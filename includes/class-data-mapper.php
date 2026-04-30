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
	 * Single-select fields and their fallback when the response is missing or
	 * not in the allowed list.
	 */
	const SINGLE_SELECT_FALLBACKS = array(
		'org_type'            => 'Other',
		'org_sector'          => 'Other',
		'org_employees'       => 'Unknown',
		'org_revenue'         => 'Unknown',
		'org_alignment_score' => 'Unknown',
	);

	const MULTI_SELECT_FIELDS = array(
		'org_geo_scope',
		'org_focus_areas',
		'org_partnership_models',
	);

	/**
	 * Extract a JSON object from Claude's text response. Three strategies in
	 * order: explicit <json>...</json> wrapper, fenced code block, balanced-
	 * brace scan. Returns null if no parseable JSON is found.
	 *
	 * @param string $text
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}

		// 1. <json>...</json> wrapper (we ask for this in the system prompt).
		if ( preg_match( '#<json>\s*(\{.*?\})\s*</json>#s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// 2. Fenced code block: ```json {...} ``` or ``` {...} ```
		if ( preg_match( '#```(?:json)?\s*(\{.*?\})\s*```#s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// 3. Balanced-brace scan — try every {...} substring in order, returning
		// the first one that parses as JSON. Handles prose that contains
		// curly braces ({something}) before the actual JSON object.
		foreach ( self::find_balanced_brace_candidates( $text ) as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Validate and normalise a parsed enrichment payload against the allowed
	 * options for each field.
	 *
	 * @param array $parsed
	 * @return array {
	 *   contact:    array<string, string|string[]> values to push to linked contacts
	 *   company:    array<string, string>          values to push to the company
	 *   narrative:  array{decision_maker_context:string, recent_developments:string, alignment_assessment:string, recommended_approach:string}
	 *   dropped:    array<int, string>             field => reason for dropped values
	 * }
	 */
	public static function map( array $parsed ) {
		$contact   = array();
		$company   = array();
		$narrative = array(
			'decision_maker_context' => '',
			'recent_developments'    => '',
			'alignment_assessment'   => '',
			'recommended_approach'   => '',
		);
		$dropped   = array();

		$contact_fields = self::index_by_slug( FCE_Field_Registrar::contact_field_definitions() );

		foreach ( self::SINGLE_SELECT_FALLBACKS as $slug => $fallback ) {
			$value           = isset( $parsed[ $slug ] ) ? (string) $parsed[ $slug ] : '';
			$allowed         = self::options_for( $contact_fields, $slug );
			$contact[ $slug ] = self::pick_single( $value, $allowed, $fallback, $slug, $dropped );
		}

		foreach ( self::MULTI_SELECT_FIELDS as $slug ) {
			$values          = isset( $parsed[ $slug ] ) ? (array) $parsed[ $slug ] : array();
			$allowed         = self::options_for( $contact_fields, $slug );
			$contact[ $slug ] = self::pick_multi( $values, $allowed, $slug, $dropped );
		}

		// Company-side: confidence is the only structured value; status and
		// date are written by the job itself.
		$confidence_allowed = array( 'High', 'Medium', 'Low' );
		$confidence         = isset( $parsed['confidence'] ) ? (string) $parsed['confidence'] : '';
		$company['enrichment_confidence'] = self::pick_single(
			$confidence,
			$confidence_allowed,
			'Low',
			'confidence',
			$dropped
		);

		// Narrative: tolerate either nested under "narrative" or top-level
		// keys. Each section is plain text/markdown — we trim and pass through.
		$source = isset( $parsed['narrative'] ) && is_array( $parsed['narrative'] )
			? $parsed['narrative']
			: $parsed;

		foreach ( array_keys( $narrative ) as $section ) {
			if ( isset( $source[ $section ] ) && is_string( $source[ $section ] ) ) {
				$narrative[ $section ] = trim( $source[ $section ] );
			}
		}

		return array(
			'contact'   => $contact,
			'company'   => $company,
			'narrative' => $narrative,
			'dropped'   => $dropped,
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @param string                $value
	 * @param array<int, string>    $allowed
	 * @param string                $fallback
	 * @param string                $slug      for error reporting
	 * @param array<int, string>    &$dropped
	 * @return string
	 */
	private static function pick_single( $value, array $allowed, $fallback, $slug, array &$dropped ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			$dropped[] = $slug . ': empty (used fallback "' . $fallback . '")';
			return $fallback;
		}
		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}
		$dropped[] = $slug . ': "' . $value . '" not in allowed options (used fallback "' . $fallback . '")';
		return $fallback;
	}

	/**
	 * Intersect supplied values with the allowed list; record any that were
	 * filtered out. Returns a comma-joined string (FluentCRM's internal
	 * default for select-multi values).
	 *
	 * @param array<int, mixed>     $values
	 * @param array<int, string>    $allowed
	 * @param string                $slug
	 * @param array<int, string>    &$dropped
	 * @return string
	 */
	private static function pick_multi( array $values, array $allowed, $slug, array &$dropped ) {
		$kept = array();
		foreach ( $values as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( in_array( $candidate, $allowed, true ) ) {
				if ( ! in_array( $candidate, $kept, true ) ) {
					$kept[] = $candidate;
				}
			} else {
				$dropped[] = $slug . ': "' . $candidate . '" not in allowed options';
			}
		}
		return implode( ', ', $kept );
	}

	/**
	 * @param array<int, array<string, mixed>> $defs
	 * @return array<string, array<string, mixed>>  slug => def
	 */
	private static function index_by_slug( array $defs ) {
		$out = array();
		foreach ( $defs as $def ) {
			if ( ! empty( $def['slug'] ) ) {
				$out[ $def['slug'] ] = $def;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $indexed
	 * @param string                              $slug
	 * @return array<int, string>
	 */
	private static function options_for( array $indexed, $slug ) {
		if ( ! isset( $indexed[ $slug ]['options'] ) || ! is_array( $indexed[ $slug ]['options'] ) ) {
			return array();
		}
		return array_values( $indexed[ $slug ]['options'] );
	}

	/**
	 * Yield every balanced { ... } substring in the text, in order of
	 * appearance. Tracks string state so braces inside JSON string values
	 * don't break the bracket matching.
	 *
	 * @param string $text
	 * @return \Generator<int, string>
	 */
	private static function find_balanced_brace_candidates( $text ) {
		$len    = strlen( $text );
		$start  = 0;
		$depth  = 0;
		$open   = -1;
		$in_str = false;
		$escape = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];

			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $c && $in_str ) {
				$escape = true;
				continue;
			}
			if ( '"' === $c ) {
				$in_str = ! $in_str;
				continue;
			}
			if ( $in_str ) {
				continue;
			}

			if ( '{' === $c ) {
				if ( 0 === $depth ) {
					$open = $i;
				}
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth && $open >= 0 ) {
					yield substr( $text, $open, ( $i - $open ) + 1 );
					$open = -1;
				}
			}
		}
	}
}
