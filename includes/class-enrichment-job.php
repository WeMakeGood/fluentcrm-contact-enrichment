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
		add_action( FCE_CRON_CONTACT, array( __CLASS__, 'run_contact' ), 10, 1 );
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

	/**
	 * Cron handler for contact-side individual research (v0.7.0+).
	 *
	 * Pipeline parallels run() but with stricter ethics framing
	 * (Apra-grounded discipline, relevance gate, source-quality
	 * guidance), a different schema (individual_* fields), and a
	 * subscriber note instead of a company note. Critically: this
	 * checks the contact's individual_research_consent value before
	 * spending any API budget; "Restricted" short-circuits with a
	 * status flip and no research call.
	 *
	 * @param int $contact_id
	 * @return void
	 */
	public static function run_contact( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			self::log( 'invalid contact_id passed to job' );
			return;
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
			self::log( 'FluentCRM not loaded — cannot run contact enrichment for ' . $contact_id );
			return;
		}

		$contact = \FluentCrm\App\Models\Subscriber::find( $contact_id );
		if ( ! $contact ) {
			self::log( 'contact not found: ' . $contact_id );
			return;
		}

		// Consent gate: respect Apra's confidentiality principle and the
		// admin's per-contact opt-out flag. If the contact's consent is
		// Restricted, set status accordingly and bail before spending
		// any tokens or making any external call.
		$cf      = $contact->custom_fields();
		$consent = isset( $cf[ FCE_IND_CONSENT ] ) ? (string) $cf[ FCE_IND_CONSENT ] : 'Allowed';
		if ( 'Restricted' === $consent ) {
			$contact->syncCustomFieldValues( array( FCE_IND_STATUS => 'Restricted' ), false );
			self::log( sprintf( 'contact %d enrichment skipped: research_consent=Restricted', $contact_id ) );
			return;
		}

		self::set_contact_status( $contact, 'Processing' );

		try {
			$system = self::build_contact_system_prompt();
			$user   = self::build_contact_user_prompt( $contact );

			$result = FCE_Claude_Client::research( $system, $user );
			if ( null !== $result['error'] ) {
				self::fail_contact( $contact, $result['error'] );
				return;
			}

			$parsed = FCE_Data_Mapper::extract_json( $result['text'] );
			if ( null === $parsed ) {
				self::fail_contact(
					$contact,
					__( 'Claude response did not contain a parseable JSON object.', 'fluentcrm-contact-enrichment' ),
					$result['text']
				);
				return;
			}

			$mapped = FCE_Data_Mapper::map_individual( $parsed );

			self::write_contact( $contact, $mapped );
			self::create_contact_research_note( $contact, $mapped, $result['search_count'] );

			self::log(
				sprintf(
					'contact enrichment complete for contact %d (email=%s): %d searches used, %d values dropped',
					$contact->id,
					(string) $contact->email,
					(int) $result['search_count'],
					count( $mapped['dropped'] )
				)
			);
		} catch ( \Throwable $e ) {
			self::fail_contact( $contact, 'unhandled error: ' . $e->getMessage() );
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

Note: the contact-side `org_sector` field is derived automatically from your `linkedin_industry` value — they share FluentCRM's industry vocabulary. You don't need to return `org_sector` separately.

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
	// Contact-side prompt builders (v0.7.0+)
	// ---------------------------------------------------------------------

	/**
	 * Build the contact-research system prompt. Three layers:
	 *   1. Apra-grounded research discipline (stricter than the org
	 *      preamble — adds the Confidentiality / Relevance / Source
	 *      Provenance / Privacy principles).
	 *   2. Active contact context modules (admin's framing for the
	 *      use case: donor research, cohort prep, sales, board, etc.).
	 *   3. Schema instructions with admin-configurable capacity tier
	 *      options.
	 *
	 * @return string
	 */
	private static function build_contact_system_prompt() {
		$base = self::contact_research_discipline();

		$modules = FCE_Contact_Context_Modules::active();
		$module_text = '';
		foreach ( $modules as $module ) {
			$module_text .= "\n\n---\n\n";
			if ( '' !== $module['title'] ) {
				$module_text .= "## " . $module['title'] . "\n\n";
			}
			$module_text .= $module['content'];
		}

		$schema = self::contact_schema_instructions();

		return $base . $module_text . "\n\n---\n\n" . $schema;
	}

	/**
	 * Apra-grounded research discipline applied to every contact
	 * enrichment regardless of admin context modules. The principles
	 * are about *how* to research a person, not what to do with the
	 * findings — they apply equally to fundraising prospect research,
	 * cohort program participant prep, B2B sales research, and board
	 * recruitment.
	 *
	 * @return string
	 */
	private static function contact_research_discipline() {
		return <<<PROMPT
You are an individual-contact researcher operating to professional standards adapted from Apra (the Association of Prospect Researchers for Advancement). Your job is to research the person described in the user message and return structured data plus a narrative summary, following the schema below.

Research discipline:

- **Integrity & Honesty.** Use only legitimate public sources. Be honest about what you find versus what you infer. Don't fabricate, don't aggregate beyond what the relationship justifies, don't surveil.
- **Accuracy & Competence.** Cite sources inline in narrative sections. Make the source visible in the language ("their LinkedIn profile shows…", "the Form 4 filed in March 2025 reports…", "their employer's leadership page lists them as…"). When you can only find self-reported information, say so.
- **Relevance.** Apra's core constraint: research is restricted to information bearing on the relationship the requesting organization is trying to build. Read the context modules below to understand what the requesting organization considers relevant for THEIR use case (fundraising research, cohort prep, sales prospecting, board recruitment) and confine your research accordingly. Even if a piece of information is findable, don't include it unless it bears on that use case. Personal life details, family information, and private-residence specifics are out of scope for any of these use cases.
- **Confidentiality & Privacy.** Don't research what wouldn't be appropriate in a face-to-face professional conversation. If you find sensitive information that doesn't bear on the use case, omit it. The Apra Social Media Responsibility principle applies: do not unreasonably intrude on an individual's privacy. LinkedIn profile content and other professional public information is fair game; personal social media beyond professional context is not.
- **Source Provenance.** Track and report where each piece of data came from. Apra's Data Standards principle: "ensure all information is legally obtained and publicly available from reliable sources."
- **Sources to prefer:** SEC EDGAR insider filings (executives only); FEC contribution data; IRS Form 990 schedules listing donors; foundation board and trustee rosters; capital campaign donor recognition (public donor walls, named gifts, annual reports); employer leadership/about pages with verified bios; LinkedIn profile pages (career history, board service, volunteering); published interviews, books, op-eds, conference talks; industry awards; news coverage in professional context.
- **Sources to AVOID:** personal-data aggregator sites (Spokeo, BeenVerified, etc.); reverse-lookup services; social media content that's not professional in nature; anything resembling surveillance or aggregation beyond what a relevant relationship would justify; private real-estate or family records.
- **Mark inferences as inferences.** If you reason from incomplete information, make the inferential step visible in the language rather than stating an inference as fact. Confidence calibration matters more here than for org research because individual research has thinner sources and higher stakes.
- **When information cannot be reasonably determined, say so.** Use "Unknown" for structured fields and name the gap explicitly in the narrative. Most individuals are not public figures; "Unknown" will be the honest answer for many fields, especially for non-major-donor / non-executive subjects.
- **Before assigning the alignment score, weigh more than the first framing that comes to mind.** The alignment dimensions in the context modules below typically pull in different directions for any individual; the score should reflect that tension honestly rather than picking the cleanest narrative.

PROMPT;
	}

	/**
	 * The contact-side schema instructions. Capacity tier options are
	 * admin-configurable; the other four structured fields use fixed
	 * vocabularies matching the field registrar.
	 *
	 * @return string
	 */
	private static function contact_schema_instructions() {
		$capacity_options = self::format_options( FCE_Field_Registrar::capacity_tier_options() );
		$align_options    = self::format_options( array( 'Strong', 'Moderate', 'Weak', 'Unknown' ) );
		$ready_options    = self::format_options( array( 'High', 'Medium', 'Low', 'Unknown' ) );
		$prior_options    = self::format_options( array( 'Yes', 'Possible', 'No', 'Unknown' ) );
		$signals_options  = self::format_options( array( 'Yes', 'No', 'Unknown' ) );
		$confidence_opts  = '"High" | "Medium" | "Low"';

		return <<<SCHEMA
Return your final answer as a JSON object wrapped in <json>...</json> tags. Use exactly these keys:

{
  "individual_capacity_tier": "...",
  "individual_alignment": "...",
  "individual_engagement_readiness": "...",
  "individual_prior_relationship": "...",
  "individual_relevant_signals_present": "...",
  "confidence": "...",
  "narrative": {
    "personal_context": "...",
    "relevant_background": "...",
    "alignment_assessment": "...",
    "recommended_approach": "..."
  }
}

Allowed values:

- individual_capacity_tier: {$capacity_options}
- individual_alignment: {$align_options}
- individual_engagement_readiness: {$ready_options}
- individual_prior_relationship: {$prior_options}
- individual_relevant_signals_present: {$signals_options}
- confidence: {$confidence_opts}

Field meanings:

- **individual_capacity_tier**: the person's tier per the use case defined in the context modules. The vocabulary is set by the requesting organization — for fundraising it's typically Major / Mid / Standard / Unknown (capacity for major-gift consideration); for other use cases it might mean leadership tier, decision-making authority, or another dimension. Use only the listed values; default to "Unknown" if you cannot reasonably determine.
- **individual_alignment**: alignment between this person and the requesting organization's mission per the context modules.
- **individual_engagement_readiness**: current likelihood of receptivity (recent activity, life events, public signals).
- **individual_prior_relationship**: whether there is a known connection between this person and the requesting organization or its mission (alumni, prior gift, board overlap, public alignment, etc.).
- **individual_relevant_signals_present**: confidence flag — Yes if you found verifiable public signals relevant to the use case (giving disclosures for donors, leadership credentials for cohort prep, decision-authority signals for sales); No if you searched thoroughly and didn't; Unknown if you couldn't search effectively.

If you cannot determine a value with reasonable confidence, use "Unknown" — never fabricate. Most contacts are not public figures; "Unknown" is the honest answer for many fields and many people.

The narrative sections should be plain Markdown. Each section is one to three short paragraphs. Cite sources inline in the prose; do not include a separate references list.

- **personal_context**: career, current role, institutional affiliations, public profile. Sourced inline.
- **relevant_background**: context bearing on the use case as defined by the context modules. For donor research: known giving, board service, charitable involvement, public recognition. For cohort prep: leadership experience, prior coursework, current professional challenges. For sales: decision-making authority, organizational role, buying signals. Sourced inline.
- **alignment_assessment**: fit with the requesting organization's mission and use case per the context modules. Name what fits and what does not. Be honest about how strong the alignment evidence actually is.
- **recommended_approach**: practical path to engagement, framed appropriately for the use case. For fundraising: cultivation sequence and timing. For cohort prep: what the program leader should know going in. For sales: who else to engage and what to lead with. For board recruitment: cultivation and vetting path.

When citing sources inside the narrative sections, use Markdown link syntax: `[the cited claim](https://source-url)`. Do NOT use `<cite>` tags inside the JSON string values — those tags don't render in the destination CRM note.

Critical reminder: this research will be reviewed by a human professional. Honest "Unknown" answers, named information gaps, and explicit hedges are more useful than confident fabrications. Apra's accuracy principle is the load-bearing one for this work.
SCHEMA;
	}

	/**
	 * @param object $contact FluentCrm\App\Models\Subscriber instance
	 * @return string
	 */
	private static function build_contact_user_prompt( $contact ) {
		$name_parts = array_filter( array(
			(string) ( $contact->first_name ?? '' ),
			(string) ( $contact->last_name ?? '' ),
		) );
		$full_name  = implode( ' ', $name_parts );

		$lines = array(
			'Research this individual contact and return structured data plus a narrative summary, per the schema in the system prompt.',
			'',
			'Name: ' . ( '' !== $full_name ? $full_name : '(no name on file)' ),
			'Email: ' . (string) $contact->email,
		);

		// Hint with employer context if the contact is linked to a company —
		// helps Claude disambiguate similarly-named individuals.
		if ( ! empty( $contact->company_id ) && class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			$company = \FluentCrm\App\Models\Company::find( (int) $contact->company_id );
			if ( $company ) {
				$lines[] = 'Employer: ' . (string) $company->name;
				if ( ! empty( $company->website ) ) {
					$lines[] = 'Employer website: ' . (string) $company->website;
				}
			}
		}

		// Pass any contact-level context the admin has set (job_title is
		// a common FluentCRM field).
		if ( ! empty( $contact->job_title ) ) {
			$lines[] = 'Title: ' . (string) $contact->job_title;
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
	 * confidence + the 8 mirrored org_* values, in a single createOrUpdate
	 * call so meta serialization and the company_updated action both
	 * fire correctly.
	 *
	 * Caching the org_* values on the company gives us a canonical
	 * source of truth for the organization. Sync-to-contacts operations
	 * read from here rather than picking a "source contact" arbitrarily.
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

		// Mirror the 8 org_* values from the contact-side payload to the
		// company. Same slugs, same string format (multi-select values
		// are comma-joined), so reading the company gives an admin the
		// same picture every linked contact has.
		foreach ( $mapped['contact'] as $slug => $value ) {
			$values[ $slug ] = $value;
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

	// ---------------------------------------------------------------------
	// Contact-side result writers (v0.7.0+)
	// ---------------------------------------------------------------------

	/**
	 * Update the contact's individual_* enrichment fields with the
	 * mapped output values, status=Complete, today's date, and the
	 * confidence value from the response. Single syncCustomFieldValues
	 * call so hooks fire correctly.
	 *
	 * @param object $contact
	 * @param array  $mapped  Result of FCE_Data_Mapper::map_individual()
	 * @return void
	 */
	private static function write_contact( $contact, array $mapped ) {
		$values = array_merge(
			$mapped['individual'],
			array(
				FCE_IND_STATUS     => 'Complete',
				FCE_IND_DATE       => current_time( 'Y-m-d' ),
				FCE_IND_CONFIDENCE => isset( $mapped['confidence'] ) && '' !== $mapped['confidence']
					? $mapped['confidence']
					: 'Low',
			)
		);
		$contact->syncCustomFieldValues( $values, false );
	}

	/**
	 * Flip the contact's individual_enrichment_status. Used for the
	 * Pending → Processing → Complete/Failed/Restricted lifecycle.
	 *
	 * @param object $contact
	 * @param string $status
	 * @return void
	 */
	private static function set_contact_status( $contact, $status ) {
		$contact->syncCustomFieldValues( array( FCE_IND_STATUS => $status ), false );
	}

	/**
	 * Create a "Contact Research — YYYY-MM-DD" subscriber note. Same-day
	 * replacement: if today's research note already exists for this
	 * contact, update in place rather than appending. Cross-day
	 * re-enrichments preserve historical analyses.
	 *
	 * @param object $contact
	 * @param array  $mapped
	 * @param int    $search_count
	 * @return void
	 */
	private static function create_contact_research_note( $contact, array $mapped, $search_count ) {
		$markdown = self::format_contact_note_markdown( $mapped, $search_count );
		$html     = self::markdown_to_html( $markdown );

		$data = array(
			'subscriber_id' => (int) $contact->id,
			'type'          => 'note',
			'title'         => sprintf(
				/* translators: %s: ISO date */
				__( 'Contact Research — %s', 'fluentcrm-contact-enrichment' ),
				current_time( 'Y-m-d' )
			),
			'description'   => $html,
			'created_at'    => current_time( 'mysql' ),
		);

		if ( class_exists( '\\FluentCrm\\App\\Services\\Sanitize' ) ) {
			$data = \FluentCrm\App\Services\Sanitize::contactNote( $data );
		}

		$existing = self::find_todays_contact_note( (int) $contact->id );
		if ( $existing ) {
			$existing->fill( array(
				'description' => $data['description'],
				'created_at'  => $data['created_at'],
			) )->save();
			do_action( 'fluent_crm/subscriber_note_updated', $existing, $contact, $data );
			return;
		}

		$note = \FluentCrm\App\Models\SubscriberNote::create( $data );
		do_action( 'fluent_crm/subscriber_note_added', $note, $contact, $data );
	}

	/**
	 * Look for a "Contact Research — <today>" note on this contact.
	 * Matches by title prefix so manually-added admin notes don't get
	 * mistaken for enrichment output.
	 *
	 * @param int $contact_id
	 * @return object|null
	 */
	private static function find_todays_contact_note( $contact_id ) {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\SubscriberNote' ) ) {
			return null;
		}
		$today_title = sprintf(
			/* translators: %s: ISO date */
			__( 'Contact Research — %s', 'fluentcrm-contact-enrichment' ),
			current_time( 'Y-m-d' )
		);
		return \FluentCrm\App\Models\SubscriberNote::where( 'subscriber_id', $contact_id )
			->where( 'title', $today_title )
			->orderBy( 'id', 'desc' )
			->first();
	}

	/**
	 * @param array $mapped
	 * @param int   $search_count
	 * @return string
	 */
	private static function format_contact_note_markdown( array $mapped, $search_count ) {
		$n = $mapped['narrative'];

		$body  = "## Personal context\n\n" . self::or_placeholder( $n['personal_context'] ) . "\n\n";
		$body .= "## Relevant background\n\n" . self::or_placeholder( $n['relevant_background'] ) . "\n\n";
		$body .= "## Alignment assessment\n\n" . self::or_placeholder( $n['alignment_assessment'] ) . "\n\n";
		$body .= "## Recommended approach\n\n" . self::or_placeholder( $n['recommended_approach'] ) . "\n";

		if ( ! empty( $mapped['dropped'] ) ) {
			$body .= "\n---\n\n*Notes for the field admin: " . count( $mapped['dropped'] ) . ' value(s) returned by Claude were not in the allowed options list and were dropped: ' . implode( '; ', $mapped['dropped'] ) . ".*\n";
		}

		$body .= "\n*Generated using " . (int) $search_count . ' web search' . ( 1 === (int) $search_count ? '' : 'es' ) . ".*\n";

		return $body;
	}

	/**
	 * Failure path for contact enrichment. Mirrors fail() but writes
	 * to the contact's individual_enrichment_status and creates a
	 * subscriber note (not company note).
	 *
	 * @param object $contact
	 * @param string $error
	 * @param string $raw_text  Optional raw response for diagnostics.
	 * @return void
	 */
	private static function fail_contact( $contact, $error, $raw_text = '' ) {
		self::log(
			sprintf( 'contact enrichment failed for contact %d (email=%s): %s', $contact->id, (string) $contact->email, $error )
		);

		self::set_contact_status( $contact, 'Failed' );

		$markdown  = "## Contact enrichment failed\n\n";
		$markdown .= "**Error:** " . $error . "\n\n";
		$markdown .= "Run again from the contact profile when ready.\n";

		if ( '' !== $raw_text ) {
			$markdown .= "\n---\n\n";
			$markdown .= "<details><summary>Raw response</summary>\n\n";
			$markdown .= "```\n" . substr( $raw_text, 0, 4000 ) . "\n```\n";
			$markdown .= "</details>\n";
		}

		$html = self::markdown_to_html( $markdown );

		$data = array(
			'subscriber_id' => (int) $contact->id,
			'type'          => 'note',
			'title'         => sprintf(
				/* translators: %s: ISO date */
				__( 'Contact Enrichment Failed — %s', 'fluentcrm-contact-enrichment' ),
				current_time( 'Y-m-d' )
			),
			'description'   => $html,
			'created_at'    => current_time( 'mysql' ),
		);

		if ( class_exists( '\\FluentCrm\\App\\Services\\Sanitize' ) ) {
			$data = \FluentCrm\App\Services\Sanitize::contactNote( $data );
		}

		try {
			$note = \FluentCrm\App\Models\SubscriberNote::create( $data );
			do_action( 'fluent_crm/subscriber_note_added', $note, $contact, $data );
		} catch ( \Throwable $e ) {
			self::log( 'failed to write contact failure note: ' . $e->getMessage() );
		}
	}

	// ---------------------------------------------------------------------
	// Company-side failure path (kept name `fail` for backward compatibility)
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
