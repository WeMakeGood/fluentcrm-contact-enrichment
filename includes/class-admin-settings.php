<?php
/**
 * Admin settings page (Settings → Contact Enrichment). Three tabs:
 *  - API Settings (key, model, test connection)
 *  - Context Modules (admin-edited Markdown injected into every research prompt)
 *  - Focus Areas (multi-select option list for the org_focus_areas field)
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Admin_Settings {

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_fce_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu_page() {
		// Implemented in Step 3.
	}

	public static function render_page() {
		// Implemented in Step 3.
	}

	public static function handle_save() {
		// Implemented in Step 3.
	}
}
