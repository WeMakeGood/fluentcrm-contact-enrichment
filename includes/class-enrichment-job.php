<?php
/**
 * Enrichment cron job. Single entry point: `fce_run_enrichment_job` cron hook
 * receives a company ID, runs the full pipeline, writes results to FluentCRM.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Enrichment_Job {

	public static function register_hooks() {
		add_action( FCE_CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	/**
	 * Cron handler.
	 *
	 * @param int $company_id
	 * @return void
	 */
	public static function run( $company_id ) {
		// Implemented in Step 5.
	}
}
