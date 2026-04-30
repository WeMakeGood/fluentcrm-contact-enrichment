<?php
/**
 * Enrichment cron job. Single entry point: `fce_run_enrichment_job` cron hook
 * receives a company ID, runs the full pipeline, writes results to FluentCRM.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Enrichment_Job {

	public static function register_hooks() {
		add_action( FCE_CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	/**
	 * Cron handler.
	 *
	 * @param int $company_id
	 * @return void
	 */
	public static function run( $company_id ) {
		$company_id = (int) $company_id;
		if ( $company_id <= 0 ) {
			self::log( 'invalid company_id passed to job' );
			return;
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			self::log( 'FluentCRM not loaded — cannot run enrichment for company ' . $company_id );
			return;
		}

		$company = \FluentCrm\App\Models\Company::find( $company_id );
		if ( ! $company ) {
			self::log( 'company not found: ' . $company_id );
			return;
		}

		self::set_status( $company, 'Processing' );

		try {
			$system = self::build_system_prompt();
			$user   = self::build_user_prompt( $company );

			$result = FCE_Claude_Client::research( $system, $user );
			if ( null !== $result['error'] ) {
				self::fail( $company, $result['error'] );
				return;
			}

			$parsed = FCE_Data_Mapper::extract_json( $result['text'] );
			if ( null === $parsed ) {
				self::fail(
					$company,
					__( 'Claude response did not contain a parseable JSON object.', 'fluentcrm-contact-enrichment' ),
					$result['text']
				);
				return;
			}

			$mapped = FCE_Data_Mapper::map( $parsed );

			self::write_company( $company, $mapped );
			$native_written = self::write_native_fields( $company, $mapped );
			self::create_research_note( $company, $mapped, $result['search_count'], $native_written );
			$contacts_updated = self::push_to_contacts( $company, $mapped );

			self::log(
				sprintf(
					'enrichment complete for company %d (name=%s): %d contacts updated, %d searches used, %d values dropped',
					$company->id,
					(string) $company->name,
					$contacts_updated,
					(int) $result['search_count'],
					count( $mapped['dropped'] )
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $company, 'unhandled error: ' . $e->getMessage() );
		}
	}

	// ---------------------------------------------------------------------
	// Prompt builders
	// ---------------------------------------------------------------------

	/**
	 * @return string
	 */
	private static function build_system_prompt() {
		$base = self::base_research_discipline();

		$modules = FCE_Context_Modules::active();
		$module_text = '';
		foreach ( $modules as $module ) {
			$module_text .= "\n\n---\n\n";
			if ( '' !== $module['title'] ) {
				$module_text .= "## " . $module['title'] . "\n\n";
			}
			$module_text .= $module['content'];
		}

		$schema = self::schema_instructions();

		return $base . $module_text . "\n\n---\n\n" . $schema;
	}

	/**
	 * Research-discipline language adapted from the
	 * creating-organization-dossiers skill — applies to every enrichment run
	 * regardless of admin context modules.
	 *
	 * @return string
	 */
	private static function base_research_discipline() {
		return <<<PROMPT
You are an organizational research analyst. Research the organization described in the user message and return both structured data and a narrative summary, following the schema below.

Research discipline:

- Use web search to find current, primary-source information. Prefer the organization's own site, recent press, and verifiable filings (990s for US nonprofits, annual reports for foundations and corporations).
- Cite sources inline in narrative sections. Make the source visible in the language ("according to their 2024 annual report", "their About page states", "TechCrunch reported in March 2025"). The reader needs to be able to evaluate where each claim came from.
- Distinguish what the organization says about itself from third-party verification. When you can only find self-reported information, say so.
- Mark inferences as inferences. If you are reasoning from incomplete information ("likely a small team based on website size"), make the inferential step visible in the language rather than stating the inference as fact.
- When information cannot be reasonably determined, use "Unknown" for structured fields and name the gap explicitly in the narrative. Do not fabricate.
- Before assigning the alignment score, weigh more than the first framing that comes to mind — the alignment dimensions in the context modules below typically pull in different directions for any organization, and the score should reflect that tension honestly.

PROMPT;
	}

	/**
	 * The schema/JSON instructions, dynamically populated from the field
	 * definitions so the allowed-options lists stay in sync with the
	 * registrar.
	 *
	 * @return string
	 */
	private static function schema_instructions() {
		$contact_defs = array();
		foreach ( FCE_Field_Registrar::contact_field_definitions() as $def ) {
			$contact_defs[ $def['slug'] ] = $def;
		}

		$type_options    = self::format_options( $contact_defs['org_type']['options'] );
		$sector_options  = self::format_options( $contact_defs['org_sector']['options'] );
		$emp_options     = self::format_options( $contact_defs['org_employees']['options'] );
		$rev_options     = self::format_options( $contact_defs['org_revenue']['options'] );
		$geo_options     = self::format_options( $contact_defs['org_geo_scope']['options'] );
		$focus_options   = self::format_options( $contact_defs['org_focus_areas']['options'] );
		$models_options  = self::format_options( $contact_defs['org_partnership_models']['options'] );
		$align_options   = self::format_options( $contact_defs['org_alignment_score']['options'] );

		$confidence_options = '"High" | "Medium" | "Low"';
		$industry_options   = self::linkedin_industry_options();

		return <<<SCHEMA
Return your final answer as a JSON object wrapped in <json>...</json> tags. Use exactly these keys:

{
  "org_type": "...",
  "org_sector": "...",
  "org_employees": "...",
  "org_revenue": "...",
  "org_geo_scope": ["..."],
  "org_focus_areas": ["..."],
  "org_partnership_models": ["..."],
  "org_alignment_score": "...",
  "confidence": "...",
  "native_fields": {
    "linkedin_industry": "...",
    "description": "...",
    "website": "https://...",
    "headquarters": {
      "address_line_1": "...",
      "address_line_2": "...",
      "city": "...",
      "state": "...",
      "postal_code": "...",
      "country": "..."
    },
    "linkedin_url": "https://linkedin.com/company/...",
    "facebook_url": "https://facebook.com/...",
    "twitter_url": "https://twitter.com/..."
  },
  "narrative": {
    "decision_maker_context": "...",
    "recent_developments": "...",
    "alignment_assessment": "...",
    "recommended_approach": "..."
  }
}

Allowed values:

- org_type: {$type_options}
- org_sector: {$sector_options}
- org_employees: {$emp_options}
- org_revenue: {$rev_options}
- org_geo_scope (array): {$geo_options}
- org_focus_areas (array): {$focus_options}
- org_partnership_models (array): {$models_options}
- org_alignment_score: {$align_options}
- confidence: {$confidence_options}

For array fields, return an array of values — not a comma-separated string. Only include values that match the allowed options exactly. If you cannot determine a value with reasonable confidence, use "Unknown" for the relevant single-select field, or omit unknown values from arrays.

For the `native_fields` object:

- `linkedin_industry`: must be one of these LinkedIn industry categories exactly (case-sensitive). If none of these fit confidently, omit the key or use null. Do not invent values:
  {$industry_options}
- `description`: 1–2 sentence neutral summary of what the organization does and who they serve. No promotional language. Source-grounded — if details are unclear, keep it short rather than embellish.
- `website`: the organization's primary public website URL. Verify it's the right organization (not a similarly-named one) before returning. Omit the key if you can't confirm or if no public website exists.
- `headquarters`: physical headquarters address. Include only fields you can verify; omit any field you can't determine. Use null or omit the key for unknown values rather than guessing. The address goes to standard CRM address fields, not narrative.
- `linkedin_url`, `facebook_url`, `twitter_url`: full URLs to the organization's official accounts only. Verify the account is the organization's, not a similarly-named one. Omit the key if you can't confirm.

Native fields are written to standard FluentCRM company fields; they are independent of the org_* contact fields. We will only fill native fields that are currently empty on the company record, so it's fine to return values that may already exist.

The narrative sections should be plain Markdown. Each section is one to three short paragraphs. Cite sources inline in the prose; do not include a separate references list.

- decision_maker_context: Who appears to make partnership and funding decisions, and what is publicly known about their priorities, tenure, or recent moves.
- recent_developments: Significant changes in the past 12 months — funding rounds, leadership transitions, strategic shifts, public reputation events.
- alignment_assessment: How the organization's stated and demonstrated priorities compare to the alignment criteria in the context modules. Name what fits and what does not.
- recommended_approach: What kind of outreach is likely to land — the partnership model that fits, the contact path that fits, and the timing if any.

When citing sources inside the narrative sections, use Markdown link syntax: `[the cited claim](https://source-url)`. Do NOT use `<cite>` tags inside the JSON string values — those tags don't render in the destination CRM note. Plain Markdown links and inline source naming ("their 2024 annual report says...") are the right format.
SCHEMA;
	}

	/**
	 * Returns FluentCRM's canonical industry list as a pipe-joined quoted
	 * string for the schema instructions. Falls back to a generic message
	 * if the helper isn't available (FluentCRM not loaded or version
	 * mismatch).
	 *
	 * @return string
	 */
	private static function linkedin_industry_options() {
		if ( ! class_exists( '\\FluentCrm\\App\\Services\\Helper' ) ) {
			return '(industry list unavailable — leave linkedin_industry empty)';
		}
		$cats = \FluentCrm\App\Services\Helper::companyCategories();
		if ( ! is_array( $cats ) || empty( $cats ) ) {
			return '(industry list unavailable — leave linkedin_industry empty)';
		}
		return self::format_options( $cats );
	}

	/**
	 * @param array<int, string> $options
	 * @return string  pipe-joined quoted list, e.g. '"A" | "B" | "C"'
	 */
	private static function format_options( array $options ) {
		return implode(
			' | ',
			array_map(
				static function ( $option ) {
					return '"' . $option . '"';
				},
				$options
			)
		);
	}

	/**
	 * @param object $company FluentCrm\App\Models\Company instance
	 * @return string
	 */
	private static function build_user_prompt( $company ) {
		$lines = array(
			'Research this organization and return structured data plus a narrative summary, per the schema in the system prompt.',
			'',
			'Organization name: ' . (string) $company->name,
		);
		if ( ! empty( $company->website ) ) {
			$lines[] = 'Website: ' . (string) $company->website;
		}
		if ( ! empty( $company->industry ) ) {
			$lines[] = 'Industry hint: ' . (string) $company->industry;
		}
		if ( ! empty( $company->description ) ) {
			$lines[] = '';
			$lines[] = 'Existing description:';
			$lines[] = (string) $company->description;
		}
		$lines[] = '';
		$lines[] = 'Return only the JSON object inside <json>...</json> tags after your research is complete.';
		return implode( "\n", $lines );
	}

	// ---------------------------------------------------------------------
	// Result writers
	// ---------------------------------------------------------------------

	/**
	 * Update the company's enrichment custom fields with success state +
	 * confidence. Uses FluentCrmApi('companies')->createOrUpdate as the
	 * canonical write path so meta serialization and the company_updated
	 * action both fire correctly.
	 *
	 * @param object $company
	 * @param array  $mapped
	 * @return void
	 */
	private static function write_company( $company, array $mapped ) {
		$values = array(
			FCE_FIELD_STATUS => 'Complete',
			FCE_FIELD_DATE   => current_time( 'Y-m-d' ),
		);
		if ( ! empty( $mapped['company']['enrichment_confidence'] ) ) {
			$values[ FCE_FIELD_CONFIDENCE ] = $mapped['company']['enrichment_confidence'];
		}

		\FluentCrmApi( 'companies' )->createOrUpdate(
			array(
				'id'            => (int) $company->id,
				'name'          => (string) $company->name,
				'custom_values' => $values,
			)
		);
	}

	/**
	 * Set or clear the company's enrichment_status. Used for in-progress
	 * status flips and for the failure path.
	 *
	 * @param object $company
	 * @param string $status
	 * @return void
	 */
	private static function set_status( $company, $status ) {
		\FluentCrmApi( 'companies' )->createOrUpdate(
			array(
				'id'            => (int) $company->id,
				'name'          => (string) $company->name,
				'custom_values' => array(
					FCE_FIELD_STATUS => $status,
				),
			)
		);
	}

	/**
	 * Fill native FluentCRM company columns (industry, description, address,
	 * social URLs, employees_number) — but only those that are currently
	 * empty. We never overwrite admin-curated values.
	 *
	 * @param object $company
	 * @param array  $mapped
	 * @return array<int, string>  list of column names actually written
	 */
	private static function write_native_fields( $company, array $mapped ) {
		$candidates = isset( $mapped['native_fields'] ) && is_array( $mapped['native_fields'] )
			? $mapped['native_fields']
			: array();
		if ( empty( $candidates ) ) {
			return array();
		}

		// Re-fetch the company so we have the latest column values.
		// $company in scope was hydrated at job start (status=Pending).
		$fresh = \FluentCrm\App\Models\Company::find( (int) $company->id );
		if ( ! $fresh ) {
			return array();
		}

		$to_write = array();
		foreach ( $candidates as $column => $value ) {
			$existing = self::existing_column_value( $fresh, $column );
			if ( '' === trim( (string) $existing ) ) {
				$to_write[ $column ] = $value;
			}
		}

		if ( empty( $to_write ) ) {
			return array();
		}

		$payload = array_merge(
			array(
				'id'   => (int) $company->id,
				'name' => (string) $company->name,
			),
			$to_write
		);

		\FluentCrmApi( 'companies' )->createOrUpdate( $payload );

		return array_keys( $to_write );
	}

	/**
	 * Read the current value of a native column on a Company model. Some
	 * columns are top-level attributes; the rest live in the same table.
	 *
	 * @param object $company
	 * @param string $column
	 * @return string
	 */
	private static function existing_column_value( $company, $column ) {
		$value = $company->{$column} ?? '';
		// employees_number is an int column — 0 and null both mean "empty"
		// from the admin's perspective.
		if ( 'employees_number' === $column && (int) $value === 0 ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param object             $company
	 * @param array              $mapped
	 * @param int                $search_count
	 * @param array<int, string> $native_written  list of native columns that were filled
	 * @return void
	 */
	private static function create_research_note( $company, array $mapped, $search_count, array $native_written = array() ) {
		$markdown = self::format_note_markdown( $mapped, $search_count, $native_written );
		$html     = self::markdown_to_html( $markdown );

		$data = array(
			'subscriber_id' => (int) $company->id,
			'type'          => 'note',
			'title'         => sprintf(
				/* translators: %s: ISO date */
				__( 'Enrichment Research — %s', 'fluentcrm-contact-enrichment' ),
				current_time( 'Y-m-d' )
			),
			'description'   => $html,
			'created_at'    => current_time( 'mysql' ),
		);

		// Sanitize::contactNote allows the HTML we just generated; it filters
		// the same way wp_kses_post does for the description field.
		if ( class_exists( '\\FluentCrm\\App\\Services\\Sanitize' ) ) {
			$data = \FluentCrm\App\Services\Sanitize::contactNote( $data );
		}

		// Same-day replace: if the most recent enrichment note on this
		// company was created today, update it in place rather than
		// appending a duplicate. Re-clicking Enrich on the same day
		// shouldn't pile up notes; cross-day re-enrichments preserve
		// history.
		$existing = self::find_todays_research_note( (int) $company->id );
		if ( $existing ) {
			$existing->fill( array(
				'description' => $data['description'],
				'created_at'  => $data['created_at'],
			) )->save();
			do_action( 'fluent_crm/company_note_updated', $existing, $company, $data );
			return;
		}

		$note = \FluentCrm\App\Models\CompanyNote::create( $data );
		do_action( 'fluent_crm/company_note_added', $note, $company, $data );
	}

	/**
	 * Look for an enrichment-research note on this company created today.
	 * Matches by title prefix so we ignore generic admin notes that may
	 * have been added manually.
	 *
	 * @param int $company_id
	 * @return object|null  CompanyNote or null
	 */
	private static function find_todays_research_note( $company_id ) {
		$today_prefix = sprintf(
			/* translators: %s: ISO date */
			__( 'Enrichment Research — %s', 'fluentcrm-contact-enrichment' ),
			current_time( 'Y-m-d' )
		);

		return \FluentCrm\App\Models\CompanyNote::where( 'subscriber_id', $company_id )
			->where( 'title', $today_prefix )
			->orderBy( 'id', 'desc' )
			->first();
	}

	/**
	 * @param array              $mapped
	 * @param int                $search_count
	 * @param array<int, string> $native_written
	 * @return string
	 */
	private static function format_note_markdown( array $mapped, $search_count, array $native_written = array() ) {
		$n = $mapped['narrative'];

		$body  = "## Decision-maker context\n\n" . self::or_placeholder( $n['decision_maker_context'] ) . "\n\n";
		$body .= "## Recent developments\n\n" . self::or_placeholder( $n['recent_developments'] ) . "\n\n";
		$body .= "## Alignment assessment\n\n" . self::or_placeholder( $n['alignment_assessment'] ) . "\n\n";
		$body .= "## Recommended approach\n\n" . self::or_placeholder( $n['recommended_approach'] ) . "\n";

		if ( ! empty( $native_written ) ) {
			$body .= "\n---\n\n*Native fields populated (only filled when the column was previously empty): " . implode( ', ', $native_written ) . ".*\n";
		}

		if ( ! empty( $mapped['dropped'] ) ) {
			$body .= "\n---\n\n*Notes for the field admin: " . count( $mapped['dropped'] ) . ' value(s) returned by Claude were not in the allowed options list and were dropped: ' . implode( '; ', $mapped['dropped'] ) . ".*\n";
		}

		$body .= "\n*Generated using " . (int) $search_count . ' web search' . ( 1 === (int) $search_count ? '' : 'es' ) . ".*\n";

		return $body;
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function or_placeholder( $text ) {
		return '' !== trim( (string) $text )
			? (string) $text
			: __( '_(Not provided.)_', 'fluentcrm-contact-enrichment' );
	}

	/**
	 * Find every contact whose primary company_id matches and push the
	 * mapped contact-side values via syncCustomFieldValues, with
	 * deleteOtherValues=false so we don't wipe other custom fields.
	 *
	 * @param object $company
	 * @param array  $mapped
	 * @return int  number of contacts updated
	 */
	private static function push_to_contacts( $company, array $mapped ) {
		$values = $mapped['contact'];
		if ( empty( $values ) ) {
			return 0;
		}

		$contacts = \FluentCrm\App\Models\Subscriber::where( 'company_id', (int) $company->id )->get();
		$count    = 0;

		foreach ( $contacts as $contact ) {
			$contact->syncCustomFieldValues( $values, false );
			$count++;
		}

		return $count;
	}

	// ---------------------------------------------------------------------
	// Failure path
	// ---------------------------------------------------------------------

	/**
	 * Record a failure on the company: status → Failed, append a note with
	 * the error details, and write to the WP error log.
	 *
	 * @param object $company
	 * @param string $error
	 * @param string $raw_text  Optional raw response text for diagnostics.
	 * @return void
	 */
	private static function fail( $company, $error, $raw_text = '' ) {
		self::log(
			sprintf( 'enrichment failed for company %d (name=%s): %s', $company->id, (string) $company->name, $error )
		);

		self::set_status( $company, 'Failed' );

		$markdown  = "## Enrichment failed\n\n";
		$markdown .= "**Error:** " . $error . "\n\n";
		$markdown .= "Run again from the company profile when ready.\n";

		if ( '' !== $raw_text ) {
			$markdown .= "\n---\n\n";
			$markdown .= "<details><summary>Raw response</summary>\n\n";
			$markdown .= "```\n" . substr( $raw_text, 0, 4000 ) . "\n```\n";
			$markdown .= "</details>\n";
		}

		$html = self::markdown_to_html( $markdown );

		$data = array(
			'subscriber_id' => (int) $company->id,
			'type'          => 'note',
			'title'         => sprintf(
				/* translators: %s: ISO date */
				__( 'Enrichment Failed — %s', 'fluentcrm-contact-enrichment' ),
				current_time( 'Y-m-d' )
			),
			'description'   => $html,
			'created_at'    => current_time( 'mysql' ),
		);

		if ( class_exists( '\\FluentCrm\\App\\Services\\Sanitize' ) ) {
			$data = \FluentCrm\App\Services\Sanitize::contactNote( $data );
		}

		try {
			$note = \FluentCrm\App\Models\CompanyNote::create( $data );
			do_action( 'fluent_crm/company_note_added', $note, $company, $data );
		} catch ( \Throwable $e ) {
			self::log( 'failed to write failure note: ' . $e->getMessage() );
		}
	}

	// ---------------------------------------------------------------------
	// Markdown rendering
	// ---------------------------------------------------------------------

	/**
	 * Convert Markdown to HTML using bundled Parsedown. Loaded lazily so a
	 * missing vendor/ directory only fails when notes are actually being
	 * written, not at every plugin load.
	 *
	 * @param string $markdown
	 * @return string
	 */
	private static function markdown_to_html( $markdown ) {
		$autoload = FCE_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}

		if ( class_exists( '\\Parsedown' ) ) {
			$parser = new \Parsedown();
			$parser->setSafeMode( true );
			return $parser->text( $markdown );
		}

		// Fallback: WP autop. Acceptable degradation if vendor/ is missing.
		return wpautop( $markdown );
	}

	// ---------------------------------------------------------------------
	// Logging
	// ---------------------------------------------------------------------

	/**
	 * @param string $message
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[fce] ' . $message );
		}
	}
}
