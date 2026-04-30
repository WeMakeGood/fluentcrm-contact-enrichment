<?php
/**
 * Maps Claude's JSON response to FluentCRM field values. Validates each value
 * against its allowed-options list; drops unknowns instead of corrupting the
 * field with foreign values.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Data_Mapper {

	/**
	 * Extract a JSON object from Claude's text response. Returns null if no
	 * parseable JSON is found.
	 *
	 * @param string $text
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		// Implemented in Step 4.
		return null;
	}

	/**
	 * Validate and normalise a parsed enrichment payload against the allowed
	 * options for each field. Returns a tuple (contact_values, company_values,
	 * confidence, narrative).
	 *
	 * @param array $parsed
	 * @return array { contact: array, company: array, narrative: array, dropped: array }
	 */
	public static function map( array $parsed ) {
		// Implemented in Step 4.
		return array( 'contact' => array(), 'company' => array(), 'narrative' => array(), 'dropped' => array() );
	}
}
