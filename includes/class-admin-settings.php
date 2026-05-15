<?php
/**
 * Admin settings page wiring. v1.0.0 onward the entire settings UI
 * is a Vue 3 + Element Plus app (source under `src/admin/`, built to
 * `dist/admin/`); this class just registers the WP admin page, links
 * it into FluentCRM's menus, and hands off to the Vue mount point.
 *
 * The class also keeps a small legacy decryption surface so the
 * migration notice (FCE_Migration_Notice) can surface a pre-1.0.0
 * encrypted API key to admins before it's deleted. Once enough time
 * has passed in the field, that block can be removed too.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Admin_Settings {

	public static function register_hooks() {
		// Register our standalone admin page under FluentCRM's WP admin
		// submenu (parent: 'fluentcrm-admin'). Runs at admin_menu
		// priority 11 so FluentCRM's parent menu (registered at
		// priority 10) is in place when we attach.
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 11 );

		// Inject an entry into FluentCRM's SPA sidebar so admins inside
		// the Vue admin can navigate to our page without leaving to the
		// WP admin sidebar. Clicking it triggers a full page load out of
		// the SPA into our page (the SPA can't host us — see CLAUDE.md
		// for the architectural rationale).
		add_filter( 'fluent_crm/core_menu_items', array( __CLASS__, 'register_spa_sidebar_item' ), 10, 3 );
	}

	/**
	 * Register the WP admin submenu item.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'fluentcrm-admin',
			__( 'FluentCRM Contact Enrichment', 'fluentcrm-contact-enrichment' ),
			__( 'Contact Enrichment', 'fluentcrm-contact-enrichment' ),
			FCE_CAPABILITY,
			FCE_MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add an entry to FluentCRM's Vue sidebar so admins inside the SPA
	 * can navigate to our standalone page. FluentCRM's filter expects
	 * items in the SPA's shape (key / label / permalink / icon); the
	 * permalink here is a full URL that leaves the SPA.
	 *
	 * @param array       $menu_items
	 * @param array       $permissions  FluentCRM permissions for current user
	 * @param string|null $url_base     SPA base URL (unused; we link to WP admin)
	 * @return array
	 */
	public static function register_spa_sidebar_item( $menu_items, $permissions, $url_base = null ) {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			return $menu_items;
		}

		$menu_items[] = array(
			'key'       => 'fce_contact_enrichment',
			'label'     => __( 'Contact Enrichment', 'fluentcrm-contact-enrichment' ),
			'permalink' => admin_url( 'admin.php?page=' . FCE_MENU_SLUG ),
		);

		return $menu_items;
	}

	/**
	 * Render the admin page. Enqueues the Vue bundle, localizes the
	 * config payload as `window.FCEAdmin`, and emits the mount point.
	 * If the bundle is missing — typically a dev install that hasn't
	 * run `npm run build` — render a clear error instead of a blank
	 * page so the cause is obvious.
	 */
	public static function render_page() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fluentcrm-contact-enrichment' ) );
		}

		$dist_dir  = FCE_PLUGIN_DIR . 'dist/admin/';
		$dist_url  = FCE_PLUGIN_URL . 'dist/admin/';
		$has_build = file_exists( $dist_dir . 'admin.js' );

		if ( ! $has_build ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'FluentCRM Contact Enrichment', 'fluentcrm-contact-enrichment' ); ?></h1>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: 1: relative path to the missing built asset, 2: build command */
							esc_html__( 'The Vue admin bundle is missing at %1$s. Run %2$s in the plugin directory to build it.', 'fluentcrm-contact-enrichment' ),
							'<code>dist/admin/admin.js</code>',
							'<code>npm install &amp;&amp; npm run build</code>'
						);
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		// Version the asset URL with file mtime so admins picking up a
		// new build don't get a cached old bundle.
		$js_version  = (string) filemtime( $dist_dir . 'admin.js' );
		$css_path    = $dist_dir . 'admin.css';
		$has_css     = file_exists( $css_path );
		$css_version = $has_css ? (string) filemtime( $css_path ) : $js_version;

		wp_enqueue_script(
			'fce-admin-app',
			$dist_url . 'admin.js',
			array(),
			$js_version,
			true
		);

		if ( $has_css ) {
			wp_enqueue_style(
				'fce-admin-app',
				$dist_url . 'admin.css',
				array(),
				$css_version
			);
		}

		// Module scripts must declare type="module" — Vite's bundle
		// uses import/export at the top level. WordPress's enqueue API
		// doesn't have a type-module switch, so we hook script_loader_tag.
		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle ) {
				if ( 'fce-admin-app' === $handle ) {
					return str_replace( ' src=', ' type="module" src=', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		$health = class_exists( 'FCE_Provider_Bridge' )
			? ( new FCE_Provider_Bridge() )->inspect()
			: array( 'status' => 'unavailable', 'provider' => '', 'model' => '', 'message' => '' );

		wp_localize_script(
			'fce-admin-app',
			'FCEAdmin',
			array(
				'version'       => defined( 'FCE_VERSION' ) ? FCE_VERSION : '',
				'restRoot'      => esc_url_raw( rest_url( 'fce/v1/' ) ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'menuSlug'      => FCE_MENU_SLUG,
				'companyOn'     => FCE_FluentCRM_Compat::is_company_module_enabled(),
				'health'        => $health,
				'aiSettingsUrl' => admin_url( 'admin.php?page=fluentcrm-admin#/settings/ai_settings' ),
			)
		);

		// Deliberately not using `<div class="wrap">` — WordPress admin's
		// global form CSS (input borders, table-style form rows, button
		// styling, notice positioning) targets elements inside .wrap and
		// double-styles our Element Plus components. The Vue app owns
		// its own layout, including the page-edge margins .wrap would
		// otherwise provide.
		?>
		<div class="fce-vue-wrap">
			<div id="fce-admin"></div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Legacy API-key decryption (migration path only)
	//
	// Pre-v1.0.0 the plugin stored an AES-256-CBC encrypted Anthropic key
	// in FCE_OPT_API_KEY. v1.0.0 reads from FluentCRM's AI configuration
	// instead, but FCE_Migration_Notice still needs to read the old value
	// once so the admin can paste it into FluentCRM's settings before the
	// option is auto-deleted. After enough time in the field these three
	// methods + the FCE_OPT_API_KEY constant can also be removed.
	// ---------------------------------------------------------------------

	/**
	 * Returns the decrypted legacy API key (only used by the migration
	 * notice; new code uses FCE_Provider_Bridge).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$stored = get_option( FCE_OPT_API_KEY, '' );
		if ( '' === $stored ) {
			return '';
		}
		return self::decrypt( $stored );
	}

	/**
	 * @param string $stored
	 * @return string plaintext, or empty string on failure
	 */
	private static function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'fce1:' ) ) {
			return '';
		}
		$payload = base64_decode( substr( $stored, 5 ), true );
		if ( false === $payload || strlen( $payload ) <= 16 ) {
			return '';
		}
		$iv  = substr( $payload, 0, 16 );
		$ct  = substr( $payload, 16 );
		$key = self::derive_key();
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $pt ) ? '' : $pt;
	}

	/**
	 * Derive a 256-bit symmetric key from WordPress's auth salts. Stable
	 * across requests as long as wp-config.php doesn't change.
	 *
	 * @return string 32-byte raw key
	 */
	private static function derive_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' )
			. 'fluentcrm-contact-enrichment';
		return hash( 'sha256', $material, true );
	}
}
