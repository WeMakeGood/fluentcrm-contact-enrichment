<?php
/**
 * Provider bridge — reads FluentCRM 3.0's AI credentials and surfaces them to
 * the enrichment pipeline. Source of truth for "which provider, which key,
 * which model, is it ready."
 *
 * FluentCRM splits AI configuration across two options:
 *   - get_option('_fluent_ai_creds')                  — credentials (sensitive)
 *   - fluentcrm_get_option('_ai_writing_settings')    — preferences (is_enabled, custom_prompt)
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Provider_Unavailable extends \RuntimeException {}

class FCE_Provider_Bridge {

	const CREDENTIALS_OPTION = '_fluent_ai_creds';
	const PREFERENCES_OPTION = '_ai_writing_settings';

	/**
	 * Providers this plugin can actually run enrichment against in the
	 * current release. v1.0.0 is Claude-only; v1.0.1 adds open_ai and gemini.
	 *
	 * @var string[]
	 */
	const SUPPORTED_PROVIDERS = array( 'claude' );

	/**
	 * Return credentials usable by the enrichment client.
	 *
	 * @return array { provider: string, api_key: string, model: string, supports_search: bool }
	 * @throws FCE_Provider_Unavailable When FluentCRM is missing, AI is disabled,
	 *                                  credentials are absent, or the configured
	 *                                  provider isn't supported by this release.
	 */
	public function get_credentials() {
		if ( ! function_exists( 'fluentcrm_get_option' ) ) {
			throw new FCE_Provider_Unavailable(
				__( 'FluentCRM 3.0+ is required.', 'fluentcrm-contact-enrichment' )
			);
		}

		$credentials = get_option( self::CREDENTIALS_OPTION, array() );
		$preferences = fluentcrm_get_option( self::PREFERENCES_OPTION, array() );

		if ( ! is_array( $credentials ) ) {
			$credentials = array();
		}
		if ( ! is_array( $preferences ) ) {
			$preferences = array();
		}

		// FluentCRM gates its own AI features on the is_enabled flag. Respecting
		// it means admins who deliberately turn off AI also turn off enrichment.
		$is_enabled = ( isset( $preferences['is_enabled'] ) ? (string) $preferences['is_enabled'] : 'no' ) === 'yes';
		if ( ! $is_enabled ) {
			throw new FCE_Provider_Unavailable(
				__( 'Enable AI in FluentCRM → Settings → AI Configuration before running enrichment.', 'fluentcrm-contact-enrichment' )
			);
		}

		$provider = self::normalize_provider( isset( $credentials['provider'] ) ? (string) $credentials['provider'] : '' );
		if ( '' === $provider ) {
			throw new FCE_Provider_Unavailable(
				__( 'Configure an AI provider in FluentCRM → Settings → AI Configuration.', 'fluentcrm-contact-enrichment' )
			);
		}

		$api_key = isset( $credentials['api_key'] ) ? (string) $credentials['api_key'] : '';
		if ( '' === $api_key ) {
			throw new FCE_Provider_Unavailable(
				sprintf(
					/* translators: %s: provider slug (claude / open_ai / gemini) */
					__( 'API key for %s is not set in FluentCRM AI settings.', 'fluentcrm-contact-enrichment' ),
					$provider
				)
			);
		}

		if ( ! in_array( $provider, self::SUPPORTED_PROVIDERS, true ) ) {
			throw new FCE_Provider_Unavailable(
				sprintf(
					/* translators: %s: provider slug (open_ai / gemini) */
					__( 'This release supports Claude only. Switch FluentCRM\'s active provider to Claude, or wait for the v1.0.1 release for %s support.', 'fluentcrm-contact-enrichment' ),
					$provider
				)
			);
		}

		// FluentCRM stores 'auto' as a literal model value; resolve to the
		// per-provider default that FluentCRM itself uses.
		$model = isset( $credentials['model'] ) ? (string) $credentials['model'] : 'auto';
		if ( '' === $model || 'auto' === $model ) {
			$model = self::auto_model_for( $provider );
		}

		return array(
			'provider'        => $provider,
			'api_key'         => $api_key,
			'model'           => $model,
			'supports_search' => 'claude' === $provider,
		);
	}

	/**
	 * Boolean variant for cheap UI checks (e.g. health-check banner). Doesn't
	 * leak the credentials and doesn't throw.
	 *
	 * @return bool
	 */
	public function is_ready() {
		try {
			$this->get_credentials();
			return true;
		} catch ( FCE_Provider_Unavailable $e ) {
			return false;
		}
	}

	/**
	 * Inspect the configuration without throwing. Returns a status array used
	 * by the health-check banner to choose its rendering.
	 *
	 * Possible status values:
	 *   - 'ready'         — credentials valid and provider supported
	 *   - 'unsupported'   — credentials valid but provider isn't in SUPPORTED_PROVIDERS
	 *   - 'disabled'      — credentials exist but is_enabled !== 'yes'
	 *   - 'not_configured'— provider missing or api_key missing
	 *   - 'unavailable'   — FluentCRM 3.0+ functions not present
	 *
	 * @return array { status: string, provider: string, model: string, message: string }
	 */
	public function inspect() {
		if ( ! function_exists( 'fluentcrm_get_option' ) ) {
			return array(
				'status'   => 'unavailable',
				'provider' => '',
				'model'    => '',
				'message'  => __( 'FluentCRM 3.0+ is required.', 'fluentcrm-contact-enrichment' ),
			);
		}

		$credentials = get_option( self::CREDENTIALS_OPTION, array() );
		$preferences = fluentcrm_get_option( self::PREFERENCES_OPTION, array() );

		if ( ! is_array( $credentials ) ) {
			$credentials = array();
		}
		if ( ! is_array( $preferences ) ) {
			$preferences = array();
		}

		$provider = self::normalize_provider( isset( $credentials['provider'] ) ? (string) $credentials['provider'] : '' );
		$api_key  = isset( $credentials['api_key'] ) ? (string) $credentials['api_key'] : '';
		$model    = isset( $credentials['model'] ) ? (string) $credentials['model'] : 'auto';
		if ( '' === $model || 'auto' === $model ) {
			$model = self::auto_model_for( $provider );
		}

		if ( '' === $provider || '' === $api_key ) {
			return array(
				'status'   => 'not_configured',
				'provider' => $provider,
				'model'    => '',
				'message'  => __( 'Configure an AI provider in FluentCRM → Settings → AI Configuration.', 'fluentcrm-contact-enrichment' ),
			);
		}

		$is_enabled = ( isset( $preferences['is_enabled'] ) ? (string) $preferences['is_enabled'] : 'no' ) === 'yes';
		if ( ! $is_enabled ) {
			return array(
				'status'   => 'disabled',
				'provider' => $provider,
				'model'    => $model,
				'message'  => __( 'AI is configured but disabled in FluentCRM → Settings → AI Configuration.', 'fluentcrm-contact-enrichment' ),
			);
		}

		if ( ! in_array( $provider, self::SUPPORTED_PROVIDERS, true ) ) {
			return array(
				'status'   => 'unsupported',
				'provider' => $provider,
				'model'    => $model,
				'message'  => sprintf(
					/* translators: %s: provider slug */
					__( 'This release supports Claude only. Switch FluentCRM\'s active provider to Claude, or wait for v1.0.1 for %s support.', 'fluentcrm-contact-enrichment' ),
					$provider
				),
			);
		}

		return array(
			'status'   => 'ready',
			'provider' => $provider,
			'model'    => $model,
			'message'  => '',
		);
	}

	/**
	 * FluentCRM accepts 'openai' on input and normalizes it to 'open_ai'. We
	 * match that normalization so downstream comparisons work regardless of
	 * which form was stored.
	 *
	 * @param string $provider
	 * @return string
	 */
	public static function normalize_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		return 'openai' === $provider ? 'open_ai' : $provider;
	}

	/**
	 * Resolve the literal model string FluentCRM stores ('auto') into the
	 * concrete model FluentCRM itself uses for that provider. Mirrors
	 * AiController::$autoProviderModels.
	 *
	 * @param string $provider
	 * @return string
	 */
	public static function auto_model_for( $provider ) {
		$map = array(
			'claude'  => 'claude-sonnet-4-6',
			'open_ai' => 'gpt-5.5',
			'gemini'  => 'gemini-2.5-flash',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : '';
	}
}
