# FluentCRM enrichment research

Investigation conducted against FluentCRM (free, version detected at `wp-content/plugins/fluent-crm/`) and the Anthropic Messages API. The purpose was to verify, before any code is written, that every internal API the spec relies on actually exists and behaves the way the spec assumes — and to surface mismatches with the spec language so the implementation can target the real APIs rather than the imagined ones.

## TL;DR — corrections to the spec

The spec is mostly right, but a few details would have produced runtime errors if implemented literally. None of these change the architecture; they change which functions/methods the plugin calls.

1. **Custom field definitions are not Eloquent records.** The verification command `FluentCrm\App\Models\CustomContactField::all()` will fatal — the class has no Eloquent base. It's a thin wrapper around the WP option `contact_custom_fields` (`fluent-crm/app/Models/CustomContactField.php`). Use `(new CustomContactField())->getGlobalFields()` or `fluentcrm_get_custom_contact_fields()` for verification, and `saveGlobalFields()` to write.
2. **Field-type slugs are different from the spec's labels.** The spec uses "Select", "Multi-select", "Date" — the actual type strings are `single-select`, `multi-select`, `date`. Full set: `text`, `textarea`, `number`, `single-select`, `multi-select`, `radio`, `checkbox`, `date`, `date_time`.
3. **Company custom-field values are stored in a serialized `meta` blob, not a meta table.** The `CustomCompanyField` *definition* class shares storage shape with `CustomContactField` (a `company_custom_fields` WP option). But company *values* live inside `wp_fc_companies.meta` under the key `custom_values` — written via `FluentCrmApi('companies')->createOrUpdate(['id' => ..., 'name' => ..., 'custom_values' => [...]])`. There is **no per-field update API on the Company model** equivalent to `Subscriber::syncCustomFieldValues()`.
4. **There is no `addCompanyProfileSection` on `extend`.** The registered API key is `extender` (the spec text uses `'extend'`). The proxy returns an `FCApi` wrapper that swallows exceptions — calls to wrong keys return `null` silently rather than warning. The signature is `addCompanyProfileSection($key, $sectionTitle, $callback, $saveCallback = null)` and the section render callback returns `['heading' => string, 'content_html' => string]`.
5. **Notes use a single shared `fc_subscriber_notes` table for both subscribers and companies.** A `_company_note_` status flag distinguishes them. The Eloquent model is `CompanyNote`; the column `subscriber_id` actually holds the company ID for company notes. The legal `type` values come from `fluentcrm_activity_types()` — `note`, `call`, `email`, `meeting`, etc. We use `note`.
6. **Web search tool versioning.** The spec says "check Anthropic API docs for the current tool use format" — the current version string for our use is `web_search_20250305` (the 2026-02 dynamic-filtering variant `web_search_20260209` requires the code-execution tool to also be enabled, which isn't appropriate for this plugin's use case). The tool name is the literal string `web_search`.

## Custom field definitions

### Where they live

Definitions are stored as a single WP option, **not a database table**:

| Field set | Option key | Class |
|---|---|---|
| Contact custom fields | `_fluentcrm_contact_custom_fields` (via `fluentcrm_get_option('contact_custom_fields')`) | `FluentCrm\App\Models\CustomContactField` |
| Company custom fields | `_fluentcrm_company_custom_fields` (via `fluentcrm_get_option('company_custom_fields')`) | `FluentCrm\App\Models\CustomCompanyField` (extends contact) |

`CustomCompanyField` inherits all behavior from `CustomContactField` — same `saveGlobalFields()`, same `getGlobalFields()`, same field-type vocabulary. Only the option name and the default group label differ.

### Field definition shape

A single field definition is an associative array:

```php
[
  'slug'    => 'org_alignment_score',          // unique identifier; auto-generated from label if omitted
  'label'   => 'Alignment Score',              // human label
  'type'    => 'single-select',                // one of: text, textarea, number,
                                               // single-select, multi-select, radio,
                                               // checkbox, date, date_time
  'group'   => 'Enrichment — Alignment',       // optional grouping
  'options' => ['Strong', 'Moderate',          // required for select/radio/checkbox types
                'Weak', 'Unknown'],            // flat array of strings
]
```

Important: for `single-select` and `multi-select`, options are stored as a flat array. When rendered, FluentCRM does `array_combine($options, $options)` (`PrefFormHandler.php:751,760`) — so the *value stored on a contact* is the option string itself, not an index or slug.

### Creation API

Both contact and company use the same upsert pattern:

```php
$model  = new CustomContactField();          // or CustomCompanyField()
$global = $model->getGlobalFields();         // [ 'fields' => [ ...definitions... ] ]
$existingSlugs = wp_list_pluck($global['fields'], 'slug');

$desired = [ /* full list of plugin-managed fields, ours plus everything already there */ ];
$merged  = $global['fields'];
foreach ($desired as $candidate) {
    if (!in_array($candidate['slug'], $existingSlugs, true)) {
        $merged[] = $candidate;
    }
}
$model->saveGlobalFields($merged);            // overwrites the entire option
```

`saveGlobalFields()` deduplicates by slug and overwrites the whole option, so we **must** read first, append our missing fields, and write the merged set. Writing only our fields would erase any existing fields the admin had created.

### Field types we use

| Plugin field | FluentCRM type | Notes |
|---|---|---|
| `enrichment_status` | `single-select` | Options: Not Enriched, Pending, Processing, Complete, Failed |
| `enrichment_date` | `date` | `value_type: date` |
| `enrichment_confidence` | `single-select` | High, Medium, Low |
| `org_type` | `single-select` | Spec list |
| `org_sector` | `single-select` | Spec list |
| `org_employees` | `single-select` | Spec list |
| `org_revenue` | `single-select` | Spec list |
| `org_geo_scope` | `multi-select` | Local, Regional, National, International |
| `org_focus_areas` | `multi-select` | Admin-configurable; loaded from `fce_focus_area_options` at field-creation time |
| `org_partnership_models` | `multi-select` | Spec list |
| `org_alignment_score` | `single-select` | Strong, Moderate, Weak, Unknown |

> **Note on `org_focus_areas`:** because field definitions are written at plugin activation, changing the focus-area option list later requires re-saving the field. The admin "Focus Areas" tab needs to update both `fce_focus_area_options` and the field definition's `options` array on save.

## Custom field values — write paths

### Subscriber (contact) values — canonical write

`FluentCrm\App\Models\Subscriber::syncCustomFieldValues($values, $deleteOtherValues = true)` — `app/Models/Subscriber.php:621`.

```php
$subscriber->syncCustomFieldValues([
    'org_type'             => 'Foundation',
    'org_alignment_score'  => 'Strong',
    'org_focus_areas'      => ['Education', 'Health'],
], false);  // false: don't delete other custom fields
```

What it does:

- Writes to `wp_fc_subscriber_meta` with `object_type = 'custom_field'`, one row per field.
- Skips updates when the existing value already matches.
- Fires both `fluentcrm_contact_custom_data_updated` and `fluent_crm/contact_custom_data_updated` actions when any value actually changes.
- Returns the diff (only changed fields).

**Pass `false` for `$deleteOtherValues`.** The default `true` would wipe every contact custom field absent from `$values` — fine for full-form saves, catastrophic for partial enrichment writes.

For multi-select arrays, the value stored is a comma-joined string by default unless `formatCustomFieldValues()` is called first (which detects array-typed fields by their definition and converts strings ⇄ arrays). FluentCRM does this for you in the Contacts API path; for direct `syncCustomFieldValues()` calls we must pass already-formatted values. Inspect FluentCRM's own behavior: it stores the raw value passed in. To be safe and consistent, store multi-select values as comma-joined strings — that's what FluentCRM does internally when the value originates from a form, and `formatCustomFieldValues()` will re-array-ify it on read for any consumer that expects an array.

### Company values — canonical write

`FluentCrmApi('companies')->createOrUpdate($data)` — `app/Api/Classes/Companies.php:50`.

```php
FluentCrmApi('companies')->createOrUpdate([
    'id'            => $company->id,
    'name'          => $company->name,         // required even on update
    'custom_values' => [
        'enrichment_status'     => 'Complete',
        'enrichment_date'       => '2026-04-30',
        'enrichment_confidence' => 'High',
    ],
]);
```

What it does:

- Loads existing company by `id` (or `name` if no `id`).
- Merges `custom_values` into `meta.custom_values` (does not delete absent keys).
- Calls `(new CustomCompanyField())->formatCustomFieldValues($values)` to normalize types.
- Fires `fluent_crm/company_updated`.

There is **no equivalent of `syncCustomFieldValues()` on Company**. The `meta` column is a serialized PHP array with a single `custom_values` key. Writing directly via `$company->meta = [...]` works (`setMetaAttribute` serializes), but skips the `formatCustomFieldValues()` normalization and the `fluent_crm/company_updated` hook — go through `createOrUpdate()`.

## Notes — write path

### Storage

Single table `wp_fc_subscriber_notes` for both contact and company notes. The discriminator is `status`:

- Subscriber notes: `status` is the activity sub-type (`note`, `call`, `email`, …) — **but** `SubscriberNote::boot()` adds a global scope filter that excludes rows where `status = '_company_note_'`.
- Company notes: `status` is hardcoded to `'_company_note_'`. `CompanyNote::boot()` scopes its queries to that value.

The column **`subscriber_id` holds the company ID** for company notes (the column was reused rather than renamed). Don't be misled by the name.

### Creation

```php
use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Services\Sanitize;

$noteData = Sanitize::contactNote([
    'subscriber_id' => $company->id,
    'type'          => 'note',                   // legal values from fluentcrm_activity_types()
    'title'         => 'Enrichment Research — 2026-04-30',
    'description'   => $markdownNarrative,
    'created_at'    => current_time('mysql'),
]);

$note = CompanyNote::create($noteData);
do_action('fluent_crm/company_note_added', $note, $company, $noteData);
```

The model boot method auto-stamps `status = '_company_note_'`, `updated_at`, and `created_by`. We just need to pass valid fillable fields. Firing `fluent_crm/company_note_added` matches what `CompanyController::addNote()` does so any other plugins observing notes get the event.

## Subscribers linked to a company

The spec asks "find all subscribers where `company_id` = this company's ID." The verified path:

```php
use FluentCrm\App\Models\Subscriber;

$contacts = Subscriber::where('company_id', $companyId)->get();
foreach ($contacts as $contact) {
    $contact->syncCustomFieldValues($enrichmentValues, false);
}
```

This uses the **primary** `company_id` on `wp_fc_subscribers` — the same relationship the rollups plugin uses for rollups. There's also a many-to-many pivot (`wp_fc_subscriber_pivot`, `object_type = 'FluentCrm\App\Models\Company'`) for additional company associations; the spec doesn't ask us to push to those, and the rollups plugin documented (and accepted) the same scope choice. Recommend we mirror that decision and write only to primary-company contacts. This needs explicit confirmation in the user-facing settings or readme so admins aren't surprised.

## Company profile section — Extender API

Verified signature in `app/Api/Classes/Extender.php:53`:

```php
FluentCrmApi('extender')->addCompanyProfileSection(
    'fce_enrichment',                              // unique key
    __('Enrichment', 'fluentcrm-contact-enrichment'),
    function ($content, $company) {                // render callback; $company is hydrated Company model
        return [
            'heading'      => __('Enrichment', 'fluentcrm-contact-enrichment'),
            'content_html' => '...html...',         // status display + Enrich button + last note link
        ];
    }
);
```

Quirks (documented in the rollups plugin's notes, re-verified):

- API key is `extender`, not `extend`. Wrong key throws an exception that the `FCApi` proxy swallows silently.
- `FluentCrmApi('extender')` returns an `FCApi` proxy, not the `Extender` class. `method_exists($x, 'addCompanyProfileSection')` returns `false` even when the proxied method works. Don't gate on `method_exists()`.
- The proxy catches exceptions in `__call` and returns `null`. A failed call is indistinguishable from a successful call returning `null`. Wrap in try/catch and assume success unless we have proof otherwise.

### Triggering enrichment from the section

The Enrich button fires admin-ajax (`wp_ajax_fce_trigger_enrichment`). Because section content is rendered as HTML inside FluentCRM's Vue admin, the click handler needs to be inline JavaScript bundled into `content_html` — there's no script-enqueue hook for this section. Approach: emit a `<button>` with a `data-company-id` attribute, plus a `<script>` block that wires the click via vanilla `fetch()` to admin-ajax with `wpnonce`. Confirm WP-Cron event scheduling status in the response so the button can flip to "Queued."

## Background job — WP-Cron

Single-event scheduling pattern:

```php
// Schedule (in the ajax handler)
wp_schedule_single_event(
    time() + 5,                          // small delay to let the response return
    'fce_run_enrichment_job',
    [$companyId]
);

// Handler (in plugin bootstrap)
add_action('fce_run_enrichment_job', 'fce_run_enrichment_job', 10, 1);
```

Notes:

- WP-Cron events are de-duplicated by `(hook, args, time-bucket)`. Multiple "Enrich" clicks within a few seconds will collapse into one event — desirable.
- Local-only sites without traffic won't fire WP-Cron until a request hits the site. The verification step `wp cron event run fce_run_enrichment_job` handles this in dev.
- For production reliability, the plugin readme should recommend a server-cron `wp cron event run --due-now` job, but that's documentation, not implementation.

## Anthropic API — web search tool use

Verified against [docs.anthropic.com/.../web-search-tool](https://platform.claude.com/docs/en/agents-and-tools/tool-use/web-search-tool).

### Tool versions

- `web_search_20250305` — basic; works on Sonnet 4.6 and other current models. Supports `max_uses`, `allowed_domains`, `blocked_domains`, `user_location`. **This is what we use.**
- `web_search_20260209` — adds dynamic filtering, but **requires the code-execution tool to also be enabled**. Heavier setup, no benefit for our use case.

### Request shape

```json
{
  "model": "claude-sonnet-4-6",
  "max_tokens": 4096,
  "system": "You are an organizational research analyst. ...",
  "messages": [
    {"role": "user", "content": "Research this organization and return JSON. ..."}
  ],
  "tools": [
    {
      "type": "web_search_20250305",
      "name": "web_search",
      "max_uses": 8
    }
  ]
}
```

- **No `tool_choice`** — the model decides when to search.
- **`max_uses`**: cap the search count. For an org-research turn, 6–10 searches is reasonable; we'll start at 8 and expose it as a setting if needed.
- **No streaming**: the WP-Cron context doesn't benefit from streaming. Use a single non-streaming request.

### Response handling

The `content` array interleaves blocks. To extract Claude's textual answer:

```php
$text = '';
foreach ($response['content'] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $text .= $block['text'];
    }
}
```

The text contains the JSON object we asked for. Strategy:

1. Concatenate all `text` blocks.
2. Find the JSON object: regex-extract the first `{...}` substring with balanced braces, or instruct Claude to wrap in `<json>...</json>` for unambiguous parsing. The spec asks Claude to return a JSON object directly; we parse defensively (try whole-text JSON first, fall back to substring extraction).
3. Validate keys against the expected schema; for array fields, intersect with the allowed-options list and drop unknowns.

### Error and edge cases

- **`stop_reason: pause_turn`** — the model paused mid-tool-use to avoid hitting limits. For our use case this should be rare with `max_uses: 8`, but we handle it by failing the job with a clear error message rather than implementing the continuation loop. (We can add the loop later if pause_turn turns out to be common.)
- **`web_search_tool_result_error`** — a `web_search_tool_result` block with `content.type === 'web_search_tool_result_error'`. Possible codes: `too_many_requests`, `invalid_input`, `max_uses_exceeded`, `query_too_long`, `unavailable`. We don't need to halt on these unless every search failed; Claude usually recovers and produces a best-effort answer with whatever it got.
- **Org-level web-search enable**: the docs note "Your organization's administrator must enable web search in the [Claude Console](https://platform.claude.com/settings/privacy)." The plugin's "Test connection" button should specifically test a tiny `web_search_20250305` request (not just a plain message) so that an org without web-search enabled gets surfaced before the first real enrichment.
- **Pricing**: $10/1k searches plus token costs. Worth surfacing in the readme.

## Mapping Claude's response to FluentCRM fields

| JSON key | Target | Notes |
|---|---|---|
| `org_type` | contact `org_type` | Validate against `single-select` options; fall back to `Other` |
| `org_sector` | contact `org_sector` | Validate against options; fall back to `Other` |
| `org_employees` | contact `org_employees` | Validate; fall back to `Unknown` |
| `org_revenue` | contact `org_revenue` | Validate; fall back to `Unknown` |
| `org_geo_scope` | contact `org_geo_scope` | Array; intersect with allowed options |
| `org_focus_areas` | contact `org_focus_areas` | Array; intersect with `fce_focus_area_options` |
| `org_partnership_models` | contact `org_partnership_models` | Array; intersect with allowed options |
| `org_alignment_score` | contact `org_alignment_score` | Validate; fall back to `Unknown` |
| `confidence` | company `enrichment_confidence` | Validate against High/Medium/Low |
| `narrative.decision_maker_context` | company note (section 1) | Markdown |
| `narrative.recent_developments` | company note (section 2) | Markdown |
| `narrative.alignment_assessment` | company note (section 3) | Markdown |
| `narrative.recommended_approach` | company note (section 4) | Markdown |

The note body is rendered as Markdown wrapped in section headings; FluentCRM stores notes as HTML (the WP editor field). We need to either (a) convert markdown to HTML before save, or (b) save plain markdown and accept that the FluentCRM admin UI will show the raw markdown. Recommend (a) using a small markdown helper — WordPress doesn't ship with one core-side, but a tiny conversion (paragraphs, bold, italic, lists, headings) is enough for the four narrative sections.

## Cross-reference — `creating-organization-dossiers` skill

A research-discipline skill at `app/creating-organization-dossiers/` covers the same problem domain. Worth borrowing, in the system prompt:

- **Source attribution per claim** — instead of treating Claude as a generic researcher, we ask it to cite each factual claim with its source URL inline.
- **Epistemic calibration** — distinguish "stated by the organization" vs "verified by third party" vs "inferred from available data."
- **Information gaps as content** — explicitly require Claude to name what it could not determine, rather than fabricating.
- **Premature commitment check** — before assigning an alignment score, weigh more than the first framing that comes to mind.

These don't change the JSON schema; they shape the quality of the narrative four-section output and reduce hallucination risk in the structured fields. Recommend wiring them into the system prompt as a base layer that the admin's context modules then refine for organization-specific framing.

## Open questions before implementation

1. **Markdown → HTML conversion**: ship a tiny inline converter, depend on `markdownify`/parsedown via Composer, or save raw markdown? My recommendation: tiny inline converter (50 lines, covers headings/bold/italic/lists/paragraphs/links) — no Composer dependency, predictable behavior, easy to audit.
2. **Multi-select value storage format**: store as comma-joined string (FluentCRM's internal default), or as array? Internal calls expect string-on-write, array-on-read. My recommendation: comma-joined string — matches what `Subscriber::store()` does for CSV imports.
3. **Pivot-company contacts**: spec asks for `company_id = this ID`. We should write only to primary-company contacts (matches rollups plugin's documented decision). Confirm in plugin readme.
4. **Test-connection button**: should it run a minimal `messages.create` with no tools (cheaper, only proves the API key works), or a `web_search_20250305` round-trip (proves both the key and the web-search org-level enable)? My recommendation: do the web-search round-trip — surfacing "web search not enabled" before the first real enrichment is more valuable than the small cost.
5. **System prompt baseline**: borrow research-discipline language from the dossier skill (source attribution, gap-naming, epistemic calibration), or keep the spec's lightweight framing? My recommendation: borrow — it's free quality improvement.

## Database structure (verified live by rollups plugin)

| Table | Purpose | Relevance to enrichment |
|---|---|---|
| `wp_fc_companies` | Companies. `meta` column is a serialized blob containing `custom_values`. | Where company enrichment fields land. |
| `wp_fc_subscribers` | Contacts. `company_id` is the primary company FK. | Source of contacts to push enrichment to. |
| `wp_fc_subscriber_meta` | Custom field values. `object_type = 'custom_field'`, `key` = field slug. | Where contact enrichment fields land. |
| `wp_fc_subscriber_notes` | Both subscriber and company notes. `status = '_company_note_'` distinguishes company notes. | Where the four-section enrichment narrative lands. |

Custom field *definitions* are not in any of these tables — they live in WP options `_fluentcrm_contact_custom_fields` and `_fluentcrm_company_custom_fields`.
