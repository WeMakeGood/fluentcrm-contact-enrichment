<?php
/**
 * Field registrar — auto-creates the company and contact custom fields the
 * plugin depends on, idempotently, on activation and (defensively) when
 * FluentCRM finishes initialising.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Field_Registrar {

	public static function register_hooks() {
		// Defensive re-run if FluentCRM activates after we did, or if our
		// activation fired before FluentCRM was loaded.
		add_action( 'fluent_crm/after_init_app', array( __CLASS__, 'ensure_fields' ) );
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function on_activate() {
		// Implemented in Step 2.
	}

	/**
	 * Idempotent ensure-fields-exist pass.
	 *
	 * @return void
	 */
	public static function ensure_fields() {
		// Implemented in Step 2.
	}
}
