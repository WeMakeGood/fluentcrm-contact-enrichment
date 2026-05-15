<?php
/**
 * Thin static wrapper preserved for backward compatibility with existing
 * call sites in FCE_Enrichment_Job.
 *
 * Pre-v1.0.0 this class owned the Anthropic API HTTP plumbing AND the
 * decryption of the plugin-managed API key. In v1.0.0 both responsibilities
 * moved: credentials come from FCE_Provider_Bridge (which reads
 * FluentCRM 3.0's `_fluent_ai_creds`), and HTTP plumbing lives in
 * FCE_Claude_Adapter under the dispatcher in FCE_Provider_Client. This class
 * now exists so the v1.0.0 cut-over PR stays focused — a follow-up will
 * rename the entry point at the call sites and delete this file.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Claude_Client {

	/**
	 * Kept as constants so admin code that referenced them keeps working
	 * during the cut-over. Step 5 of rework 1 removes the references.
	 */
	const DEFAULT_MODEL    = 'claude-sonnet-4-6';
	const DEFAULT_MAX_USES = 8;
	const DEFAULT_MAX_TOKS = 4096;

	/**
	 * Run an enrichment request. Delegates to FCE_Provider_Client, which
	 * resolves the configured provider (Claude in v1.0.0) via the bridge.
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param array  $options { max_uses?: int, max_tokens?: int }
	 * @return array { text: string, raw: array, error: ?string, search_count: int }
	 */
	public static function research( $system_prompt, $user_prompt, array $options = array() ) {
		return ( new FCE_Provider_Client() )->research( $system_prompt, $user_prompt, $options );
	}
}
