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
	 *   contact:       array<string, string|string[]>  values to push to linked contacts
	 *   company:       array<string, string>           custom-field values to push to the company
	 *   native_fields: array<string, string>           native FluentCRM column values (industry, description, address, social URLs, employees_number)
	 *   narrative:     array{decision_maker_context:string, recent_developments:string, alignment_assessment:string, recommended_approach:string}
	 *   dropped:       array<int, string>              field => reason for dropped values
	 * }
	 */
	public static function map( array $parsed ) {
		$contact       = array();
		$company       = array();
		$native_fields = array();
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
				$narrative[ $section ] = self::clean_narrative( $source[ $section ] );
			}
		}

		// Native FluentCRM fields. The enrichment job decides whether to
		// actually write each one based on whether the column is empty —
		// the mapper just shapes valid values and drops invalid ones.
		$native_fields = self::map_native_fields(
			isset( $parsed['native_fields'] ) && is_array( $parsed['native_fields'] )
				? $parsed['native_fields']
				: array(),
			isset( $contact['org_employees'] ) ? $contact['org_employees'] : '',
			$dropped
		);

		return array(
			'contact'       => $contact,
			'company'       => $company,
			'native_fields' => $native_fields,
			'narrative'     => $narrative,
			'dropped'       => $dropped,
		);
	}

	/**
	 * Validate and shape the native_fields object from Claude's response.
	 * Address sub-fields and social URLs pass through with sanitization;
	 * linkedin_industry validates against the FluentCRM industry list.
	 *
	 * @param array              $native        Raw native_fields object from response.
	 * @param string             $employees_bucket  e.g. "11-50" — used to derive employees_number.
	 * @param array<int, string> &$dropped      Drop log.
	 * @return array<string, string>
	 */
	private static function map_native_fields( array $native, $employees_bucket, array &$dropped ) {
		$out = array();

		// Industry — must match FluentCRM's enum exactly. Drop silently if
		// it doesn't (the field stays empty rather than getting junk).
		if ( isset( $native['linkedin_industry'] ) && is_string( $native['linkedin_industry'] ) ) {
			$candidate = trim( $native['linkedin_industry'] );
			if ( '' !== $candidate ) {
				$allowed = self::company_industries();
				if ( in_array( $candidate, $allowed, true ) ) {
					$out['industry'] = $candidate;
				} else {
					$dropped[] = 'linkedin_industry: "' . $candidate . '" not in FluentCRM industry list';
				}
			}
		}

		// Description — short paragraph; we strip <cite> tags defensively
		// in case the model put any in here too.
		if ( isset( $native['description'] ) && is_string( $native['description'] ) ) {
			$desc = self::clean_narrative( $native['description'] );
			if ( '' !== $desc ) {
				$out['description'] = $desc;
			}
		}

		// Address — pass each sub-field through individually so partial
		// addresses are valid (e.g. city + country with no street).
		$addr = isset( $native['headquarters'] ) && is_array( $native['headquarters'] )
			? $native['headquarters']
			: array();
		$address_keys = array( 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' );
		foreach ( $address_keys as $key ) {
			if ( isset( $addr[ $key ] ) && is_string( $addr[ $key ] ) ) {
				$value = trim( $addr[ $key ] );
				if ( '' !== $value ) {
					$out[ $key ] = $value;
				}
			}
		}

		// Website + social URLs — basic URL validation; drop if not parseable.
		foreach ( array( 'website', 'linkedin_url', 'facebook_url', 'twitter_url' ) as $key ) {
			if ( isset( $native[ $key ] ) && is_string( $native[ $key ] ) ) {
				$url = trim( $native[ $key ] );
				if ( '' === $url ) {
					continue;
				}
				if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$out[ $key ] = $url;
				} else {
					$dropped[] = $key . ': "' . $url . '" is not a valid URL';
				}
			}
		}

		// Derive employees_number from the org_employees bucket. We only
		// have a bucket from Claude; FluentCRM's column is an int. The
		// midpoint of the bucket is a reasonable approximation that's
		// stable across re-enrichments.
		$mid = self::employees_bucket_midpoint( $employees_bucket );
		if ( $mid > 0 ) {
			$out['employees_number'] = (string) $mid;
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private static function company_industries() {
		if ( ! class_exists( '\\FluentCrm\\App\\Services\\Helper' ) ) {
			return array();
		}
		$list = \FluentCrm\App\Services\Helper::companyCategories();
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Map the org_employees bucket string to an integer midpoint. Returns
	 * 0 (i.e. "do not write") for "Unknown" or unrecognised buckets.
	 *
	 * @param string $bucket
	 * @return int
	 */
	private static function employees_bucket_midpoint( $bucket ) {
		switch ( $bucket ) {
			case '1–10':
			case '1-10':
				return 5;
			case '11–50':
			case '11-50':
				return 30;
			case '51–200':
			case '51-200':
				return 125;
			case '201–1000':
			case '201-1000':
				return 600;
			case '1001–5000':
			case '1001-5000':
				return 3000;
			case '5000+':
				return 7500;
			default:
				return 0;
		}
	}

	/**
	 * Defensive cleanup for narrative strings. Claude's structured citations
	 * attach to top-level text blocks and don't reach inside JSON string
	 * values, but the model still sometimes emits `<cite index='X-Y'>...</cite>`
	 * tags inline inside narrative prose. We strip them so they don't end up
	 * HTML-encoded in the rendered note. Keep the cited text content in
	 * place, just drop the wrapping tags.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function clean_narrative( $text ) {
		$text = (string) $text;
		// Drop opening and closing <cite ...> tags.
		$text = preg_replace( '#</?cite\b[^>]*>#i', '', $text );
		return trim( $text );
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
