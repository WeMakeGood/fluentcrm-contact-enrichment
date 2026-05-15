# FluentCRM 3.0 Integration Design

This document specifies how the FluentCRM Contact Enrichment plugin is updated for FluentCRM 3.0. It is the engineering plan for the next major version (provisionally v1.0.0 — the bump signals the 3.0 dependency).

## Why this work

FluentCRM 3.0 shipped three changes that intersect this plugin:

1. **Built-in AI features and a unified provider configuration.** Contact summaries, email writing, inline text editing. Provider credentials live in `get_option('_fluent_ai_creds')` (provider, api_key, model) and the enablement flag lives in `fluentcrm_get_option('_ai_writing_settings')` (is_enabled, custom_prompt). The configured provider applies to all of FluentCRM's AI surfaces.
2. **An MCP server with 25 native tools.** Built on the WordPress Abilities API and the WordPress MCP Adapter plugin. Native tools already expose our custom fields and notes via `get-contact` / `upsert-contact`, so this plugin doesn't need to register its own.
3. **A refreshed UI design system.** Element Plus mapped to `--fc-*` CSS variables and FluentCRM utility classes. Our profile sections were styled for the pre-3.0 admin and now look out of place.

The 3.0 release marginally overlaps with this plugin's contact-summary capability, but only at the surface. 3.0 produces ephemeral CRM-data-only summaries written to subscriber meta `_ai_contact_summary`. This plugin produces grounded, structured, persistent research written to custom fields and notes. The positioning is sharper after 3.0, not weaker.

This design captures four reworks:

- **Provider integration** — stop managing our own API key. Read credentials from FluentCRM's `ai_settings`. Support all three providers FluentCRM supports (Claude, OpenAI, Gemini); web search is Claude-only in v1.0.
- **UI realignment** — restyle profile sections to match 3.0's design system; relocate admin pages under the FluentCRM menu.
- **API migration** — adopt 3.0's new `addProfileSection()` signature for the contact-side profile section.
- **Company module detection** — gate company-related features on whether FluentCRM's company module is enabled.

There is no MCP rework. FluentCRM's native MCP tools already cover our enrichment data surface (see "MCP surface" below).

## Hard requirements

- **FluentCRM 3.0+** required. Activation guard refuses to load on older versions with an admin notice explaining the dependency.
- **AI must be enabled and configured** in FluentCRM → Settings → AI Configuration. The `is_enabled` flag must be `'yes'`, a provider must be selected, and an API key must be set. Without all three, the plugin shows a setup notice and disables Enrich buttons. No fallback to a plugin-managed key.
- **v1.0.0 supports Claude as the active provider only.** The bridge architecture supports all three providers (Claude, OpenAI, Gemini), but only the Claude adapter is implemented in v1.0.0. Installs configured for OpenAI or Gemini see a "Configured for unsupported provider" health-check banner and disabled Enrich buttons. OpenAI and Gemini adapters land in v1.0.1.
- **Active provider must be Claude for web search.** Once v1.0.1 adds OpenAI and Gemini adapters, those runs work but don't include web search.

---

## Reworks

### 1. Provider integration — adopt FluentCRM's `ai_settings` as the single config surface

**Current state.** The plugin stores an AES-256-CBC encrypted Anthropic API key in `fce_api_key`. The Settings page has an API Key tab with a Test Connection button. `FCE_Claude_Client` reads the option, decrypts, and signs requests.

**Target state.** The plugin reads the configured AI credentials from FluentCRM's two-option storage (`get_option('_fluent_ai_creds')` for credentials, `fluentcrm_get_option('_ai_writing_settings')` for the enablement flag) at call time. Supports Claude, OpenAI, and Gemini — whichever FluentCRM has active. Web search runs only when Claude is active; the other providers produce enrichment grounded by lookup fields, context modules, and FluentCRM data but without live web research. The plugin's API Key settings tab is removed.

The user experience this enables: one place to configure AI (FluentCRM → Settings → AI Configuration), one set of credentials, one provider switch that controls both FluentCRM's native AI features and enrichment runs.

#### Why we're implementing our own provider client rather than calling FluentCRM's

FluentCRM 3.0 has private methods `callClaude`, `callOpenAi`, `callGemini` inside `AiController` that handle each provider's request shape. They're cleanly factored — generic `(system_prompt, user_prompt)` in, raw text out, no feature coupling. But three things block reuse:

- They're **private**, with no filters or hooks to intercept input/request args.
- They don't accept a **tools array**, so we can't add web search.
- They don't accept **response_format / structured output**, so we can't request JSON.
- `max_tokens` is hardcoded at 2048, below what our four-section narrative needs.

We file an issue with WPManageNinja asking for a public provider client API — that's the right long-term fix for the whole plugin ecosystem. But it doesn't unblock us now. For v1.0 we implement our own multi-provider client, modeling FluentCRM's request structures so admins see no functional difference in how providers are configured.

#### Implementation

```php
class FCE_Provider_Bridge {
    public function get_credentials(): array {
        if (!function_exists('fluentcrm_get_option')) {
            throw new FCE_Provider_Unavailable('FluentCRM 3.0+ required.');
        }

        $credentials = get_option('_fluent_ai_creds', []);
        $preferences = fluentcrm_get_option('_ai_writing_settings', []);

        if (!is_array($credentials)) $credentials = [];
        if (!is_array($preferences)) $preferences = [];

        // FluentCRM gates its AI features on the is_enabled flag. We honor it.
        $is_enabled = ($preferences['is_enabled'] ?? 'no') === 'yes';
        if (!$is_enabled) {
            throw new FCE_Provider_Unavailable(
                'Enable AI in FluentCRM → Settings → AI Configuration before running enrichment.'
            );
        }

        $provider = $this->normalize_provider((string) ($credentials['provider'] ?? ''));
        if (!in_array($provider, ['claude', 'open_ai', 'gemini'], true)) {
            throw new FCE_Provider_Unavailable(
                'Configure an AI provider in FluentCRM → Settings → AI Configuration.'
            );
        }

        $api_key = (string) ($credentials['api_key'] ?? '');
        if ($api_key === '') {
            throw new FCE_Provider_Unavailable("API key for {$provider} is not set.");
        }

        // FluentCRM lets admins pick "auto" — resolve to the per-provider default.
        $model = (string) ($credentials['model'] ?? 'auto');
        if ($model === '' || $model === 'auto') {
            $model = $this->auto_model_for($provider);
        }

        return [
            'provider'        => $provider,    // 'claude' | 'open_ai' | 'gemini'
            'api_key'         => $api_key,
            'model'           => $model,
            'supports_search' => $provider === 'claude',
        ];
    }

    private function normalize_provider(string $p): string {
        $p = sanitize_key($p);
        return $p === 'openai' ? 'open_ai' : $p;
    }

    private function auto_model_for(string $provider): string {
        // Mirrors FluentCRM's $autoProviderModels (AiController.php:21).
        return [
            'claude'  => 'claude-sonnet-4-6',
            'open_ai' => 'gpt-5.5',
            'gemini'  => 'gemini-2.5-flash',
        ][$provider] ?? '';
    }
}
```

The two-option split (`_fluent_ai_creds` for credentials, `_ai_writing_settings` for preferences) is FluentCRM's own design — credentials are sensitive WP options, preferences live in their structured option store. We respect the same boundary.

The provider client dispatches to a provider-specific adapter:

```php
class FCE_Provider_Client {
    public function __construct(FCE_Provider_Bridge $bridge) { ... }

    public function send_enrichment_request(array $payload): array {
        $creds = $this->bridge->get_credentials();

        switch ($creds['provider']) {
            case 'claude':  return (new FCE_Claude_Adapter($creds))->send($payload);
            case 'open_ai': return (new FCE_OpenAI_Adapter($creds))->send($payload);
            case 'gemini':  return (new FCE_Gemini_Adapter($creds))->send($payload);
        }
    }
}
```

Each adapter:
- Builds the provider-specific request shape (Anthropic Messages, OpenAI Chat Completions, Gemini Generate Content).
- For Claude: includes the `web_search_20250305` tool definition.
- For all three: requests JSON output via the provider's native mechanism (Claude prompt-based, OpenAI `response_format`, Gemini `responseSchema`).
- Returns a normalized response shape that `FCE_Data_Mapper` can consume regardless of provider.

The mapper already handles citation extraction and `<cite>` tag stripping. Non-Claude adapters return `citations: []` since they don't have web search.

#### Settings UI changes

- The "API Key" tab is removed.
- A health-check banner appears at the top of every plugin admin page. Reads `_fluent_ai_creds` + `_ai_writing_settings` and shows one of five states:
  - **Ready (Claude)** — `is_enabled === 'yes'`, provider is Claude, key is set. Banner notes the resolved model and that web search is enabled.
  - **Ready (OpenAI / Gemini)** — *(v1.0.1+)* `is_enabled === 'yes'`, provider is OpenAI or Gemini, key is set. Banner notes the resolved model and that web search is **not** enabled.
  - **Configured for unsupported provider** — *(v1.0.0 only)* `is_enabled === 'yes'`, provider is OpenAI or Gemini, key is set. Yellow banner: "v1.0.0 supports Claude only. Switch FluentCRM's active provider to Claude, or wait for v1.0.1."
  - **Disabled** — Credentials are set but `is_enabled === 'no'`. Yellow banner: "AI is configured but disabled — enable it in FluentCRM → Settings → AI Configuration."
  - **Not configured** — Provider missing or API key missing. Yellow banner linking to FluentCRM → Settings → AI Configuration.
- The Enrich button on profile sections is disabled in any state other than "Ready."

#### Migration for existing installs

- On plugin upgrade to v1.0.0, the activation hook reads any existing `fce_api_key`.
- If FluentCRM's `ai_settings` doesn't have a Claude key set, show a one-time admin notice: "Your enrichment plugin previously stored its own Anthropic API key. Copy it to FluentCRM → Settings → AI Configuration now." Include the decrypted key in the notice (one-time, dismissable, behind a "Show key" disclosure).
- After the notice is dismissed (or 30 days, whichever first), delete `fce_api_key`.

Documented in CHANGELOG with explicit migration steps.

---

### 2. UI realignment

#### 2a. Profile section styling

Both profile sections (company and contact) need a styling pass. Current pattern uses bare inline `style="…"` attributes that override 3.0's design system. New pattern uses FluentCRM's utility classes and CSS variables.

**Specific changes:**

- Replace inline `style="padding: 1em 0"` and similar with utility classes (`fcrm_pt_16`, `fcrm_pb_16`, `fcrm_mb_12`, etc.).
- Replace hardcoded colors with `var(--fc-primary-text)`, `var(--fc-secondary-text)`, etc.
- Wrap status badges in `<span class="fcrm_badge fcrm_badge_{status}">` where `{status}` is `success` / `warning` / `error` / `info` depending on enrichment state. This matches the visual language of the rest of the admin.
- Use `fc_full_listed fcrm_customer_summary_list` for the label-value list of enrichment fields. Matches the WooCommerce purchase history pattern.
- The "Enrich" button uses `el-button el-button--primary` with no inline overrides. The "Sync to Contacts" button uses `el-button el-button--default`. Spacing comes from `fcrm_ml_8` between them, not from `style="margin-left: 0.5em"`.

No new CSS file. We inherit FluentCRM's existing stylesheet, which is already loaded on admin pages where our sections render.

#### 2b. Contact profile section API migration — *not needed*

The first design draft expected a migration from a deprecated array-based `addSubscriberProfileSection($section)` to FluentCRM 3.0's callback-based `addProfileSection($key, $title, $renderCallback, $saveCallback)`. The recon agent reported a signature change.

When rework 1 verification surfaced unrelated bad recon, I rechecked this one too. Both `class-contact-section.php` and `class-company-section.php` already use the FluentCRM 3.0 callback signatures (`addProfileSection` for contacts, `addCompanyProfileSection` for companies), both passing `($key, $sectionTitle, $renderCallback)` and returning `{ heading, content_html }` from the render callback. The contact section has been on this signature since it was added in v0.7.0 — we built against the right API from the start.

**No code change in rework 2.** The styling pass in 2a still applies.

#### 2c. Admin entry placement

The plugin's admin currently lives under WordPress Settings → Contact Enrichment. The Settings menu is the wrong neighborhood — admins manage everything else about FluentCRM from inside FluentCRM. The fix is to register our entry through FluentCRM's documented extension surface so admins find us alongside the rest of FluentCRM.

**Registration via `fluent_crm/core_menu_items`:**

```php
add_filter('fluent_crm/core_menu_items', function ($menu_items, $permissions) {
    if (!in_array('fcrm_manage_settings', $permissions, true)) {
        return $menu_items;
    }

    $menu_items['contact_enrichment'] = [
        'title'      => __('Contact Enrichment', 'fluentcrm-contact-enrichment'),
        'capability' => 'fcrm_manage_settings',
        'slug'       => 'contact-enrichment',
    ];

    return $menu_items;
}, 10, 2);
```

This places our entry in FluentCRM's WP submenu, gated by FluentCRM's permission system. The slug becomes the WP admin page at `admin.php?page=contact-enrichment`. We render that page with our existing settings UI — the tab structure (Company Context / Contact Context / Capacity Tiers / Focus Areas / Lookup Fields / Danger Zone) stays as-is.

**The remove path:** delete the existing `add_submenu_page()` under `'options-general.php'`. There's no parallel registration — old entry goes, new entry takes its place. WordPress Settings menu is no longer involved.

**Why we don't render inside FluentCRM's Vue SPA.** Pro modules (Sequences, SMS, Advanced Reports) appear as routes inside FluentCRM's SPA because they're *compiled into FluentCRM's Vite build at release time* — they're not registered from outside. The developer documentation indexes every public extension point, and none of them supports SPA route registration from an external plugin. `core_menu_items` is the documented surface, and it points at WP admin pages, not SPA routes. A plugin shipping its own prebuilt Vue chunks to inject into the SPA is possible but unsupported, would break on FluentCRM updates, and the build-pipeline cost outweighs the seam it removes.

**Why we don't try the "Settings sub-page alongside General / Email / AI" placement.** That would require a hook like `fluent_crm/settings_tabs` or `fluent_crm/global_settings_sections`. No such hook exists. The settings tabs inside FluentCRM's SPA are defined in the Vue build, same as the rest of the SPA.

**Why we don't try the Integrations tab.** Per the developer docs, "Integration" hooks (`fluent_crm/import_providers`, `fluent_crm/saas_migrators`, `fluent_crm/purchase_history_providers`, `fluent_crm/form_submission_providers`, `fluent_crm/advanced_report_providers`) are for specific narrow roles — registering an import source, registering a migrator, surfacing order history in the contact sidebar. None of them is a host for plugin-wide configuration UI.

**What the user experiences:**

1. Open WP admin.
2. Click "FluentCRM" in the sidebar — the FluentCRM admin loads.
3. In FluentCRM's submenu, see "Contact Enrichment" alongside "Settings," "Help," and the other items `core_menu_items` populates.
4. Click "Contact Enrichment" — page loads. Visually it matches FluentCRM admin (Element Plus components, `--fc-*` design tokens, utility classes) because we enqueue the same stylesheets and use the same class conventions.

The one visible seam: clicking the entry triggers a full page reload rather than an instant SPA transition. Acceptable; most admins won't notice it if the destination looks like FluentCRM.

**Visual continuity implementation:**

- Enqueue FluentCRM's admin CSS on our page so `--fc-*` variables and `fcrm_*` utility classes are available. (Verify the handle name during implementation — likely `fluentcrm_admin_app` or similar.)
- Build forms with Element Plus markup conventions (`el-form`, `el-input`, `el-button`, `el-select`). No Vue compilation required — we're using the rendered classes, not the reactive components. Markdown editor, lookup field picker, and capacity tier configuration are all forms with light vanilla JS for interactivity.
- For data we need on the JS side (current AI settings state, capability flags), read `window.FluentCrmApp` populated via the `fluent_crm/admin_vars` filter rather than re-fetching.

This is the upper bound of what documented extension points allow. If a future FluentCRM release publishes a real SPA route hook (we should ask), this becomes the upgrade target.

---

### 3. Company module detection

FluentCRM's company module is opt-in. Admins enable it under FluentCRM → Settings → General → "Enable Company Module" (option key TBD, verify during implementation). When the module is off, companies don't exist as records, custom company fields don't render, the company profile page doesn't load.

The plugin currently assumes companies always work. Most of it degrades gracefully (the contact-side enrichment is fully independent), but several things misbehave:

- The company profile section registration runs but the page never renders, so no actual harm — just wasted hook firing.
- The bulk-resync Danger Zone references companies that may not exist.
- The field registrar creates company custom fields that never surface in the UI.
- The Company Context tab in plugin settings is confusing on an install where there are no companies.

#### Implementation

A central detector:

```php
class FCE_FluentCRM_Compat {
    public static function is_company_module_enabled(): bool {
        $settings = fluentcrm_get_option('general_settings', []);
        return !empty($settings['company_module']); // verify key during impl
    }
}
```

Gating applies at five points:

1. **Field registrar.** `ensure_fields()` skips the company-side `ensure_company_fields()` call when the module is off.
2. **Profile section registration.** `addCompanyProfileSection()` only fires when the module is on.
3. **Admin settings.** The "Company Context" tab and the "Lookup Fields → Company" picker only render when the module is on. The Danger Zone bulk-resync button is hidden too.
4. **Activation idempotence.** If an admin enables the module after the plugin is already active, our admin page load checks `is_company_module_enabled()` and re-runs the field registrar if fields are missing. (The alternative would be to require deactivate/reactivate, which is friction.)
5. **The health-check banner** notes whether the company module is detected, alongside the AI provider state. Helps admins debug "why don't I see a Company Context tab?"

The contact-side surface is entirely unaffected.

---

## MCP surface

We deliberately don't register MCP tools in v1.0. This is a change from earlier drafts of this design.

**Why:** FluentCRM 3.0's native MCP tools already expose our enrichment data.

- `get-contact` returns `custom_fields` (including all `org_*` and `individual_*` slugs) and `notes` (including our four-section narrative notes) inline.
- `upsert-contact` accepts a `custom_fields` object for writes.
- `list-contacts` supports `include_custom_fields=true` to inline values in list summaries.
- Filtering by custom-field values may be available via the `advanced_filters` argument, depending on whether FluentCRM registers our slugs in `Helper::getAdvancedFilterOptions()`. Verify during implementation.

An MCP client connected to FluentCRM's `/wp-json/fluent-crm/mcp` endpoint can already query our enrichment data, write to enrichment fields, and read enrichment notes — without us adding anything. That's a stronger story than registering a parallel set of tools that duplicate FluentCRM's surface.

**Known gaps** in the native MCP coverage:

- **No full-text search across note descriptions.** A query like "find contacts whose enrichment notes mention planned giving" isn't covered. `list-contacts` searches name/email/custom-field values, not notes.
- **No company-side MCP tools at all.** Zero. If the company module is on, all company queries fall through.

These are real gaps, but they're FluentCRM gaps, not enrichment-plugin gaps. Filing them upstream is the right move. If demand justifies it, a future v1.x could add a single `fluent-crm-enrichment/search-notes` tool to cover the first gap — but that's a small, focused addition we can make when usage shows it matters.

**A deliberate consequence.** Native MCP write access to custom fields means an MCP client can populate `org_*` or `individual_*` fields directly, bypassing the research pipeline. This trades data quality for flexibility. We don't try to prevent it; we document it. Admins who want enrichment data to come only from grounded research can manage that through FluentCRM's per-user capabilities (give MCP-using accounts read-only `fcrm_read_contacts` instead of `fcrm_manage_contacts`).

---

## Other changes

**`uninstall.php`:** Update to also clean up `fce_api_key` if it still exists from pre-1.0.0 installs (it shouldn't after the migration, but defensive).

**`fluentcrm-contact-enrichment.php` plugin header:** Bump version to 1.0.0. Update `Requires Plugins: fluent-crm` (WP 6.5+ feature) so WP shows the dependency. Add a "Requires FluentCRM 3.0+" note in `Description`.

**`readme.txt`:** Update positioning. The plugin is now positioned as the *research and structured-data layer* that complements FluentCRM 3.0's *generative writing assistance layer*. Make this distinction explicit in the description. Update "Tested up to" and the feature list. Note multi-provider support and the Claude-only web search caveat.

**`CLAUDE.md`:** Add a new section after "Why these architectural decisions" titled "FluentCRM 3.0 integration" that covers the provider bridge, multi-provider architecture, the deliberate decision not to register MCP tools, and the API migration. Future Claude sessions need to know FluentCRM is the source of truth for the API key, not us.

**`docs/fields-reference.md`:** Add a footer note that the fields are MCP-discoverable in 1.0.0+ via FluentCRM's native `get-contact` / `upsert-contact` tools.

---

## Out of scope (deferred)

These were considered and consciously left for a later release:

- **Web search for OpenAI and Gemini.** Each provider has a different web search API (OpenAI Responses API with `web_search_preview`; Gemini's `google_search` grounding tool). Implementing all three properly is significant work and we don't have evidence yet about whether non-Claude enrichment quality is acceptable without it. v1.1 candidate.
- **Custom MCP tool for note full-text search.** One tool, ~50 LOC. Add when admins ask for it.
- **Custom MCP tools for company surface.** Larger gap, but the right fix is in FluentCRM (or a separate, focused plugin). Not this plugin's responsibility.
- **Per-tool MCP capability.** A dedicated `fcrm_enrich_contacts` capability instead of reusing `fcrm_manage_contacts`. Adds setup friction without evidence of need.
- **Bulk individual enrichment via MCP or admin button.** Same rationale as the bulk-resync individual button we didn't build. Revisit if usage data shows demand.
- **Migration to FluentCRM's "Integration" surface.** The Integrations framework doesn't accommodate this plugin's admin shape.

---

## Integrations worth considering

A few smaller integrations surfaced during recon. They don't need to land in v1.0 but they're cheap and they meaningfully improve the experience.

1. **Read FluentCRM's contact summary as additional prompt context.** When we run a contact enrichment, read `_ai_contact_summary` meta (if FluentCRM generated one) and include it in the prompt as "FluentCRM's automated engagement summary." That summary is CRM-data-only — exactly the grounding our research benefits from. Cheap to add.
2. **Mark enrichment stale when underlying data changes.** Hook `fluent_crm/company_updated` and `fluent_crm/subscriber_updated`. If an admin manually edits a company's industry or address (or a contact's company), set `enrichment_status` to `Stale`. A future re-enrichment refreshes; the existing structured data remains visible. Helps surface re-enrichment candidates naturally.
3. **Per-tool description tuning.** Not relevant in v1.0 (we're not registering tools), but the descriptions FluentCRM exposes on our custom fields control how Claude interprets them when reading via `get-contact`. Worth a tuning pass: each field's `description` should be tuned for LLM consumption ("Indicates the contact's giving capacity tier, calibrated against actual giving history and stated capacity. Major = $10K+ annual gift potential; Mid = $1K–$10K; Standard = under $1K; Unknown = insufficient signal."). Pays off for every MCP query that reads our fields.
4. **Filter our existing field descriptions through FluentCRM's settings UI.** No code change needed — just documentation. Admins who customize their `org_focus_areas` list should know that the field description is what tells the LLM how to choose values.

I'd land item 1 in v1.0 (small, meaningful) and items 2–4 in v1.1.

---

## Sequencing

The four reworks are independent but have a natural order:

1. **Provider integration** first. Largest, sets the foundation. Validates the multi-provider bridge before we depend on it elsewhere. *(Shipped in v1.0.0.)*
2. ~~**API migration (`addProfileSection`)**~~ — verified unnecessary; both sections already use the 3.0 signature.
3. **Company module detection** next. Touches several places but each touch is small.
4. **UI restyling** last. Mostly mechanical; can be done incrementally per-section.

A single PR or a four-PR series both work. The four-PR series gives cleaner review and easier rollback if any one rework reveals trouble. The single PR ships faster but reviews as a bigger lump.

---

## Open questions for implementation

These don't need to be answered before starting, but they'll surface during the work:

1. **Exact option key for the company module setting.** `fluentcrm_get_option('general_settings', [])['company_module']` is the likely shape — verify during implementation.
2. **Does `advanced_filters` on `list-contacts` actually accept our custom field slugs?** If yes, enrichment-aware filtering via MCP works out of the box. If no, that becomes a real argument for the deferred `search-notes` and field-filter tools.
3. **Model defaults follow FluentCRM's `$autoProviderModels` table.** Claude → `claude-sonnet-4-6`, OpenAI → `gpt-5.5`, Gemini → `gemini-2.5-flash`. These match what FluentCRM resolves "auto" to internally (`AiController.php:21`). Verify these defaults haven't drifted before each major release of our plugin — they're FluentCRM's call, not ours.
4. **JSON output mode reliability across providers.** Claude's prompt-based JSON output, OpenAI's `response_format`, and Gemini's `responseSchema` are not equivalent. We need test runs of all three before v1.0 ships to confirm non-Claude providers produce parseable structured output reliably.
5. **What happens if FluentCRM removes a provider** from `ai_settings`? Our bridge should fail gracefully. Already covered by the "Not configured" banner state, but worth testing.
6. **Filing an issue with WPManageNinja** to make `callProviderApi` public or to extract the three provider methods into a service class. Worth doing — would let us drop the provider adapters in a future release.
7. **Filing a second issue with WPManageNinja** asking for an extension hook to register pages inside FluentCRM's SPA — a `fluent_crm/spa_routes` filter or similar that lets external plugins contribute Vue components. Right now the SPA is a closed surface for plugins outside FluentCRM's build. An official hook would let us migrate from the `core_menu_items` + separate-WP-page pattern to true SPA integration without forking their build.

---

## Verification plan

Before each rework PR merges:

- **Provider integration:** With each of the three providers configured in FluentCRM AI settings, run a contact enrichment. Verify the API call goes out, the model is the one configured in FluentCRM, the response is parsed normally. For Claude, verify web search runs and citations appear in the note. For OpenAI/Gemini, verify the note renders without citations and structured fields populate correctly. Then unset the provider and verify the "Not configured" banner appears and Enrich buttons disable.
- **API migration:** Reload a contact profile page in 3.0. Verify the Enrichment section renders, the Enrich button works, the ajax handler fires.
- **Company module detection:** Toggle the company module on/off in FluentCRM settings. Verify our admin tabs, profile section, and field definitions appear/disappear correctly. Verify contact-side enrichment is unaffected in either state.
- **UI restyling:** Visual check on both contact and company profile pages. Compare against a built-in section (WooCommerce orders, Form Submissions) — our section should look like a peer, not a foreign object.
- **Admin entry placement:** After registering via `fluent_crm/core_menu_items`, verify the "Contact Enrichment" entry appears in FluentCRM's submenu. Click it and verify our admin page loads with the FluentCRM stylesheet applied. Verify the old WordPress Settings → Contact Enrichment entry is gone. Verify the page renders correctly for a user with `fcrm_manage_settings` but not full WordPress admin privileges.
- **MCP discoverability** (no code change, but worth verifying once): Use Claude Desktop with Application Password auth, point it at `/wp-json/fluent-crm/mcp`, and verify that `get-contact` returns our `org_*` / `individual_*` field values and our enrichment notes inline. Verify `upsert-contact` can write field values. Confirm the documented "MCP-discoverable in 1.0.0+" claim in `fields-reference.md`.

---

## References

Decisions in this document were grounded in two sources:

- **The FluentCRM 3.0 source code** at `../fluent-crm/` and `../fluentcampaign-pro/` — for current behavior and the AI controller internals that motivated the multi-provider client decision.
- **The FluentCRM developer documentation** at https://github.com/fluentcrm/fluent-crm-developers-docs — authoritative for filter/action hook names, parameter signatures, and the documented extension surfaces. Specifically: `src/hooks/filters/admin-and-dashboard.md` (the `core_menu_items` registration pattern), `src/hooks/filters/webhooks-and-integrations.md` (confirming the integration filters are narrow-role), `src/modules/index.md` (the canonical list of public extension points).

See `CLAUDE.md` → "External references" for the clone/refresh convention.
