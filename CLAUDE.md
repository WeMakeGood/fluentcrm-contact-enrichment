# CLAUDE.md — FluentCRM Contact Enrichment

This file briefs future Claude sessions working on this plugin. It is not user documentation — it is engineering context.

## What this plugin does

The plugin has two parallel research surfaces:

**Company research** (since v0.1.0): an "Enrich" button on the FluentCRM company profile. A click schedules a WP-Cron job that calls the Anthropic Messages API with web search, parses a structured JSON response, writes organization-profile fields to every contact whose primary `company_id` matches the company, and stores a four-section narrative note on the company record.

**Individual contact research** (since v0.7.0): an "Enrich" button on the FluentCRM contact profile, scheduling a parallel WP-Cron job that researches the *person* (not their employer). Used for fundraising prospect research, cohort program participant prep, B2B stakeholder research, board recruitment. Apra-grounded research discipline; per-contact consent gate; subscriber note as output.

Both surfaces share the same Claude API client, JSON parsing, citation handling, and Markdown-to-HTML rendering. They have separate cron hooks, separate context modules (different framings for org vs. individual research), separate admin settings tabs, and separate result types (company note vs. subscriber note).

The plugin is general-purpose; admins customize each surface with Markdown context modules in the settings page that are injected into the respective system prompts.

## Why these architectural decisions

### File layout: OO/multi-file, not single-file procedural

The rollups plugin is a single-file procedural plugin (`fcr_*` prefix). This one is OO/multi-file (`includes/class-*.php`) because the spec called for it and because the seven concerns (registrar, modules, client, mapper, job, settings, section) genuinely separate cleanly. WordPress core style still applies: snake_case methods, capability + nonce checks, `wpdb->prepare()` if we ever touch SQL directly (we don't).

### Field types: canonical strings, not registry aliases

FluentCRM's PHP type registry has nine entries. For `single-select` and `multi-select`, the registry alias **differs from the canonical type string the Vue admin renders against**:

- alias `single-select` → canonical `select-one`
- alias `multi-select` → canonical `select-multi`

The bundled JS in `fluent-crm/assets/admin/js/start.js` only renders inputs for fields whose `type` matches the canonical string. A field saved with `type: "single-select"` persists fine but renders as a JSON dump on the contact/company profile.

`FCE_Field_Registrar::heal_field_types()` runs at the end of `ensure_fields()` and rewrites any plugin-managed slug whose stored type doesn't match the canonical form. This is a one-time migration for installations that ran 0.1.0-pre versions; new installs never hit it. **Never write fields with the alias.** `text`, `number`, `date`, `date_time`, `radio`, `checkbox`, `textarea` happen to have alias === canonical so they're fine either way.

### Custom field definitions are not Eloquent

`CustomContactField` and `CustomCompanyField` are wrappers around the WP options `contact_custom_fields` and `company_custom_fields`. There is no DB table, no `::all()`. Read with `(new CustomContactField())->getGlobalFields()`, write with `saveGlobalFields()`. The latter overwrites the entire option, so the registrar always reads first, appends missing fields, and writes the merged set.

### Company custom values: createOrUpdate, not direct meta write

Subscribers have `Subscriber::syncCustomFieldValues($values, $deleteOtherValues = true)` — call it with `false` for the second argument to write only the fields you care about. Companies have **no equivalent**. The only canonical write path is `FluentCrmApi('companies')->createOrUpdate(['id' => ..., 'name' => ..., 'custom_values' => [...]])`. The `name` is required even on update. The call merges into the existing serialized `meta.custom_values` blob, normalises types via `formatCustomFieldValues()`, and fires `fluent_crm/company_updated`. Don't write `$company->meta = [...]` directly — it works (the setter serializes correctly) but skips the type normalization and the hook.

### Notes share one table

`wp_fc_subscriber_notes` holds both subscriber and company notes. A `status` column distinguishes them: company notes have `status = '_company_note_'`, set by `CompanyNote::boot()`. The column **`subscriber_id` actually holds the company ID** for company notes (the column name was reused, not aliased). Don't be misled by the column name.

Note `type` values come from `fluentcrm_activity_types()` — `note`, `call`, `email`, `meeting`, etc. We use `note`. Run sanitization through `FluentCrm\App\Services\Sanitize::contactNote()` before save, and fire `fluent_crm/company_note_added` after, so any plugins listening to note creation get the event.

### Section render: `extender` API, not `extend`

The registered FluentCRM API key is `extender` (some external docs say `extend` — wrong). `FluentCrmApi('extender')` returns an `FCApi` proxy that forwards calls via `__call`. **Two non-obvious quirks:**

- `method_exists($extender, 'addCompanyProfileSection')` returns `false` even when the call works, because the proxy doesn't reflect the underlying `Extender` class's methods. Don't gate on `method_exists()`.
- The proxy catches exceptions in `__call` and returns `null`. A failed call is silent. Wrap in try/catch and treat null as success.

### Click handler: admin_footer + event delegation, not inline script

FluentCRM's Vue admin renders `content_html` from a profile section via `domProps:{innerHTML: t._s(t.content_html)}`. **`innerHTML` does not execute embedded `<script>` tags** — that's a standard DOM security behaviour, not a Vue choice. The Enrich button's click handler is therefore enqueued in `admin_footer` (gated to FluentCRM screens by `strpos($screen->id, 'fluentcrm-admin')`) and uses event delegation against `document`. The button itself carries `data-company-id`, `data-nonce`, and `data-ajax-url` attributes; the handler reads all of those from the click target so we don't have to inline page state into the script.

### Same-day note replacement

`create_research_note()` looks for an existing note on the company whose title matches `Enrichment Research — <today>`. If found, it `fill().save()`s the description in place rather than `create()`ing a new row. Cross-day re-enrichments still create a new note, preserving historical analyses. Without this, a user re-clicking Enrich would pile up duplicate notes from the same day.

### Citation handling

Anthropic's web search returns two parallel citation systems:

1. **Structured citations** on `text` blocks at the top level of `content[]`. Each has a `url`, `title`, `cited_text`. **These do not appear inside JSON string values** — the four narrative fields don't get them.
2. **Inline `<cite index='X-Y'>...</cite>` tags** that Claude often emits inside its prose, including inside JSON string values returned in the schema we ask for.

We tell the model in the system prompt to use Markdown link syntax inside narrative strings instead of `<cite>` tags. The data mapper also strips `<cite>` tags defensively (`FCE_Data_Mapper::clean_narrative()`). This produces clean clickable `<a href>` links in the rendered note rather than HTML-encoded `&lt;cite&gt;` gibberish.

The `cited_text` field on the Claude client's return value (where structured citations get woven into Markdown-link syntax) is currently not used downstream — it's there for a future feature (citation-aware narrative re-extraction) but the JSON-inside-text-block reality made it unnecessary for now. Kept the code path live so a future change has the hook.

### Web search tool version

Use `web_search_20250305`. The newer `web_search_20260209` adds dynamic filtering but **requires the code-execution tool to also be enabled** — heavier dependency, no upside for this use case. The org admin must also enable web search at the org level in the Claude Console; the Test Connection button verifies this without spending a billable search round (it includes the tool definition but tells the model not to invoke it).

### API key encryption

Stored AES-256-CBC encrypted with a key derived from WP auth salts (`hash('sha256', AUTH_KEY . SECURE_AUTH_KEY . AUTH_SALT . 'fluentcrm-contact-enrichment', true)`). The stored value starts with `fce1:` so the version is identifiable for future migrations. A server compromise that reads `wp-config.php` can decrypt — that's the realistic threat model for WP plugins, and we don't claim to defend beyond it. The save path only updates the option when a non-empty value is posted, so resubmitting the form without retyping doesn't blank the stored key.

### Individual contact research surface (v0.7.0)

A parallel research path that targets the contact (not their employer). Use cases are framed by the admin's contact context modules — fundraising prospect research, cohort prep, sales prospecting, board recruitment all share the same machinery, just different framing.

Three architectural decisions worth knowing:

1. **Apra-grounded discipline, not just generic research.** The contact-side system prompt (`FCE_Enrichment_Job::contact_research_discipline()`) cites the Apra Statement of Ethics principles verbatim — Integrity, Accuracy, Accountability, Confidentiality, Source Provenance — and adds an explicit *relevance gate*: "research is restricted to information bearing on the relationship the requesting organization is trying to build." The gate's content is shaped by the contact context modules. This is stricter than the company-side prompt because individual research has higher privacy stakes.

2. **Per-contact consent gate.** Every contact has an `individual_research_consent` field (Allowed / Restricted, default Allowed). `FCE_Enrichment_Job::run_contact()` checks the value before doing anything else and short-circuits with status=Restricted if the contact has opted out. The check happens inside the cron handler (not just the ajax handler) so a race between click and cron-fire still respects the opt-out. Verified live: a Restricted run returns in <1 second with no API call made.

3. **Capacity tier is admin-configurable.** The default values are donor-flavored (Major / Mid / Standard / Unknown), but the "Capacity Tiers" admin settings tab lets admins rewrite them per use case (cohort programs might use Senior Leader / Mid-Career / Emerging / Unknown; B2B might use Decision Maker / Influencer / End User / Unknown). Same admin pattern as `org_focus_areas` — list of strings, syncs into the field definition's options at save time. The system prompt reads the configured values at runtime so the schema is dynamic.

The 9 individual_* fields (4 status/consent + 5 outputs) live only on the contact — no mirroring to anywhere. The contact-side rollups exclusion already covers them via `all_contact_field_slugs()`. The output is a `SubscriberNote` titled "Contact Research — YYYY-MM-DD" with the same four-section narrative structure as company research, but framed for individuals (Personal Context / Relevant Background / Alignment Assessment / Recommended Approach).

A bulk-resync Danger Zone variant for individual research is **deliberately not built**. Bulk individual research at scale would burn through API budget without a clear use case, and consent-checking adds complexity that doesn't pay off without evidence. Revisit if there's demand.

### Sync buttons (v0.6.0)

Two surfaces let admins push the company-side org_* cache to contacts without re-running an enrichment:

- **Per-company button** on the Enrichment profile section, only rendered when `enrichment_status === 'Complete'`. Hits `wp_ajax_fce_sync_company_to_contacts`, which calls `FCE_Contact_Sync::sync_company($id)`. Useful when a contact is attached to an already-enriched company, or when contact values have drifted.
- **Bulk button** in a Danger Zone tab on the settings page. Typed-RESYNC confirmation gate, synchronous execution, success-message reports counts of companies processed/skipped and contacts updated. Calls `FCE_Contact_Sync::bulk_resync()`, which chunks through all companies in batches of 100.

`FCE_Contact_Sync::cached_org_values()` does the format conversion: company-side multi-select values stored as PHP arrays (e.g. `['National', 'International']`) are joined with `", "` before being passed to `Subscriber::syncCustomFieldValues()`. Without this conversion, FluentCRM's contact-side read path would mishandle the array.

The bulk sync is synchronous on purpose — the admin clicked a button and is waiting. We bump `set_time_limit(300)` and `wp_raise_memory_limit('admin')` before starting. For installs with thousands of companies this still risks timeout; we accept that as a known limit and document it. A future chunked-cron variant could remove the limit if it becomes a problem.

### Hiding only the three status fields from FluentCRM's company surfaces (v0.5.0 → v0.5.1)

The original v0.5.0 hid all 11 enrichment fields by filtering them out of `$data['company_custom_fields']` in the `fluent_crm/admin_vars` payload. That worked for the company profile's "Custom Data" sidebar, but the same payload powers four separate UI surfaces:

1. The "Custom Data" sidebar on the company profile (display)
2. The company list-view filter chips (segmentation)
3. The company list-view custom column dropdown (display)
4. The field-value editor on the profile

Hiding all 11 fields from surfaces 2–4 broke company-level filtering and segmentation that admins genuinely use. v0.5.1 narrows the filter: only the three enrichment status fields (`enrichment_status`, `enrichment_date`, `enrichment_confidence`) are hidden, since those duplicate what we already render at the top of the Enrichment profile section. The 8 org_* fields are visible across all four surfaces, restoring filtering parity with the contact side.

The contact-side `contact_custom_fields` is intentionally untouched. The 8 org_* values appear in the contact profile's Custom Profile Data sidebar where they're filterable via FluentCRM's contact segment builder.

**Lesson:** the same payload feeds multiple UI surfaces. When filtering, scope the filter to the exact subset whose duplication is the actual annoyance — not the broadest set that "looks cleaner" on the surface you started with.

### Company-side org_* cache (v0.4.0)

Through v0.3.0, the 8 org_* enrichment values lived only on contacts (via `Subscriber::syncCustomFieldValues()`). The company record had `enrichment_status`, `enrichment_date`, and `enrichment_confidence` — but the *organizational* data (type, sector, employees, etc.) was only on contacts. That made the company the wrong source of truth for organizational facts: a contact joining a company *after* enrichment had no way to inherit the company's data, and a sync-to-contacts button would have to "pick a source contact" arbitrarily.

In v0.4.0, the same 8 org_* slugs are now defined on the company side too (with identical groups: "Enrichment — Org Profile" and "Enrichment — Alignment"), and `FCE_Enrichment_Job::write_company()` writes them in the same `createOrUpdate` call as the status/date/confidence fields. The company is now canonical.

A one-time heal pass at activation (`heal_company_org_cache()`) walks every company that has an enriched contact but no company-side cache, picks the most-recently-updated contact as source, and writes its org_* values to the company. Idempotent — re-running skips companies whose cache is already populated.

**Format wrinkle worth knowing:** multi-select values are stored differently on the two surfaces.

- Company side: `org_geo_scope = ['National']` (PHP array). FluentCRM's `Companies::createOrUpdate()` calls `formatCustomFieldValues()` which array-coerces multi-select values before serialization into `meta.custom_values`.
- Contact side: `org_geo_scope = 'National'` (comma-joined string). `Subscriber::syncCustomFieldValues()` doesn't apply that coercion.

Both formats represent the same logical value, but a future sync-from-company implementation must convert arrays back to comma-joined strings before writing to contacts. The data mapper currently emits comma-joined strings; on the company write path, FluentCRM converts. On the contact write path, no conversion happens. This was an artifact of using the canonical write paths on each side rather than fighting them — fighting them would have been more code for less benefit.

### `org_sector` shares FluentCRM's industry vocabulary (v0.3.0)

Originally the contact-side `org_sector` field had its own 10-item list (Education, Health, Arts & Culture, etc.). The native company `industry` field uses FluentCRM's 147-item canonical list. Same name, different vocabularies → admins saw mismatched values across contact and company records.

In v0.3.0, `org_sector`'s option list switched to `\FluentCrm\App\Services\Helper::companyCategories()` (same source as native industry), and the value is **derived** from `native_fields.linkedin_industry` in the data mapper rather than asked of Claude separately. One source of truth, guaranteed consistency.

The heal pass also runs `clear_invalid_org_sector_values()` once: any stored value that's not in the new list is deleted from `wp_fc_subscriber_meta` so it doesn't render as invalid in the FluentCRM UI. Re-enrichment refills. No remapping table — the old vocabulary's categories ("Environment / Conservation", "Arts & Culture") don't have clean targets in the new list, so a remap would be opinionated.

The `org_sector` field continues to live on contacts (not just companies) because FluentCRM segment builders only see contact custom fields. See `~/.claude/projects/.../memory/fluentcrm-contact-side-fields.md`.

### Native company fields (v0.2.0)

In addition to the eleven custom fields, enrichment fills FluentCRM's built-in company columns when they're empty: `industry` (validated against `\FluentCrm\App\Services\Helper::companyCategories()` — 147-item LinkedIn-style enum), `description`, the six address columns, the three social-URL columns, and `employees_number` (derived from the `org_employees` bucket midpoint).

Two non-obvious decisions:

1. **Fill-if-empty, not overwrite.** `FCE_Enrichment_Job::write_native_fields()` re-fetches the company and skips any column whose existing value is non-empty. Admin-curated values are never overwritten. If the admin wants to refresh a field, they clear it first, then re-enrich.
2. **Industry uses Claude's enum constraint, not validation-after.** The system prompt includes the full 147-item list and tells Claude to pick one or omit the key. The mapper still validates against the list as a safety net — invalid values are silently dropped.

`employees_number` is derived from the bucket, not asked from Claude separately. We have a fixed bucket → midpoint table in the mapper. If FluentCRM ever supports bucket fields natively, this gets simpler.

### `org_focus_areas` field options sync

The settings tab "Focus Areas" stores its option list in `fce_focus_area_options`. Saving the tab also calls `FCE_Field_Registrar::sync_focus_area_options()`, which rewrites the `options` array on the existing field definition. Existing values stored on contacts under that field are not touched — only the field's metadata. Without this sync, a focus-area edit would drift from the field definition until the plugin was reactivated.

## Files

- `fluentcrm-contact-enrichment.php` — bootstrap, constants, hook wiring
- `includes/class-field-registrar.php` — auto-creates fields, runs heal pass
- `includes/class-context-modules.php` — Markdown module CRUD
- `includes/class-claude-client.php` — Anthropic Messages API HTTP client
- `includes/class-data-mapper.php` — JSON extraction + value validation
- `includes/class-contact-sync.php` — push company-side cached values to linked contacts (per-company + bulk paths)
- `includes/class-enrichment-job.php` — WP-Cron handler, the full pipeline
- `includes/class-admin-settings.php` — Settings → Contact Enrichment, three tabs
- `includes/class-company-section.php` — company profile section + Enrich button + Sync to Contacts + ajax
- `includes/class-contact-section.php` — contact profile section + Enrich button + ajax (v0.7.0+)
- `vendor/erusev/parsedown/` — bundled Markdown→HTML library
- `docs/fluentcrm-enrichment-research.md` — pre-implementation reconnaissance findings; FluentCRM internal APIs, Anthropic API mechanics, decisions made during the build, and the prompt-caching / Files API analysis deferred for v0.1.0
- `docs/fields-reference.md` — operational documentation for the 11 custom fields the plugin creates: pipeline diagram, per-field allowed values + meaning + fallbacks, where data is stored, how to segment
- `readme.txt` — WordPress.org-format readme

## Coding conventions

- OO classes per concern, snake_case methods, function prefix `FCE_*` and `fce_*`, constants `FCE_*`
- WordPress core style, not PSR — `wp_*` for utilities, capability check + nonce check on every form post and ajax handler
- Sanitize on save, escape on render
- All write paths to FluentCRM data go through public APIs (`FluentCrmApi('companies')->createOrUpdate`, `Subscriber::syncCustomFieldValues`, `CompanyNote::create`) — no direct `wpdb` writes to FluentCRM tables

## Database structure (verified live)

| Table | Purpose | Relevance |
|---|---|---|
| `wp_fc_companies` | Companies. `meta` is a serialized blob with `custom_values`. | Where company enrichment fields land. |
| `wp_fc_subscribers` | Contacts. `company_id` is primary company FK. | Source of contacts to push enrichment to. |
| `wp_fc_subscriber_meta` | Custom field values (`object_type='custom_field'`, `key=slug`). | Where contact enrichment fields land. |
| `wp_fc_subscriber_notes` | Both subscriber and company notes. `status='_company_note_'` distinguishes. | Where the four-section narrative lands. |

Custom field definitions live in WP options `_fluentcrm_contact_custom_fields` and `_fluentcrm_company_custom_fields`.

## Investigated and deferred

Two API features were evaluated during the v0.1.0 build and consciously left out — both for "wait for evidence" reasons, not because they're inappropriate. Full analysis in `docs/fluentcrm-enrichment-research.md` under "Future consideration: prompt caching and Files API."

- **Anthropic prompt caching.** Real cost win on bulk-enrichment sessions and a real consistency property. Implementation is ~30 lines. Deferred because realistic single-click usage hits the 5-minute TTL boundary often enough that we want actual usage data before deciding whether to cache and at what TTL. Worth revisiting when admins report bulk runs, ask for cross-call consistency, or a single context module grows past ~30k tokens.
- **Anthropic Files API.** Not a substitute for inlined context modules — same per-call token cost, only PDF/plain text supported as `document` blocks (Markdown isn't), document blocks belong in `messages[]` not `system[]`, and the persistence model would push lifecycle management onto admins. Reasonable fit for a future "attach annual report PDF to a company record" feature, where it would stack with (not replace) prompt caching. Beta header dependency.

## Known limitations

- **Vue admin caches stale company data.** After clicking Enrich and waiting for the cron job, a hard browser refresh can show stale section state (status still Pending) because FluentCRM's Vue admin caches company payloads. Re-entering the section in the Vue router refreshes it cleanly. The button text "Queued — refresh to see status" is honest about this.
- **`pause_turn` not handled by continuation.** If Claude pauses mid-tool-use (rare with `max_uses: 8`), the current implementation surfaces it as a recoverable error rather than implementing the multi-turn continuation loop. If `pause_turn` becomes common we'd need to extend the client to continue the turn.
- **Pivot-only contacts not enriched.** Only contacts whose primary `company_id` matches are updated. Many-to-many associations via `wp_fc_subscriber_pivot` are not. This mirrors the rollups plugin's documented decision and avoids double-writing for contacts associated with multiple companies.
- **No company-list-view surface.** FluentCRM has no extension points for company list columns or segment filters that could surface enrichment fields. Status appears on the company record only.

## What this plugin does NOT do

- Does not modify the FluentCRM database schema. All field definitions live in WP options; values use FluentCRM's own tables and write paths.
- Does not deactivate or remove its custom fields on plugin deactivate. They're left intact so the data isn't lost. Uninstall (full removal) is a future TODO if it matters.
- Does not provide a bulk-enrichment UI. Each enrichment is a separate user action because each costs API credits and admins should have explicit control.
- Does not handle Claude's structured citations beyond surfacing them as the `cited_text` view inside the client's return value (currently unused). Citations inside narrative strings come from prompt-time instruction to the model + defensive `<cite>` tag stripping.

## Repository

- GitHub: https://github.com/WeMakeGood/fluentcrm-contact-enrichment
- License: GPL-2.0-or-later
- Owner: WeMakeGood organization
