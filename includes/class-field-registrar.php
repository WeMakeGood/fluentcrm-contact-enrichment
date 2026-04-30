<?php
/**
 * Field registrar — auto-creates the company and contact custom fields the
 * plugin depends on, idempotently, on activation and (defensively) when
 * FluentCRM finishes initialising.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Field_Registrar {

	public static function register_hooks() {
		// Defensive re-run if FluentCRM activates after we did, or if our
		// activation fired before FluentCRM was loaded.
		add_action( 'fluent_crm/after_init_app', array( __CLASS__, 'ensure_fields' ) );
	}

	/**
	 * Activation hook. Field creation is best-effort here; FluentCRM may not
	 * be loaded yet (multisite activate-on-network, alphabetical plugin load
	 * order). The `fluent_crm/after_init_app` hook covers that case.
	 */
	public static function on_activate() {
		self::ensure_fields();
	}

	/**
	 * Idempotent ensure-fields-exist pass. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function ensure_fields() {
		if ( ! self::fluentcrm_loaded() ) {
			return;
		}

		self::ensure_contact_fields();
		self::ensure_company_fields();
		self::heal_field_types();
		self::sync_org_sector_options();
	}

	/**
	 * One-time migration: 0.1.0-pre versions of the plugin wrote fields with
	 * type "single-select" / "multi-select" instead of "select-one" /
	 * "select-multi". The aliases are accepted by the type registry but the
	 * Vue UI only renders fields whose stored type is the canonical form,
	 * so install records with the alias appear as a JSON dump instead of
	 * an input. We rewrite them in place.
	 *
	 * @return void
	 */
	private static function heal_field_types() {
		$desired_types = array();
		foreach ( self::contact_field_definitions() as $def ) {
			$desired_types[ $def['slug'] ] = $def['type'];
		}
		self::heal_in( '\\FluentCrm\\App\\Models\\CustomContactField', $desired_types );

		$desired_types = array();
		foreach ( self::company_field_definitions() as $def ) {
			$desired_types[ $def['slug'] ] = $def['type'];
		}
		self::heal_in( '\\FluentCrm\\App\\Models\\CustomCompanyField', $desired_types );
	}

	/**
	 * @param string                 $model_class
	 * @param array<string, string>  $desired_types  slug => canonical type
	 * @return void
	 */
	private static function heal_in( $model_class, array $desired_types ) {
		$model    = new $model_class();
		$current  = $model->getGlobalFields();
		$existing = isset( $current['fields'] ) && is_array( $current['fields'] )
			? $current['fields']
			: array();

		$changed = false;
		foreach ( $existing as $i => $field ) {
			if ( empty( $field['slug'] ) ) {
				continue;
			}
			if ( ! isset( $desired_types[ $field['slug'] ] ) ) {
				continue;
			}
			if ( ( $field['type'] ?? '' ) !== $desired_types[ $field['slug'] ] ) {
				$existing[ $i ]['type'] = $desired_types[ $field['slug'] ];
				$changed                = true;
			}
		}

		if ( $changed ) {
			$model->saveGlobalFields( $existing );
		}
	}

	/**
	 * The plugin's contact field definitions. Built dynamically because
	 * `org_focus_areas` options come from the admin-configured list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function contact_field_definitions() {
		return array(
			array(
				'slug'    => 'org_type',
				'label'   => __( 'Organization Type', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => array(
					'Corporation', 'SMB', 'Nonprofit', 'Foundation',
					'Government', 'Association', 'Other',
				),
			),
			array(
				'slug'    => 'org_sector',
				'label'   => __( 'Sector / Industry', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => self::company_industries(),
			),
			array(
				'slug'    => 'org_employees',
				'label'   => __( 'Employee Range', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => array(
					'1–10', '11–50', '51–200', '201–1000',
					'1001–5000', '5000+', 'Unknown',
				),
			),
			array(
				'slug'    => 'org_revenue',
				'label'   => __( 'Revenue Range', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => array(
					'<$1M', '$1–10M', '$10–100M', '$100M–$1B',
					'$1B+', 'Unknown',
				),
			),
			array(
				'slug'    => 'org_geo_scope',
				'label'   => __( 'Geographic Scope', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-multi',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => array( 'Local', 'Regional', 'National', 'International' ),
			),
			array(
				'slug'    => 'org_focus_areas',
				'label'   => __( 'Focus Areas', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-multi',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => self::focus_area_options(),
			),
			array(
				'slug'    => 'org_partnership_models',
				'label'   => __( 'Partnership Models', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-multi',
				'group'   => FCE_GROUP_ORG_PROFILE,
				'options' => array(
					'Donation', 'Cause Marketing', 'Sponsorship',
					'Grant', 'In-Kind', 'Corporate Foundation', 'Other',
				),
			),
			array(
				'slug'    => 'org_alignment_score',
				'label'   => __( 'Alignment Score', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_ALIGNMENT,
				'options' => array( 'Strong', 'Moderate', 'Weak', 'Unknown' ),
			),
		);
	}

	/**
	 * The plugin's company field definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function company_field_definitions() {
		return array(
			array(
				'slug'    => FCE_FIELD_STATUS,
				'label'   => __( 'Enrichment Status', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_COMPANY,
				'options' => array(
					'Not Enriched', 'Pending', 'Processing', 'Complete', 'Failed',
				),
			),
			array(
				'slug'  => FCE_FIELD_DATE,
				'label' => __( 'Date Enriched', 'fluentcrm-contact-enrichment' ),
				'type'  => 'date',
				'group' => FCE_GROUP_COMPANY,
			),
			array(
				'slug'    => FCE_FIELD_CONFIDENCE,
				'label'   => __( 'Enrichment Confidence', 'fluentcrm-contact-enrichment' ),
				'type'    => 'select-one',
				'group'   => FCE_GROUP_COMPANY,
				'options' => array( 'High', 'Medium', 'Low' ),
			),
		);
	}

	/**
	 * Returns the admin-configured focus-area options, with a default list
	 * for first-run and recovery.
	 *
	 * @return array<int, string>
	 */
	public static function focus_area_options() {
		$stored = get_option( FCE_OPT_FOCUS_AREAS );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return array_values( array_filter( array_map( 'strval', $stored ) ) );
		}
		return self::default_focus_areas();
	}

	/**
	 * FluentCRM's canonical industry list (the same enum that powers the
	 * Industry dropdown on the company profile). Used as the option list
	 * for the contact-side `org_sector` field so segmentation on contacts
	 * uses the same vocabulary as company-level data.
	 *
	 * Falls back to a single "Unknown" entry when FluentCRM hasn't loaded
	 * yet (e.g. during plugin activation before FluentCRM's bootstrap
	 * runs). The heal pass on `fluent_crm/after_init_app` rewrites the
	 * options later in the same request.
	 *
	 * @return array<int, string>
	 */
	public static function company_industries() {
		if ( ! class_exists( '\\FluentCrm\\App\\Services\\Helper' ) ) {
			return array( 'Unknown' );
		}
		$list = \FluentCrm\App\Services\Helper::companyCategories();
		return is_array( $list ) && ! empty( $list ) ? $list : array( 'Unknown' );
	}

	/**
	 * Default focus-area list used until the admin configures their own.
	 *
	 * @return array<int, string>
	 */
	public static function default_focus_areas() {
		return array(
			'Environment', 'Conservation', 'Community Development',
			'Education', 'Health', 'Water & Sanitation', 'Food Security',
			'Economic Development', 'Arts & Culture', 'Animal Welfare',
			'Human Rights', 'Disaster Relief',
		);
	}

	/**
	 * Refresh the `org_sector` field's options list to match FluentCRM's
	 * canonical industry list. Called from heal pass so the contact-side
	 * field stays in sync when FluentCRM updates their list.
	 *
	 * Also clears stored `org_sector` values on contacts that aren't in
	 * the new list (one-time A3 cleanup for the v0.2.0 → v0.3.0 transition,
	 * but harmless if run repeatedly — only operates on values currently
	 * out of range).
	 *
	 * @return void
	 */
	public static function sync_org_sector_options() {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CustomContactField' ) ) {
			return;
		}

		$model    = new \FluentCrm\App\Models\CustomContactField();
		$current  = $model->getGlobalFields();
		$existing = isset( $current['fields'] ) && is_array( $current['fields'] )
			? $current['fields']
			: array();

		$desired_options = self::company_industries();
		$updated         = false;

		foreach ( $existing as $i => $field ) {
			if ( isset( $field['slug'] ) && 'org_sector' === $field['slug'] ) {
				$current_options = isset( $field['options'] ) && is_array( $field['options'] )
					? $field['options']
					: array();
				if ( $current_options !== $desired_options ) {
					$existing[ $i ]['options'] = $desired_options;
					$updated                   = true;
				}
				break;
			}
		}

		if ( $updated ) {
			$model->saveGlobalFields( $existing );
			self::clear_invalid_org_sector_values( $desired_options );
		}
	}

	/**
	 * Delete stored `org_sector` values on contacts that aren't in the
	 * current allowed list. Re-enrichment will refill them. We don't try
	 * to remap; "Education" doesn't have a clean target in the FluentCRM
	 * list (could be Higher Education, Education Management, E-Learning,
	 * Primary/Secondary Education, or Professional Training & Coaching),
	 * and a hardcoded mapping ages badly.
	 *
	 * @param array<int, string> $allowed
	 * @return int  rows deleted
	 */
	private static function clear_invalid_org_sector_values( array $allowed ) {
		global $wpdb;
		if ( empty( $allowed ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $allowed ), '%s' ) );
		$query        = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}fc_subscriber_meta
			 WHERE object_type = 'custom_field'
			   AND `key` = 'org_sector'
			   AND `value` NOT IN ( $placeholders )",
			$allowed
		);
		return (int) $wpdb->query( $query );
	}

	/**
	 * Refresh the `org_focus_areas` field's options list to match the current
	 * admin-configured options. Called from settings save.
	 *
	 * @return void
	 */
	public static function sync_focus_area_options() {
		if ( ! self::fluentcrm_loaded() ) {
			return;
		}

		$model    = new \FluentCrm\App\Models\CustomContactField();
		$current  = $model->getGlobalFields();
		$existing = isset( $current['fields'] ) && is_array( $current['fields'] )
			? $current['fields']
			: array();

		$options = self::focus_area_options();
		$updated = false;

		foreach ( $existing as $i => $field ) {
			if ( isset( $field['slug'] ) && 'org_focus_areas' === $field['slug'] ) {
				$existing[ $i ]['options'] = $options;
				$updated                   = true;
				break;
			}
		}

		if ( $updated ) {
			$model->saveGlobalFields( $existing );
		}
	}

	/**
	 * Append our contact fields to FluentCRM's existing list, preserving
	 * everything the admin (or other plugins) have already created.
	 *
	 * @return void
	 */
	private static function ensure_contact_fields() {
		$model    = new \FluentCrm\App\Models\CustomContactField();
		$current  = $model->getGlobalFields();
		$existing = isset( $current['fields'] ) && is_array( $current['fields'] )
			? $current['fields']
			: array();

		$existing_slugs = self::existing_slugs( $existing );
		$desired        = self::contact_field_definitions();

		$added = false;
		foreach ( $desired as $field ) {
			if ( ! in_array( $field['slug'], $existing_slugs, true ) ) {
				$existing[]       = $field;
				$existing_slugs[] = $field['slug'];
				$added            = true;
			}
		}

		if ( $added ) {
			$model->saveGlobalFields( $existing );
		}
	}

	/**
	 * Same as ensure_contact_fields, but for company-level fields.
	 *
	 * @return void
	 */
	private static function ensure_company_fields() {
		$model    = new \FluentCrm\App\Models\CustomCompanyField();
		$current  = $model->getGlobalFields();
		$existing = isset( $current['fields'] ) && is_array( $current['fields'] )
			? $current['fields']
			: array();

		$existing_slugs = self::existing_slugs( $existing );
		$desired        = self::company_field_definitions();

		$added = false;
		foreach ( $desired as $field ) {
			if ( ! in_array( $field['slug'], $existing_slugs, true ) ) {
				$existing[]       = $field;
				$existing_slugs[] = $field['slug'];
				$added            = true;
			}
		}

		if ( $added ) {
			$model->saveGlobalFields( $existing );
		}
	}

	/**
	 * Extract the slug list from a stored fields array.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<int, string>
	 */
	private static function existing_slugs( array $fields ) {
		$slugs = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['slug'] ) ) {
				$slugs[] = $field['slug'];
			}
		}
		return $slugs;
	}

	/**
	 * @return bool true if FluentCRM's classes are loadable.
	 */
	private static function fluentcrm_loaded() {
		return class_exists( '\\FluentCrm\\App\\Models\\CustomContactField' )
			&& class_exists( '\\FluentCrm\\App\\Models\\CustomCompanyField' );
	}
}
