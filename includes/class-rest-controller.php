<?php
/**
 * REST API surface for the Vue admin app.
 *
 * Routes are registered under the `fce/v1` namespace. Each tab in the
 * settings UI maps to one resource path with GET + POST handlers; the
 * Vue app loads on mount and saves on demand.
 *
 * Auth model: every route requires the same WordPress capability the
 * legacy admin page used (FCE_CAPABILITY). The Vue client sends the
 * standard `X-WP-Nonce` header (set up via wp_localize_script in
 * FCE_Admin_Settings::render_vue_app); permission_callback verifies
 * the capability + the nonce together.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_REST_Controller {

	const NAMESPACE_ROOT = 'fce/v1';

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/focus-areas',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_focus_areas' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_focus_areas' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'options' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/context-modules/(?P<surface>contact|company)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_context_modules' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'surface' => array(
							'type'     => 'string',
							'enum'     => array( 'contact', 'company' ),
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_context_modules' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'surface' => array(
							'type'     => 'string',
							'enum'     => array( 'contact', 'company' ),
							'required' => true,
						),
						'modules' => array(
							'type'     => 'array',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/lookup-fields/(?P<surface>contact|company)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_lookup_fields' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_lookup_fields' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'slugs' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/meta-prompt/(?P<surface>contact|company)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_meta_prompt' ),
				'permission_callback' => array( __CLASS__, 'check_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/bulk-resync',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'run_bulk_resync' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'confirmation' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/capacity-tiers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_capacity_tiers' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_capacity_tiers' ),
					'permission_callback' => array( __CLASS__, 'check_admin' ),
					'args'                => array(
						'options' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Capability gate for every fce/v1 route. The nonce check happens
	 * upstream in the REST API itself (WP validates X-WP-Nonce before
	 * dispatching when the user is authenticated).
	 *
	 * @return true|\WP_Error
	 */
	public static function check_admin() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			return new \WP_Error(
				'fce_forbidden',
				__( 'You do not have permission to manage Contact Enrichment settings.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// ---------------------------------------------------------------
	// Focus Areas
	// ---------------------------------------------------------------

	/**
	 * @return \WP_REST_Response
	 */
	public static function get_focus_areas() {
		$options = FCE_Field_Registrar::focus_area_options();
		return rest_ensure_response(
			array(
				'options' => array_values( $options ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_focus_areas( $request ) {
		$posted = $request->get_param( 'options' );
		if ( ! is_array( $posted ) ) {
			return new \WP_Error(
				'fce_invalid_input',
				__( 'options must be an array.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 400 )
			);
		}

		$cleaned = self::dedupe_and_clean( $posted );
		update_option( FCE_OPT_FOCUS_AREAS, $cleaned, false );

		// Push the option list into FluentCRM's field definition so the
		// dropdown in their admin reflects the change immediately.
		FCE_Field_Registrar::sync_focus_area_options();

		return rest_ensure_response(
			array(
				'options' => $cleaned,
				'message' => __( 'Focus areas saved.', 'fluentcrm-contact-enrichment' ),
			)
		);
	}

	// ---------------------------------------------------------------
	// Capacity Tiers
	// ---------------------------------------------------------------

	/**
	 * @return \WP_REST_Response
	 */
	public static function get_capacity_tiers() {
		return rest_ensure_response(
			array(
				'options'  => array_values( FCE_Field_Registrar::capacity_tier_options() ),
				'defaults' => array_values( FCE_Field_Registrar::default_capacity_tiers() ),
			)
		);
	}

	/**
	 * Save the capacity-tier vocabulary. If the cleaned list is empty,
	 * we fall back to the per-provider defaults — enrichment requires
	 * something for Claude to pick from, and an empty list would break
	 * the structured `individual_capacity_tier` output. The legacy
	 * save handler had the same rule; preserve it here so admin
	 * behavior doesn't regress through the REST path.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_capacity_tiers( $request ) {
		$posted = $request->get_param( 'options' );
		if ( ! is_array( $posted ) ) {
			return new \WP_Error(
				'fce_invalid_input',
				__( 'options must be an array.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 400 )
			);
		}

		$cleaned = self::dedupe_and_clean( $posted );
		$used_defaults = false;
		if ( empty( $cleaned ) ) {
			$cleaned = FCE_Field_Registrar::default_capacity_tiers();
			$used_defaults = true;
		}

		update_option( FCE_OPT_CAPACITY_TIERS, $cleaned, false );
		FCE_Field_Registrar::sync_capacity_tier_options();

		$message = $used_defaults
			? __( 'Capacity tiers saved. The list was empty, so the defaults were restored.', 'fluentcrm-contact-enrichment' )
			: __( 'Capacity tiers saved.', 'fluentcrm-contact-enrichment' );

		return rest_ensure_response(
			array(
				'options'       => $cleaned,
				'used_defaults' => $used_defaults,
				'message'       => $message,
			)
		);
	}

	// ---------------------------------------------------------------
	// Context Modules
	// ---------------------------------------------------------------

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function get_context_modules( $request ) {
		$surface = (string) $request->get_param( 'surface' );
		$class   = self::module_class_for( $surface );
		return rest_ensure_response(
			array(
				'modules' => $class::all(),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_context_modules( $request ) {
		$surface = (string) $request->get_param( 'surface' );
		$modules = $request->get_param( 'modules' );

		if ( ! is_array( $modules ) ) {
			return new \WP_Error(
				'fce_invalid_input',
				__( 'modules must be an array.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 400 )
			);
		}

		$class = self::module_class_for( $surface );
		$class::save( $modules );

		return rest_ensure_response(
			array(
				'modules' => $class::all(),
				'message' => __( 'Context modules saved.', 'fluentcrm-contact-enrichment' ),
			)
		);
	}

	/**
	 * Resolve the storage class for a given surface. Centralizes the
	 * mapping so handlers stay symmetrical.
	 *
	 * @param string $surface 'contact' | 'company'
	 * @return string fully-qualified class name
	 */
	private static function module_class_for( $surface ) {
		return 'contact' === $surface
			? 'FCE_Contact_Context_Modules'
			: 'FCE_Context_Modules';
	}

	// ---------------------------------------------------------------
	// Lookup Fields
	// ---------------------------------------------------------------

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function get_lookup_fields( $request ) {
		$surface = (string) $request->get_param( 'surface' );

		if ( 'company' === $surface ) {
			$available = FCE_Lookup_Fields::available_company_fields();
			$selected  = FCE_Lookup_Fields::selected_company_slugs();
		} else {
			$available = FCE_Lookup_Fields::available_contact_fields();
			$selected  = FCE_Lookup_Fields::selected_contact_slugs();
		}

		return rest_ensure_response(
			array(
				'available' => array_values( $available ),
				'selected'  => array_values( $selected ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_lookup_fields( $request ) {
		$surface = (string) $request->get_param( 'surface' );
		$posted  = $request->get_param( 'slugs' );

		if ( ! is_array( $posted ) ) {
			return new \WP_Error(
				'fce_invalid_input',
				__( 'slugs must be an array.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 400 )
			);
		}

		// Validate against the eligible-fields list so submitted slugs
		// that aren't actually pickable (plugin-managed slugs, slugs that
		// no longer exist) are dropped silently.
		$available_slugs = array_column(
			'company' === $surface
				? FCE_Lookup_Fields::available_company_fields()
				: FCE_Lookup_Fields::available_contact_fields(),
			'slug'
		);

		$cleaned = array_values(
			array_intersect(
				array_map(
					static function ( $v ) {
						return sanitize_text_field( wp_unslash( (string) $v ) );
					},
					$posted
				),
				$available_slugs
			)
		);

		$option_key = 'company' === $surface ? FCE_OPT_COMPANY_LOOKUP : FCE_OPT_CONTACT_LOOKUP;
		update_option( $option_key, $cleaned, false );

		return rest_ensure_response(
			array(
				'selected' => $cleaned,
				'message'  => __( 'Lookup fields saved.', 'fluentcrm-contact-enrichment' ),
			)
		);
	}

	// ---------------------------------------------------------------
	// Meta-prompt
	// ---------------------------------------------------------------

	/**
	 * Return the meta-prompt Markdown for a given surface. Loaded from
	 * docs/prompts/{surface}-context-meta-prompt.md with a path-traversal
	 * guard. Missing file → 404 with a structured error so the UI hides
	 * the widget rather than rendering a broken card.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_meta_prompt( $request ) {
		$surface = (string) $request->get_param( 'surface' );

		$file = $surface . '-context-meta-prompt.md';
		$path = realpath( FCE_PLUGIN_DIR . 'docs/prompts/' . $file );
		$docs_dir = realpath( FCE_PLUGIN_DIR . 'docs/prompts' );

		if ( ! $path || ! $docs_dir || 0 !== strpos( $path, $docs_dir ) || ! is_readable( $path ) ) {
			return new \WP_Error(
				'fce_meta_prompt_missing',
				__( 'Meta-prompt file not available.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 404 )
			);
		}

		$prompt = file_get_contents( $path );
		if ( false === $prompt ) {
			return new \WP_Error(
				'fce_meta_prompt_unreadable',
				__( 'Could not read meta-prompt file.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'prompt' => $prompt,
			)
		);
	}

	// ---------------------------------------------------------------
	// Bulk Resync
	// ---------------------------------------------------------------

	/**
	 * Resync every company's cached org_* values down to its linked
	 * contacts. Synchronous on purpose — the admin clicked a button and
	 * is waiting on the result count. We raise the time + memory budget
	 * the same way the legacy admin-post handler did.
	 *
	 * Requires:
	 *  - Company module enabled (the sync source is companies)
	 *  - Posted confirmation === "RESYNC" (typed-confirm gate)
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run_bulk_resync( $request ) {
		if ( ! FCE_FluentCRM_Compat::is_company_module_enabled() ) {
			return new \WP_Error(
				'fce_module_disabled',
				__( 'Bulk resync is unavailable: FluentCRM Company module is disabled.', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 409 )
			);
		}

		$confirmation = trim( (string) $request->get_param( 'confirmation' ) );
		if ( 'RESYNC' !== $confirmation ) {
			return new \WP_Error(
				'fce_invalid_confirmation',
				__( 'Confirmation must be exactly RESYNC (uppercase, no extra spaces).', 'fluentcrm-contact-enrichment' ),
				array( 'status' => 400 )
			);
		}

		// Match the legacy handler's resource limits — installs with
		// thousands of companies can otherwise hit PHP timeouts.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		$result = FCE_Contact_Sync::bulk_resync();

		return rest_ensure_response(
			array_merge(
				$result,
				array(
					'message' => sprintf(
						/* translators: 1: companies processed, 2: contacts updated, 3: companies skipped */
						__( 'Resync complete. %1$d companies processed, %2$d contacts updated, %3$d companies skipped (no cached enrichment values).', 'fluentcrm-contact-enrichment' ),
						(int) $result['companies_processed'],
						(int) $result['contacts_updated'],
						(int) $result['companies_skipped']
					),
				)
			)
		);
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Apply standard sanitization to a list of admin-supplied strings.
	 * Trims, drops empties, deduplicates (case-sensitive — admins may
	 * legitimately want both "Education" and "education" if the model
	 * expects distinct values, though the picker should encourage one).
	 *
	 * @param array $raw
	 * @return array<int, string>
	 */
	private static function dedupe_and_clean( array $raw ) {
		$cleaned = array();
		$seen    = array();
		foreach ( $raw as $value ) {
			$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
			if ( '' === $value || isset( $seen[ $value ] ) ) {
				continue;
			}
			$seen[ $value ] = true;
			$cleaned[]      = $value;
		}
		return $cleaned;
	}
}
