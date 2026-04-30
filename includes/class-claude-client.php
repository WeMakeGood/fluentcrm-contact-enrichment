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
	const TIMEOUT_SECONDS  = 120;

	/**
	 * Send a research request and return the parsed assistant text.
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param array  $options { model?: string, max_uses?: int, max_tokens?: int }
	 * @return array { text: string, raw: array, error: ?string, search_count: int }
	 */
	public static function research( $system_prompt, $user_prompt, array $options = array() ) {
		$api_key = FCE_Admin_Settings::get_api_key();
		if ( '' === $api_key ) {
			return self::error_result( 'No API key configured. Add one in Settings → Contact Enrichment.' );
		}

		$model      = isset( $options['model'] ) ? $options['model'] : (string) get_option( FCE_OPT_MODEL, self::DEFAULT_MODEL );
		$max_uses   = isset( $options['max_uses'] ) ? (int) $options['max_uses'] : (int) get_option( FCE_OPT_MAX_SEARCHES, self::DEFAULT_MAX_USES );
		$max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : self::DEFAULT_MAX_TOKS;

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			'tools'      => array(
				array(
					'type'     => self::TOOL_VERSION,
					'name'     => 'web_search',
					'max_uses' => $max_uses,
				),
			),
		);

		return self::post( $api_key, $body );
	}

	/**
	 * Verify the API key works AND that web search is enabled at the org level.
	 * Returns null on success, or a human-readable error string.
	 *
	 * Sends a tiny request that includes the web_search tool but instructs
	 * the model not to invoke it; this verifies the org-level web-search
	 * permission without paying for an actual search.
	 *
	 * @param string $api_key
	 * @return string|null
	 */
	public static function test_connection( $api_key ) {
		if ( '' === trim( (string) $api_key ) ) {
			return __( 'No API key set.', 'fluentcrm-contact-enrichment' );
		}

		$body = array(
			'model'      => self::DEFAULT_MODEL,
			'max_tokens' => 32,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'Reply with only the single word OK. Do not use any tools.',
				),
			),
			'tools'      => array(
				array(
					'type'     => self::TOOL_VERSION,
					'name'     => 'web_search',
					'max_uses' => 1,
				),
			),
		);

		$result = self::post( $api_key, $body );
		if ( null !== $result['error'] ) {
			return $result['error'];
		}

		$text = trim( $result['text'] );
		if ( '' === $text ) {
			return __( 'API responded but returned no text. Web search may not be enabled at the org level.', 'fluentcrm-contact-enrichment' );
		}

		return null;
	}

	/**
	 * Internal: send the request and normalise the response.
	 *
	 * @param string $api_key
	 * @param array  $body
	 * @return array { text: string, raw: array, error: ?string, search_count: int }
	 */
	private static function post( $api_key, array $body ) {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error_result( $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body_s = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body_s, true );

		if ( ! is_array( $decoded ) ) {
			return self::error_result(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Anthropic API returned non-JSON response (HTTP %d).', 'fluentcrm-contact-enrichment' ),
					(int) $status
				)
			);
		}

		// Error envelope: {"type":"error","error":{"type":"...","message":"..."}}
		if ( 200 !== (int) $status || isset( $decoded['error'] ) ) {
			$msg = isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP %d', 'fluentcrm-contact-enrichment' ),
					(int) $status
				);
			$type = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : '';
			if ( '' !== $type ) {
				$msg = $type . ': ' . $msg;
			}
			return self::error_result( $msg, $decoded );
		}

		// Success: walk the content array, concatenating every text block.
		$text         = '';
		$search_count = 0;
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				$type = isset( $block['type'] ) ? $block['type'] : '';
				if ( 'text' === $type && isset( $block['text'] ) ) {
					$text .= $block['text'];
				} elseif ( 'server_tool_use' === $type ) {
					$search_count++;
				}
			}
		}

		// `pause_turn` means the model paused mid-tool-use. We don't
		// implement the continuation loop in this version; surface it as a
		// recoverable error so the admin sees the cause.
		if ( isset( $decoded['stop_reason'] ) && 'pause_turn' === $decoded['stop_reason'] ) {
			return self::error_result(
				__( 'Claude paused mid-research (pause_turn). Try lowering the search budget or retry.', 'fluentcrm-contact-enrichment' ),
				$decoded
			);
		}

		return array(
			'text'         => $text,
			'raw'          => $decoded,
			'error'        => null,
			'search_count' => $search_count,
		);
	}

	/**
	 * @param string $message
	 * @param array  $raw
	 * @return array
	 */
	private static function error_result( $message, array $raw = array() ) {
		return array(
			'text'         => '',
			'raw'          => $raw,
			'error'        => $message,
			'search_count' => 0,
		);
	}
}
