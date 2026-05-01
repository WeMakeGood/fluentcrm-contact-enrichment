<?php
/**
 * Lookup fields — admin-selected FluentCRM custom fields whose values get
 * injected into the enrichment prompt as "existing data on file" context
 * (v0.9.0+).
 *
 * The use case is fundraising or B2B research where the requesting org
 * already has signal that's stronger than anything Claude can find via
 * web search (giving totals, order history, pledge data, prior course
 * completions, etc.). Without this injection, Claude either can't find
 * those facts at all (private records) or is forced to treat them as
 * "claimed but unverified" (because they're on a private CRM record).
 * With this injection, Claude reads the values as given facts and
 * grounds the rest of the research on top of them.
 *
 * Two surfaces, same shape:
 *   - Company enrichment uses company-side custom fields
 *   - Contact enrichment uses contact-side custom fields
 *
 * Plugin-managed enrichment fields (the org_* on contacts, the
 * individual_* on contacts, the company-side enrichment_* and the
 * mirrored org_* on companies) are intentionally excluded from the
 * picker — those are dependent variables of the enrichment job, not
 * inputs. Injecting them would create feedback loops where prior
 * enrichments anchor subsequent ones.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Lookup_Fields {

	/**
	 * Available company-side fields the admin can choose to inject.
	 * Excludes plugin-managed slugs.
	 *
	 * @return array<int, array{slug:string, label:string, type:string, group:string}>
	 */
	public static function available_company_fields() {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CustomCompanyField' ) ) {
			return array();
		}
		$model  = new \FluentCrm\App\Models\CustomCompanyField();
		$fields = $model->getGlobalFields()['fields'] ?? array();
		return self::filter_eligible_fields( is_array( $fields ) ? $fields : array(), self::company_excluded_slugs() );
	}

	/**
	 * Available contact-side fields the admin can choose to inject.
	 * Excludes plugin-managed slugs.
	 *
	 * @return array<int, array{slug:string, label:string, type:string, group:string}>
	 */
	public static function available_contact_fields() {
		if ( ! function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
			return array();
		}
		$fields = fluentcrm_get_custom_contact_fields();
		return self::filter_eligible_fields( is_array( $fields ) ? $fields : array(), self::contact_excluded_slugs() );
	}

	/**
	 * Slugs the admin has chosen to inject into company enrichment, in
	 * the order the admin saved them.
	 *
	 * @return array<int, string>
	 */
	public static function selected_company_slugs() {
		$stored = get_option( FCE_OPT_COMPANY_LOOKUP, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * Slugs the admin has chosen to inject into contact enrichment.
	 *
	 * @return array<int, string>
	 */
	public static function selected_contact_slugs() {
		$stored = get_option( FCE_OPT_CONTACT_LOOKUP, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * Render a Markdown "Existing data on file" block for a hydrated
	 * Company model, using the admin-selected company lookup fields.
	 * Returns empty string if nothing to inject (no fields selected, or
	 * all selected fields have empty values on this record).
	 *
	 * @param object $company FluentCrm\App\Models\Company instance
	 * @return string
	 */
	public static function render_company_block( $company ) {
		$selected = self::selected_company_slugs();
		if ( empty( $selected ) ) {
			return '';
		}

		$cv = isset( $company->meta['custom_values'] ) && is_array( $company->meta['custom_values'] )
			? $company->meta['custom_values']
			: array();

		$labels_by_slug = self::indexed_labels( self::available_company_fields() );

		return self::render_block( $selected, $cv, $labels_by_slug );
	}

	/**
	 * Render a Markdown "Existing data on file" block for a hydrated
	 * Subscriber model, using the admin-selected contact lookup fields.
	 *
	 * @param object $contact FluentCrm\App\Models\Subscriber instance
	 * @return string
	 */
	public static function render_contact_block( $contact ) {
		$selected = self::selected_contact_slugs();
		if ( empty( $selected ) ) {
			return '';
		}

		$cf = $contact->custom_fields();
		if ( ! is_array( $cf ) ) {
			$cf = array();
		}

		$labels_by_slug = self::indexed_labels( self::available_contact_fields() );

		return self::render_block( $selected, $cf, $labels_by_slug );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Slugs to exclude from the company-side picker. Plugin-managed
	 * fields (status fields + the mirrored org_* cache) shouldn't be
	 * injected because they're outputs of the enrichment job — including
	 * them creates a feedback loop where prior enrichments anchor
	 * subsequent ones.
	 *
	 * @return array<int, string>
	 */
	private static function company_excluded_slugs() {
		if ( ! class_exists( 'FCE_Field_Registrar' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( FCE_Field_Registrar::company_field_definitions() as $def ) {
			if ( ! empty( $def['slug'] ) ) {
				$slugs[] = $def['slug'];
			}
		}
		return $slugs;
	}

	/**
	 * Slugs to exclude from the contact-side picker. Plugin-managed
	 * fields (org_* mirrors and individual_* status + outputs).
	 *
	 * @return array<int, string>
	 */
	private static function contact_excluded_slugs() {
		if ( ! class_exists( 'FCE_Field_Registrar' ) ) {
			return array();
		}
		return FCE_Field_Registrar::all_contact_field_slugs();
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 * @param array<int, string>               $excluded_slugs
	 * @return array<int, array{slug:string, label:string, type:string, group:string}>
	 */
	private static function filter_eligible_fields( array $fields, array $excluded_slugs ) {
		$excluded_set = array_flip( $excluded_slugs );
		$out          = array();
		foreach ( $fields as $field ) {
			$slug = isset( $field['slug'] ) ? (string) $field['slug'] : '';
			if ( '' === $slug || isset( $excluded_set[ $slug ] ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => isset( $field['label'] ) ? (string) $field['label'] : $slug,
				'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
				'group' => isset( $field['group'] ) ? (string) $field['group'] : '',
			);
		}
		return $out;
	}

	/**
	 * @param array<int, array{slug:string, label:string, type:string, group:string}> $fields
	 * @return array<string, string>  slug => label
	 */
	private static function indexed_labels( array $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f['slug'] ] = $f['label'];
		}
		return $out;
	}

	/**
	 * Render the Markdown block. Skips fields whose value is empty on
	 * this record. Returns empty string if no field has a value.
	 *
	 * @param array<int, string>    $selected_slugs  ordered slug list
	 * @param array<string, mixed>  $values_by_slug  current record's values
	 * @param array<string, string> $labels_by_slug  human labels
	 * @return string
	 */
	private static function render_block( array $selected_slugs, array $values_by_slug, array $labels_by_slug ) {
		$lines = array();
		foreach ( $selected_slugs as $slug ) {
			$value = $values_by_slug[ $slug ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', $value ), static function ( $v ) {
					return '' !== trim( $v );
				} ) );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$label = $labels_by_slug[ $slug ] ?? $slug;
			$lines[] = '- **' . $label . ':** ' . $value;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "## Existing data on file (treat as given facts)\n\n"
			. "These values come from the requesting organization's own records and should be treated as given. You don't need to verify them. Use them to ground your research and inform the structured field outputs and narrative.\n\n"
			. implode( "\n", $lines )
			. "\n";
	}
}
