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
		$raw = get_option( FCE_OPT_CONTEXT_MODS, array() );

		// Stored as a JSON string per the spec; tolerate either JSON or array
		// for forward-compat with manual edits via wp option update.
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $i => $module ) {
			$out[] = array(
				'title'   => isset( $module['title'] ) ? (string) $module['title'] : '',
				'content' => isset( $module['content'] ) ? (string) $module['content'] : '',
				'active'  => ! empty( $module['active'] ),
				'order'   => isset( $module['order'] ) ? (int) $module['order'] : $i,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		return $out;
	}

	/**
	 * Returns only active modules, in display order, with just title+content.
	 *
	 * @return array<int, array{title:string,content:string}>
	 */
	public static function active() {
		$active = array();
		foreach ( self::all() as $module ) {
			if ( $module['active'] && '' !== trim( $module['content'] ) ) {
				$active[] = array(
					'title'   => $module['title'],
					'content' => $module['content'],
				);
			}
		}
		return $active;
	}

	/**
	 * Persists the full module list. Caller is expected to have done capability
	 * + nonce checks already.
	 *
	 * @param array $modules
	 * @return void
	 */
	public static function save( array $modules ) {
		$normalised = array();
		$order      = 0;

		foreach ( $modules as $module ) {
			$title   = isset( $module['title'] ) ? sanitize_text_field( wp_unslash( $module['title'] ) ) : '';
			$content = isset( $module['content'] ) ? wp_kses_post( wp_unslash( $module['content'] ) ) : '';
			$active  = ! empty( $module['active'] );

			// Skip modules with neither title nor content — admin probably
			// added a row and didn't fill it in.
			if ( '' === $title && '' === trim( $content ) ) {
				continue;
			}

			$normalised[] = array(
				'title'   => $title,
				'content' => $content,
				'active'  => $active,
				'order'   => $order++,
			);
		}

		update_option( FCE_OPT_CONTEXT_MODS, wp_json_encode( $normalised ), false );
	}
}
