# Field reference

Reference for the FluentCRM custom fields this plugin creates and how they're populated. The companion document is `fluentcrm-enrichment-research.md`, which covers FluentCRM internals and the API mechanics — this one is about the user-facing data shape.

## Pipeline at a glance

```
[Admin clicks "Enrich This Company"]
        │
        ▼
[admin-ajax.php?action=fce_trigger_enrichment]
   • capability + nonce checks
   • company_id custom field: enrichment_status → "Pending"
   • wp_schedule_single_event(fce_run_enrichment_job, +5s)
        │
        ▼
[WP-Cron fires fce_run_enrichment_job(company_id)]
   • company custom field: enrichment_status → "Processing"
   • build system prompt:
       research-discipline preamble
       + admin's active context modules
       + schema instructions (allowed-options lists from registrar)
   • build user prompt: company name, website, industry hint
   • POST https://api.anthropic.com/v1/messages
       model: claude-sonnet-4-6 (or admin choice)
       tools: [{type: "web_search_20250305", max_uses: 8}]
        │
        ▼
[Parse and validate]
   • extract JSON object from response (wrapper tag → fence → balanced braces)
   • map each field against allowed options
       single-selects: validate or fall back
       multi-selects: intersect with allowed list, drop unknowns
   • strip stray <cite> tags from narrative strings
        │
        ▼
[Write company]
   • enrichment_status → "Complete"
   • enrichment_date → today (Y-m-d)
   • enrichment_confidence → from response
        │
        ▼
[Write CompanyNote]
   • title: "Enrichment Research — YYYY-MM-DD"
   • description: 4-section Markdown → HTML via Parsedown
   • same-day replace: if a note with today's title exists,
     update in place (no duplicate notes from re-clicking)
        │
        ▼
[Push to linked contacts]
   • Subscriber::where('company_id', N)->get()
   • for each: syncCustomFieldValues($org_profile_values, deleteOtherValues: false)
   • 8 contact fields populated per linked contact
```

If anything in the pipeline fails: status flips to `"Failed"`, a clearly-titled error note is written to the company, contacts are not touched.

## Contact research pipeline (separate from company research)

```
[Admin clicks "Enrich This Contact" on contact profile]
        │
        ▼
[admin-ajax.php?action=fce_trigger_contact_enrichment]
   • capability + nonce checks
   • contact custom field: individual_enrichment_status → "Pending"
   • wp_schedule_single_event(fce_run_contact_enrichment_job, +5s)
        │
        ▼
[WP-Cron fires fce_run_contact_enrichment_job(contact_id)]
   • CONSENT GATE: if individual_research_consent = Restricted →
     status = Restricted, return without API call
   • status → "Processing"
   • build system prompt:
       Apra-grounded research discipline (relevance gate)
       + admin's active contact context modules
       + schema instructions (admin-configurable capacity tier values)
   • build user prompt: contact name, email, employer hint, title
   • POST https://api.anthropic.com/v1/messages
        │
        ▼
[Parse and validate]
   • extract JSON object from response
   • validate each individual_* field against allowed options
   • strip stray <cite> tags
        │
        ▼
[Write contact]
   • individual_enrichment_status → "Complete"
   • individual_enrichment_date → today
   • individual_enrichment_confidence → from response
   • 5 output fields populated
        │
        ▼
[Write SubscriberNote]
   • title: "Contact Research — YYYY-MM-DD"
   • description: 4-section Markdown → HTML via Parsedown
   • same-day replace; cross-day appends
```

If anything fails: `individual_enrichment_status` flips to "Failed" and a clearly-titled "Contact Enrichment Failed" subscriber note is created.

## Sync paths (no API call)

Two additional surfaces push the company-side cache to contacts without running a fresh enrichment:

- **Per-company "Sync to Contacts"** button on the company profile section (visible when status is Complete). Reads the company's cached org_* values and overwrites the matching contact custom fields on every contact whose primary `company_id` matches. Use when a contact gets attached to an already-enriched company or when contact values have drifted.
- **Bulk "Resync all contacts"** Danger Zone in Settings → Contact Enrichment. Walks every company that has cached enrichment values and runs the per-company sync on each. Typed RESYNC confirmation, synchronous, summary count on completion. Use to repair drift across many companies after a bulk data event.

Both paths use the same code (`FCE_Contact_Sync`) and convert the company-side multi-select array format (`['National']`) to the comma-joined string format contacts expect (`'National'`).

---

## Company fields

The plugin defines two sets of fields on the company side:

1. **Enrichment status fields** (group: "Enrichment") — `enrichment_status`, `enrichment_date`, `enrichment_confidence`. These are unique to the company.
2. **Cached org_* fields** (groups: "Enrichment — Org Profile" and "Enrichment — Alignment") — the same 8 fields that live on contacts, mirrored to the company so the company has the canonical record of what was last decided about it.

The cached fields exist as of v0.4.0 to make the company the canonical source of truth for organizational data. Sync operations and "what does this company look like" reads should go to the company record, not to a representative contact.

**Note on multi-select format:** company-side multi-select values are stored as PHP arrays (`['National', 'International']`) inside the company's serialized `meta.custom_values`. Contact-side multi-select values are stored as comma-joined strings (`"National, International"`). Same logical content, different storage format — a quirk of FluentCRM's write paths. See CLAUDE.md for the rationale.

Group: **Enrichment** (visible on the FluentCRM company profile view)

### `enrichment_status`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Not Enriched, Pending, Processing, Complete, Failed |
| Set by | The plugin (every step of the pipeline writes this) |
| Default for new companies | absent — the field renders as "Not Enriched" by convention |

Lifecycle:

- **Not Enriched** — companies that have never been enriched. The field may simply be empty.
- **Pending** — set by the admin-ajax handler the moment the user clicks Enrich. The cron job hasn't run yet.
- **Processing** — set by the cron job before the Claude API call. Visible in this state for ~30–40s while web search and reasoning happen.
- **Complete** — set on success. The accompanying date and confidence fields are populated, and a research note exists.
- **Failed** — set on any error path. An error note is added to the company describing the failure.

Re-clicking Enrich at any point flips the status back to Pending and re-runs.

### `enrichment_date`

| | |
|---|---|
| Type | `date` |
| Format | `YYYY-MM-DD` (ISO date) |
| Set by | The plugin on `enrichment_status = Complete` |

Records the *most recent* successful enrichment. Re-enriching overwrites this. To track a history of enrichments, look at the company's notes — every successful run leaves a research note timestamped via FluentCRM's `created_at`.

### `enrichment_confidence`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | High, Medium, Low |
| Set by | Claude (validated against the allowed list before writing) |
| Fallback | "Low" when the response is missing or the value isn't in the allowed list |

Claude's self-reported confidence in the structured fields. Treat this as a hint, not a verdict — the model can be confident and wrong. The four narrative sections in the research note typically explain the basis (or limits) of that confidence.

---

## Contact fields

These fields are written to **every contact whose primary `company_id` matches the company being enriched**. Contacts associated only via the many-to-many pivot table (`fc_subscriber_pivot`) are not touched — by design, to avoid double-writing for contacts with multiple company associations.

### Group: Enrichment — Org Profile

These describe the contact's *employing organization*, not the contact themselves. A contact at Microsoft and a contact at a 5-person nonprofit have very different organizational contexts; these fields make that visible for segmentation, prioritization, and outreach planning.

### `org_type`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Corporation, SMB, Nonprofit, Foundation, Government, Association, Other |
| Fallback | "Other" |

The kind of organization. "SMB" covers small/medium for-profit businesses (under ~200 employees); "Corporation" covers larger publicly-known for-profits. Distinguishing Foundation from Nonprofit matters for funding-flow segmentation: a foundation is typically a grantmaker, a nonprofit is typically a grant-receiver.

### `org_sector`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | FluentCRM's canonical 147-item industry list (sourced live from `\FluentCrm\App\Services\Helper::companyCategories()`) |
| Fallback | empty (no value written if Claude can't pick from the list) |
| Source | **Derived** from the native `industry` value populated via `native_fields.linkedin_industry` — same vocabulary, guaranteed consistent |

Industry vertical, sharing FluentCRM's company-profile industry vocabulary. Lives on the contact (rather than only on the company) because FluentCRM's segment builder only sees contact custom fields — duplicating to contacts makes the value segmentable in dynamic segments and automation conditions.

The 147 categories are LinkedIn-style and granular ("Higher Education" vs "Education Management" vs "E-Learning"). For broader-brush segmentation, segment on multiple values together (e.g. `org_sector IN ("Higher Education", "Education Management", "E-Learning", "Professional Training & Coaching")`).

When the field's option list changes (a future FluentCRM update could expand the list), the heal pass on plugin reactivation rewrites the field definition to match. Stored values that fall out of the new list are cleared so they don't display as invalid in the FluentCRM UI; re-enrichment refills them.

### `org_employees`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | 1–10, 11–50, 51–200, 201–1000, 1001–5000, 5000+, Unknown |
| Fallback | "Unknown" |

Headcount bucket. Useful proxy for organizational scale and decision-making complexity. Real headcount is rarely public — Claude estimates from team pages, LinkedIn-visible data, and other public signal. Segment on the *bucket*, not on the precision.

### `org_revenue`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | <$1M, $1–10M, $10–100M, $100M–$1B, $1B+, Unknown |
| Fallback | "Unknown" |

Annual revenue bucket. For nonprofits, this typically comes from 990 filings (1–2 years lagged); for private companies it's often genuinely unknowable from public sources, in which case Unknown is the honest answer. The narrative section will usually note when the value is self-reported or inferred.

### `org_geo_scope`

| | |
|---|---|
| Type | `select-multi` (stored as comma-joined string per FluentCRM convention) |
| Allowed values | Local, Regional, National, International |
| Fallback | empty (no values written if Claude returns nothing valid) |

Where the organization operates. Multi-select because organizations often span scopes (a Local nonprofit with one National program, for example). Use for geographic segmentation and for matching organizations to opportunities with geographic constraints.

### `org_focus_areas`

| | |
|---|---|
| Type | `select-multi` |
| Allowed values | **Configurable** in Settings → Contact Enrichment → Focus Areas |
| Default options (12) | Environment, Conservation, Community Development, Education, Health, Water & Sanitation, Food Security, Economic Development, Arts & Culture, Animal Welfare, Human Rights, Disaster Relief |

Mission/issue areas the organization works on. The option list is admin-controlled because every organization has different relevant focus areas — the defaults are appropriate for most nonprofit-adjacent enrichment but can be reshaped to fit any vertical.

When the admin edits the focus-area list in settings, the field definition's `options` array is updated immediately via `FCE_Field_Registrar::sync_focus_area_options()`. Existing values stored on contacts are not touched, so renaming an option does not retroactively rewrite contact records.

### `org_partnership_models`

| | |
|---|---|
| Type | `select-multi` |
| Allowed values | Donation, Cause Marketing, Sponsorship, Grant, In-Kind, Corporate Foundation, Other |
| Fallback | "Other" if Claude returns one unrecognized value; otherwise empty |

How the organization tends to partner. A foundation's value here is typically Grant + Corporate Foundation; a corporation's might be Donation + Cause Marketing + Sponsorship; a nonprofit's depends on whether you're looking at it as a partner-recipient or partner-giver. Use for matching prospects to your own partnership offerings.

### Group: Enrichment — Alignment

### `org_alignment_score`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Strong, Moderate, Weak, Unknown |
| Fallback | "Unknown" |

Claude's read on alignment between the target organization and the priorities you've described in your context modules. **This is the most opinionated field — what "alignment" means is entirely defined by what you put in your context modules.** Without context modules, alignment is just generic mission-fit; with detailed context modules, alignment reflects your specific criteria.

The narrative note's "Alignment assessment" section explains the reasoning behind the score and is more useful than the score alone. Treat the score as a sort key for prospect lists, not a verdict.

---

## Individual contact research fields (v0.7.0+)

A second set of contact fields, written by the contact-side individual research surface. These describe the *person*, not their employer — used for fundraising prospect research, cohort program participant prep, B2B sales / partnership stakeholder research, board recruitment, etc.

The use case is determined by the admin's **contact context modules** (Settings → Contact Context). Without context modules, the research operates generically; with them, the research is shaped to the use case.

Research is grounded in [Apra's Statement of Ethics](https://www.aprahome.org/Resources/Statement-of-Ethics) — restricted to information bearing on the relationship the requesting organization is trying to build, sourced from public records and verified professional context, never aggregated beyond what the relationship justifies.

### Group: Enrichment — Individual Status

### `individual_enrichment_status`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Not Enriched, Pending, Processing, Complete, Failed, Restricted |

Set by the plugin throughout the enrichment lifecycle. The "Restricted" value is unique to the contact-side surface — set automatically when an enrichment is attempted on a contact whose `individual_research_consent` is "Restricted."

### `individual_enrichment_date`

| | |
|---|---|
| Type | `date` |
| Format | `YYYY-MM-DD` |

Date of last successful contact enrichment. Re-enriching overwrites.

### `individual_enrichment_confidence`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | High, Medium, Low |
| Fallback | "Low" |

Claude's self-reported confidence in the structured outputs. Individual research has thinner sources than org research, so confidence will skew lower in many cases — that's appropriate. Treat as a hint, not a verdict.

### `individual_research_consent`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Allowed, Restricted |
| Default for new contacts | Allowed (matches practitioner standard for fundraising) |

Per-contact opt-out. Admin-set. When "Restricted," the cron job short-circuits before any API call is made; status flips to "Restricted" and no fields, narrative, or notes are written.

This field is the gate for individual research. It does not affect company-side enrichment, since that researches the contact's *employer*, not the contact themselves. To prevent any research touching a contact, restrict consent here AND ensure they have no `company_id` (or accept that their employer's enrichment will mirror to them).

### Group: Enrichment — Individual

### `individual_capacity_tier`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | **Admin-configurable** (Settings → Capacity Tiers) |
| Default options | Major, Mid, Standard, Unknown |
| Fallback | "Unknown" |

The contact's tier per the use case the admin defines in their context modules. Default values are donor-flavored; admins running other use cases can rewrite them:

- **Fundraising** (default): Major / Mid / Standard / Unknown
- **Cohort programs**: Senior Leader / Mid-Career / Emerging / Unknown
- **B2B sales**: Decision Maker / Influencer / End User / Unknown
- **Board recruitment**: a relevant governance experience tier
- **Other use cases**: whatever fits

Order matters: the system prompt instructs Claude to treat the first value as the highest tier and the last as the lowest. Always include "Unknown" (or equivalent fallback) as the final value.

### `individual_alignment`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Strong, Moderate, Weak, Unknown |
| Fallback | "Unknown" |

Alignment between this person and the requesting organization's mission per the contact context modules. Same opinionated nature as `org_alignment_score` — what "alignment" means is entirely defined by what's in the context modules.

### `individual_engagement_readiness`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | High, Medium, Low, Unknown |
| Fallback | "Unknown" |

Current likelihood of receptivity to engagement. Signals that bump this up: recent public activity related to the use case, life events (book launch, retirement, board departure), known recent giving (for fundraising), recently published content (for sales prospecting).

### `individual_prior_relationship`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Yes, Possible, No, Unknown |
| Fallback | "Unknown" |

Whether the person has a known prior connection to the requesting organization or its mission — alumni status, prior gift, board overlap, public alignment with the cause, etc. The context modules tell Claude what the requesting organization is, so this only works well when those modules are populated.

### `individual_relevant_signals_present`

| | |
|---|---|
| Type | `select-one` |
| Allowed values | Yes, No, Unknown |
| Fallback | "Unknown" |

Confidence flag, not a content claim. "Yes" if Claude found verifiable public signals relevant to the use case (giving disclosures for donors, leadership credentials for cohort prep, decision-authority signals for sales). "No" if it searched thoroughly and didn't. "Unknown" if it couldn't search effectively (rare).

Useful for filtering: "show me all contacts where research found something" vs. "contacts where research came up empty."

---

## Lookup fields (v0.9.0+) — inject existing data into the prompt

The plugin can include the values of admin-selected FluentCRM custom fields in every enrichment prompt as "existing data on file." Configured in the Company Context and Contact Context settings tabs.

This is meaningful for fundraising research and any other use case where the requesting organization holds factual signal that's stronger than what Claude can find publicly: giving totals from external systems, WooCommerce purchase history, course completion records, partnership history, pledge data. With injection, Claude treats those values as given facts and grounds the structured field outputs and narrative on top of them.

### How the picker works

Both context tabs include a picker section listing every eligible FluentCRM custom field for that surface. Eligible means: a real FluentCRM custom field that's *not* one of the plugin's own enrichment-output fields. Plugin-managed slugs (the `org_*` mirrors, the `individual_*` outputs, the `enrichment_*` status fields) are deliberately excluded — including them would create feedback loops where prior enrichments anchor subsequent runs.

Fields are grouped by FluentCRM's field-group label so they're organized the same way they appear elsewhere in FluentCRM. The picker shows the human label, slug, and field type. Selection is per-surface — you can pick different fields for company research and contact research.

### What the prompt looks like

When at least one field is selected and has a value on the record being enriched, the user prompt includes a section like:

```
## Existing data on file (treat as given facts)

These values come from the requesting organization's own records and should be treated as given. You don't need to verify them. Use them to ground your research and inform the structured field outputs and narrative.

- **Total Order Value:** 166.39
- **Total Order Count:** 29
- **Current Year Giving:** 43.92
- **Previous Year Giving:** 122.47
```

Empty values are skipped silently. If no selected field has a value on this record, the section is omitted entirely.

### Where the prompt discipline is updated

Both system prompts (company and contact) acknowledge the section's existence and instruct Claude to:

- Treat the values as given facts (no verification effort)
- Use them as inputs to structured fields, especially capacity tier and prior relationship
- Cite them in the narrative with attribution like "the requesting organization's records show…"

The contact-side preamble specifically calls out that injected data typically holds stronger signal than anything findable publicly, because individual research has thinner public sources than organizational research.

## Native FluentCRM company fields (filled if empty)

In addition to the custom fields above, enrichment also populates FluentCRM's *built-in* company columns when they're currently empty. **The plugin never overwrites admin-curated values** — if any of these columns is already set on the company record, the enrichment leaves it alone, regardless of what Claude returned. The success note's footer lists which native fields were filled.

| Column | Type | Source from Claude | Notes |
|---|---|---|---|
| `industry` | enum (147 LinkedIn-style values) | `native_fields.linkedin_industry` | Validated against `\FluentCrm\App\Services\Helper::companyCategories()`. If Claude returns a value that isn't in the list, it's dropped (the field stays empty). |
| `description` | text | `native_fields.description` | 1–2 sentence neutral summary. `<cite>` tags stripped defensively. |
| `website` | URL | `native_fields.website` | The organization's primary website. Note: when website is *passed in* to enrichment as a starting hint, that value is already populated; this fills the column for company records that started with no website. Validated like other URLs. |
| `address_line_1`, `address_line_2`, `city`, `state`, `postal_code`, `country` | text | `native_fields.headquarters` | Each sub-field is filled independently, so partial addresses (city + country only) are valid. Empty sub-fields are skipped. |
| `linkedin_url`, `facebook_url`, `twitter_url` | URL | `native_fields.linkedin_url`, `.facebook_url`, `.twitter_url` | Validated with `filter_var(... FILTER_VALIDATE_URL)`. Invalid URLs are dropped to the diagnostics log. |
| `employees_number` | integer | derived from the `org_employees` bucket | Bucket midpoint: 1–10 → 5, 11–50 → 30, 51–200 → 125, 201–1000 → 600, 1001–5000 → 3000, 5000+ → 7500. "Unknown" omits the field. |

The fill-if-empty rule means: **enrichment is additive, never destructive.** If you've manually set `industry = "Education"` and Claude later determines the organization is in "Higher Education," your value stays. If you want enrichment to update a field, clear the field first, then re-enrich.

The fill-if-empty check happens *after* Claude's response is parsed and validated — so you'll see "linkedin_industry" mentioned in the dropped log if Claude returned an invalid value, separate from whether the column was actually written.

## Custom field storage — quick reference

For anyone debugging or writing reports against the database directly:

| Where stored | Mechanism |
|---|---|
| Contact field **definitions** | WP option `_fluentcrm_contact_custom_fields` (a serialized array of field defs). Read via `fluentcrm_get_custom_contact_fields()`. |
| Company field **definitions** | WP option `_fluentcrm_company_custom_fields`. Read via `(new FluentCrm\App\Models\CustomCompanyField())->getGlobalFields()`. |
| Contact field **values** | Table `wp_fc_subscriber_meta` — one row per (subscriber_id, key) where `object_type = 'custom_field'`. |
| Company field **values** | Column `wp_fc_companies.meta` — a serialized PHP array containing `custom_values`. |
| Plugin notes | Table `wp_fc_subscriber_notes` — one row per note. Company notes have `status = '_company_note_'` and use the `subscriber_id` column to hold the company ID. |

To read a contact's enrichment fields:

```sql
SELECT `key`, `value`
  FROM wp_fc_subscriber_meta
 WHERE subscriber_id = ?
   AND object_type = 'custom_field'
   AND `key` IN ('org_type','org_sector','org_employees','org_revenue',
                 'org_geo_scope','org_focus_areas','org_partnership_models',
                 'org_alignment_score');
```

Multi-select values are stored as comma-joined strings (`'Education, Health'`) — that's FluentCRM's internal convention, and `formatCustomFieldValues()` converts back to arrays on read for any consumer that expects an array.

To read a company's enrichment fields, you must unserialize:

```php
$row = $wpdb->get_row("SELECT meta FROM wp_fc_companies WHERE id = $id");
$meta = maybe_unserialize($row->meta);
$status = $meta['custom_values']['enrichment_status'] ?? null;
```

Or use the model: `\FluentCrm\App\Models\Company::find($id)->meta['custom_values']`.

---

## Segmenting on enrichment fields

The contact-side enrichment fields surface in FluentCRM's segment builder under the contact's custom fields, so you can build dynamic segments like:

- "Contacts at Foundations with Strong alignment" → `org_type = Foundation` AND `org_alignment_score = Strong`
- "Contacts at International nonprofits in Education" → `org_geo_scope contains International` AND `org_type = Nonprofit` AND `org_focus_areas contains Education`
- "Contacts at organizations with Grant or Corporate Foundation partnership models" → `org_partnership_models contains Grant` OR `org_partnership_models contains Corporate Foundation`

The company-side fields (`enrichment_status`, `enrichment_date`, `enrichment_confidence`) live on the company record and are visible on the company profile but **do not appear in FluentCRM's segment builder**. FluentCRM does not currently expose extension points for company-level segment conditions, so segmenting by company status requires either a custom report or working from the contacts of those companies.

---

## What enrichment does *not* do

- Does not modify any FluentCRM table schema.
- Does not write to any field outside the eleven custom fields and the native company columns listed above.
- Does not overwrite admin-curated native company values. Industry, Description, Address, Social Links, and Number of Employees are filled only when empty.
- Does not store the raw Claude API response anywhere — only the parsed and validated structured fields plus the narrative note.
- Does not retain the system prompt or user prompt across runs. Each enrichment is an independent call.
- Does not enrich contacts whose primary `company_id` doesn't match the company being enriched. Pivot-only associations are out of scope by design.
