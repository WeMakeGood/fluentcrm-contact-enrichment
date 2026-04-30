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

---

## Company fields

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
| Allowed values | Environment / Conservation, Education, Health, Arts & Culture, Technology, Finance, Retail / Consumer, Real Estate, Professional Services, Other |
| Fallback | "Other" |

Industry vertical. The list is intentionally short — these are the segments most useful for funder/partner/recipient categorization, not a comprehensive industry taxonomy.

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
- Does not write to any field outside the eleven listed above.
- Does not store the raw Claude API response anywhere — only the parsed and validated structured fields plus the narrative note.
- Does not retain the system prompt or user prompt across runs. Each enrichment is an independent call.
- Does not enrich contacts whose primary `company_id` doesn't match the company being enriched. Pivot-only associations are out of scope by design.
