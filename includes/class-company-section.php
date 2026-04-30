<?php
/**
 * Company profile section — adds the "Enrich" button to FluentCRM's company
 * profile via the Extender API and wires the admin-ajax trigger.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Company_Section {

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_section' ) );
		add_action( 'wp_ajax_' . FCE_AJAX_TRIGGER, array( __CLASS__, 'ajax_trigger' ) );
	}

	public static function register_section() {
		// Implemented in Step 6.
	}

	public static function render_section( $content, $company ) {
		// Implemented in Step 6.
		return $content;
	}

	public static function ajax_trigger() {
		// Implemented in Step 6.
	}
}
