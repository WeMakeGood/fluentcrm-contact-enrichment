<?php
/**
 * Sync the cached org_* values from companies to their primary contacts.
 *
 * Two entry points use this:
 *   - Per-company "Sync to Contacts" button on the company profile section
 *     (FCE_Company_Section::ajax_sync)
 *   - Bulk "Resync all contacts" Danger Zone button on the admin settings
 *     (FCE_Admin_Settings::handle_bulk_resync)
 *
 * The class never calls the Anthropic API. It reads what's already cached
 * on the company record (populated by the enrichment job) and writes that
 * to every contact whose primary company_id matches.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Contact_Sync {

	/**
	 * Sync one company's cached org_* values to all primary-linked contacts.
	 *
	 * @param int $company_id
	 * @return array{contacts_updated:int, fields_per_contact:int, error:?string}
	 */
	public static function sync_company( $company_id ) {
		$company_id = (int) $company_id;
		if ( $company_id <= 0 ) {
			return self::result( 0, 0, 'invalid_company_id' );
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return self::result( 0, 0, 'fluentcrm_not_loaded' );
		}

		$company = \FluentCrm\App\Models\Company::find( $company_id );
		if ( ! $company ) {
			return self::result( 0, 0, 'company_not_found' );
		}

		$values = self::cached_org_values( $company );
		if ( empty( $values ) ) {
			return self::result( 0, 0, 'no_cached_values' );
		}

		$contacts = \FluentCrm\App\Models\Subscriber::where( 'company_id', $company_id )->get();
		$count    = 0;
		foreach ( $contacts as $contact ) {
			$contact->syncCustomFieldValues( $values, false );
			$count++;
		}

		return self::result( $count, count( $values ), null );
	}

	/**
	 * Walk every company with cached org_* values and resync each one's
	 * primary-linked contacts. The caller (admin settings handler) is
	 * responsible for capability + nonce + typed-confirmation gating.
	 *
	 * Synchronous on purpose: the admin clicked a button and is waiting
	 * for the result. For installs above ~5k companies, this could time
	 * out a default PHP execution time; we accept that as a known limit
	 * documented in CLAUDE.md and the readme.
	 *
	 * @return array{
	 *   companies_processed:int,
	 *   companies_skipped:int,
	 *   contacts_updated:int,
	 *   skipped_company_ids:array<int, int>,
	 * }
	 */
	public static function bulk_resync() {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return array(
				'companies_processed' => 0,
				'companies_skipped'   => 0,
				'contacts_updated'    => 0,
				'skipped_company_ids' => array(),
			);
		}

		$processed       = 0;
		$skipped         = 0;
		$contacts_total  = 0;
		$skipped_ids     = array();

		// Don't load all companies into memory at once — chunk through them.
		// FluentCRM's Eloquent has a chunk method on the query builder.
		\FluentCrm\App\Models\Company::query()->chunk( 100, function ( $companies ) use ( &$processed, &$skipped, &$contacts_total, &$skipped_ids ) {
			foreach ( $companies as $company ) {
				$result = self::sync_company( (int) $company->id );
				if ( $result['error'] ) {
					if ( 'no_cached_values' === $result['error'] ) {
						$skipped++;
						$skipped_ids[] = (int) $company->id;
					}
					continue;
				}
				$processed++;
				$contacts_total += $result['contacts_updated'];
			}
		} );

		return array(
			'companies_processed' => $processed,
			'companies_skipped'   => $skipped,
			'contacts_updated'    => $contacts_total,
			'skipped_company_ids' => $skipped_ids,
		);
	}

	/**
	 * Pull the 8 org_* values out of a company's cached custom_values,
	 * converting any FluentCRM-array-coerced multi-select values back to
	 * comma-joined strings (the format Subscriber::syncCustomFieldValues
	 * stores natively on contacts).
	 *
	 * Only returns values that are actually populated; an org_* slug whose
	 * cached value is empty (or absent) is omitted from the result so we
	 * don't blank out a contact's existing value.
	 *
	 * @param object $company
	 * @return array<string, string>  slug => value
	 */
	private static function cached_org_values( $company ) {
		$cv = isset( $company->meta['custom_values'] ) && is_array( $company->meta['custom_values'] )
			? $company->meta['custom_values']
			: array();

		$out = array();
		foreach ( FCE_Field_Registrar::org_field_slugs() as $slug ) {
			if ( ! isset( $cv[ $slug ] ) ) {
				continue;
			}
			$value = $cv[ $slug ];
			if ( is_array( $value ) ) {
				// Multi-select stored as array on company side. Filter
				// empties, join with ", " for the contact-side string format.
				$value = implode( ', ', array_filter(
					array_map( 'strval', $value ),
					static function ( $v ) {
						return '' !== trim( $v );
					}
				) );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$out[ $slug ] = $value;
		}
		return $out;
	}

	/**
	 * @param int    $count
	 * @param int    $field_count
	 * @param string $error
	 * @return array
	 */
	private static function result( $count, $field_count, $error ) {
		return array(
			'contacts_updated'   => $count,
			'fields_per_contact' => $field_count,
			'error'              => $error,
		);
	}
}
