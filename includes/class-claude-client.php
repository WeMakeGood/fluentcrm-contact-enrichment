<?php
/**
 * Anthropic Messages API client. Single non-streaming POST per enrichment.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Claude_Client {

	const API_URL          = 'https://api.anthropic.com/v1/messages';
	const API_VERSION      = '2023-06-01';
	const TOOL_VERSION     = 'web_search_20250305';
	const DEFAULT_MODEL    = 'claude-sonnet-4-6';
	const DEFAULT_MAX_USES = 8;
	const DEFAULT_MAX_TOKS = 4096;

	/**
	 * Send a research request and return the parsed assistant text.
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param array  $options { 'model'?: string, 'max_uses'?: int, 'max_tokens'?: int }
	 * @return array { 'text': string, 'raw': array, 'error': null|string }
	 */
	public static function research( $system_prompt, $user_prompt, array $options = array() ) {
		// Implemented in Step 4.
		return array( 'text' => '', 'raw' => array(), 'error' => 'not_implemented' );
	}

	/**
	 * Verify the API key works AND that web search is enabled at the org level.
	 * Returns null on success, or an error string.
	 *
	 * @param string $api_key
	 * @return string|null
	 */
	public static function test_connection( $api_key ) {
		// Implemented in Step 4.
		return 'not_implemented';
	}
}
