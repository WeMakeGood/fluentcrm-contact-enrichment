<?php
/**
 * Provider client — accepts a prompt pair and dispatches to a per-provider
 * adapter based on FluentCRM's configured AI provider.
 *
 * v1.0.0 only ships the Claude adapter. OpenAI and Gemini adapters land in
 * v1.0.1. The dispatcher uses FCE_Provider_Bridge::SUPPORTED_PROVIDERS to
 * gate which providers will actually run; an unsupported provider will throw
 * inside the bridge before reaching here.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Provider_Client {

	/**
	 * @var FCE_Provider_Bridge
	 */
	private $bridge;

	public function __construct( FCE_Provider_Bridge $bridge = null ) {
		$this->bridge = $bridge ?: new FCE_Provider_Bridge();
	}

	/**
	 * Run an enrichment research request through the configured provider.
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param array  $options { max_uses?: int, max_tokens?: int }
	 * @return array { text: string, raw: array, error: ?string, search_count: int }
	 */
	public function research( $system_prompt, $user_prompt, array $options = array() ) {
		try {
			$creds = $this->bridge->get_credentials();
		} catch ( FCE_Provider_Unavailable $e ) {
			return self::error_result( $e->getMessage() );
		}

		switch ( $creds['provider'] ) {
			case 'claude':
				return ( new FCE_Claude_Adapter( $creds ) )->research( $system_prompt, $user_prompt, $options );
			default:
				// Should be unreachable — the bridge already gates on
				// SUPPORTED_PROVIDERS. Defensive.
				return self::error_result(
					sprintf(
						/* translators: %s: provider slug */
						__( 'Provider %s is not implemented in this release.', 'fluentcrm-contact-enrichment' ),
						$creds['provider']
					)
				);
		}
	}

	/**
	 * Standard error envelope, matching the success shape so callers can
	 * branch on $result['error'] only.
	 *
	 * @param string $message
	 * @return array
	 */
	public static function error_result( $message ) {
		return array(
			'text'         => '',
			'raw'          => array(),
			'error'        => $message,
			'search_count' => 0,
		);
	}
}

/**
 * Claude (Anthropic Messages API) adapter.
 *
 * Builds a /v1/messages request with the web_search tool enabled, posts it,
 * and normalizes the response into the standard envelope. Mirrors the logic
 * that used to live in FCE_Claude_Client::post(), now driven by credentials
 * from the provider bridge rather than the plugin's own option.
 */
class FCE_Claude_Adapter {

	const API_URL          = 'https://api.anthropic.com/v1/messages';
	const API_VERSION      = '2023-06-01';
	const TOOL_VERSION     = 'web_search_20250305';
	const DEFAULT_MAX_USES = 8;
	const DEFAULT_MAX_TOKS = 4096;
	const TIMEOUT_SECONDS  = 120;

	/**
	 * @var array { provider: string, api_key: string, model: string, supports_search: bool }
	 */
	private $creds;

	public function __construct( array $creds ) {
		$this->creds = $creds;
	}

	/**
	 * Run a research request.
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param array  $options { max_uses?: int, max_tokens?: int }
	 * @return array
	 */
	public function research( $system_prompt, $user_prompt, array $options = array() ) {
		$max_uses   = isset( $options['max_uses'] ) ? (int) $options['max_uses'] : self::DEFAULT_MAX_USES;
		$max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : self::DEFAULT_MAX_TOKS;

		$body = array(
			'model'      => $this->creds['model'],
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			'tools'      => array(
				array(
					'type'     => self::TOOL_VERSION,
					'name'     => 'web_search',
					'max_uses' => $max_uses,
				),
			),
		);

		return $this->post( $body );
	}

	/**
	 * POST to the Anthropic API and normalize the response.
	 *
	 * @param array $body
	 * @return array { text: string, raw: array, error: ?string, search_count: int }
	 */
	private function post( array $body ) {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'x-api-key'         => $this->creds['api_key'],
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return FCE_Provider_Client::error_result( $response->get_error_message() );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$body_s  = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body_s, true );

		if ( ! is_array( $decoded ) ) {
			return FCE_Provider_Client::error_result(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Anthropic API returned non-JSON response (HTTP %d).', 'fluentcrm-contact-enrichment' ),
					(int) $status
				)
			);
		}

		// Error envelope: {"type":"error","error":{"type":"...","message":"..."}}
		if ( 200 !== (int) $status || isset( $decoded['error'] ) ) {
			$msg = isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP %d', 'fluentcrm-contact-enrichment' ),
					(int) $status
				);
			$type = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : '';
			if ( '' !== $type ) {
				$msg = $type . ': ' . $msg;
			}
			return array(
				'text'         => '',
				'raw'          => $decoded,
				'error'        => $msg,
				'search_count' => 0,
			);
		}

		// Walk the content array, concatenating every text block. See
		// CLAUDE.md → "Citation handling" for why structured citations on
		// these blocks aren't surfaced here.
		$text         = '';
		$search_count = 0;
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				$type = isset( $block['type'] ) ? $block['type'] : '';
				if ( 'text' === $type && isset( $block['text'] ) ) {
					$text .= (string) $block['text'];
				} elseif ( 'server_tool_use' === $type ) {
					$search_count++;
				}
			}
		}

		// pause_turn = paused mid-tool-use. We don't implement the
		// continuation loop; surface it as a recoverable error.
		if ( isset( $decoded['stop_reason'] ) && 'pause_turn' === $decoded['stop_reason'] ) {
			return array(
				'text'         => '',
				'raw'          => $decoded,
				'error'        => __( 'Claude paused mid-research (pause_turn). Try lowering the search budget or retry.', 'fluentcrm-contact-enrichment' ),
				'search_count' => $search_count,
			);
		}

		return array(
			'text'         => $text,
			'raw'          => $decoded,
			'error'        => null,
			'search_count' => $search_count,
		);
	}
}
