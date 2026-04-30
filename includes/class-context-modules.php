<?php
/**
 * Context module storage and retrieval. Modules are admin-edited Markdown
 * documents stored as a JSON-encoded array in a single WP option.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Context_Modules {

	/**
	 * Returns all stored modules in their original order.
	 *
	 * @return array<int, array{title:string,content:string,active:bool,order:int}>
	 */
	public static function all() {
		// Implemented in Step 3.
		return array();
	}

	/**
	 * Returns only active modules, ordered by display order.
	 *
	 * @return array<int, array{title:string,content:string}>
	 */
	public static function active() {
		// Implemented in Step 3.
		return array();
	}

	/**
	 * Persists the full module list.
	 *
	 * @param array $modules
	 * @return void
	 */
	public static function save( array $modules ) {
		// Implemented in Step 3.
	}
}
