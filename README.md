# FluentCRM Contact Enrichment

A WordPress plugin that adds AI-powered research surfaces to [FluentCRM](https://fluentcrm.com), using the [Anthropic Claude API](https://docs.anthropic.com) with web search.

Two parallel research paths:

- **Company research** — Click "Enrich" on a FluentCRM company profile. The plugin researches the organization, returns structured fields (org type, sector, employees, revenue, geographic scope, focus areas, partnership models, alignment score), fills FluentCRM's native company columns (industry, description, address, social URLs) when empty, and writes a four-section narrative note. The structured fields mirror onto every contact whose primary company is that company, so they're filterable in FluentCRM's contact segment builder.
- **Individual contact research** — Click "Enrich" on a contact profile. The plugin researches the *person* (career, philanthropic / leadership / decision-making background per use case, alignment with the requesting org's mission), returns 5 structured fields plus a four-section narrative note. Grounded in [Apra's professional ethics standards](https://www.aprahome.org/Resources/Statement-of-Ethics) with a per-contact opt-out flag.

The plugin is general-purpose. Use cases are framed by **admin-configurable Markdown context modules** that ground each research surface in the organization's priorities. A nonprofit's modules describe donor research; a leadership-development program's would describe cohort prep; a B2B sales team's would describe stakeholder research. The plugin doesn't bake in a single use case.

## Status

Active development. The plugin runs in production on at least one site, has been validated against real enrichment workloads, and follows semantic versioning. See the [Changelog section in readme.txt](readme.txt) for release-by-release detail.

## Installation

### As a WordPress plugin

```bash
cd wp-content/plugins
git clone https://github.com/WeMakeGood/fluentcrm-contact-enrichment.git
```

Then activate via the WordPress plugin admin. The plugin bundles its only Composer dependency ([Parsedown](https://github.com/erusev/parsedown)) in `vendor/`, so you don't need to run `composer install` for end-user installations.

### Prerequisites

- WordPress 5.8+
- PHP 7.4+
- [FluentCRM](https://fluentcrm.com) (free or pro), installed and active
- An [Anthropic API key](https://console.anthropic.com) with web search enabled at the org level (verify in [Claude Console privacy settings](https://platform.claude.com/settings/privacy))

### Development setup

```bash
git clone https://github.com/WeMakeGood/fluentcrm-contact-enrichment.git
cd fluentcrm-contact-enrichment
composer install   # installs Parsedown + dev dependencies
```

The plugin loads cleanly without `composer install` because `vendor/` is committed; running install is only needed if you want the dev dependencies (`wp-cli/i18n-command` for i18n tooling).

## Architecture

### Components

```
fluentcrm-contact-enrichment/
├── fluentcrm-contact-enrichment.php   bootstrap, constants, hook wiring
├── includes/
│   ├── class-field-registrar.php      auto-create + heal field definitions
│   ├── class-context-modules.php      Company + Contact context module storage
│   ├── class-claude-client.php        Anthropic Messages API HTTP client
│   ├── class-data-mapper.php          JSON extraction + value validation
│   ├── class-contact-sync.php         push company-cached values to contacts
│   ├── class-enrichment-job.php       WP-Cron handlers for both surfaces
│   ├── class-admin-settings.php       Settings → Contact Enrichment (6 tabs)
│   ├── class-company-section.php      company profile section + Enrich button
│   └── class-contact-section.php      contact profile section + Enrich button
├── docs/
│   ├── fluentcrm-enrichment-research.md   pre-build recon, design decisions, deferred features
│   └── fields-reference.md                operational reference for all custom fields
├── readme.txt    WordPress.org-format readme (admin-facing)
├── CLAUDE.md     engineering context for future maintainer / AI sessions
└── README.md     this file
```

### Data flow

```
[Admin clicks Enrich on a company or contact]
    ↓
[admin-ajax.php — capability + nonce checks, status flip, schedule cron]
    ↓
[WP-Cron fires fce_run_enrichment_job (companies) or fce_run_contact_enrichment_job (contacts)]
    ↓
[Build system prompt: research discipline + admin's active context modules + schema]
    ↓
[POST https://api.anthropic.com/v1/messages with web_search_20250305 tool enabled]
    ↓
[Parse JSON from response, validate against allowed-options lists]
    ↓
[Write structured fields + create narrative note (CompanyNote for orgs, SubscriberNote for contacts)]
    ↓
[For company enrichment: mirror values onto every contact where company_id matches]
```

The contact research path adds an extra step at the top: `individual_research_consent` is checked before any other work. If the contact has consent set to "Restricted," the cron handler short-circuits and no API call is made.

### Key integration points

The plugin exposes one extension hook of its own and integrates cleanly with one external hook in the sister plugin:

- **`fcr_excluded_field_slugs`** (filter, defined in [fluentcrm-company-rollups](https://github.com/WeMakeGood/fluentcrm-company-rollups), hooked here) — the plugin contributes its 17 plugin-managed contact field slugs so they're excluded from rollup configuration. The values are intrinsic to each person or mirrored from companies; rolling them up across contacts always returns the same value and is meaningless.
- **`fluent_crm/admin_vars`** (FluentCRM's filter) — the plugin filters out the 3 company-side enrichment status fields from FluentCRM's profile sidebar to avoid duplication with the plugin's own profile section. The 14 other plugin-managed fields are deliberately left visible because they're useful in FluentCRM's segment builder, list-view filter chips, and custom column dropdown.
- **WP-Cron hooks** — `fce_run_enrichment_job` (company) and `fce_run_contact_enrichment_job` (contact) are the cron entry points. Other plugins or custom code can dispatch them directly via `do_action()` if you need to trigger enrichment programmatically.

### Custom fields

The plugin creates and manages custom fields in FluentCRM. See [docs/fields-reference.md](docs/fields-reference.md) for the full operational reference (slugs, types, allowed values, fallbacks, storage paths, segmenting guidance). Quick map:

| Surface | Slugs | Group |
|---|---|---|
| Company status | `enrichment_status`, `enrichment_date`, `enrichment_confidence` | Enrichment |
| Company-side org cache (mirrored to contacts) | `org_type`, `org_sector`, `org_employees`, `org_revenue`, `org_geo_scope`, `org_focus_areas`, `org_partnership_models`, `org_alignment_score` | Enrichment — Org Profile / — Alignment |
| Contact-side individual research | `individual_capacity_tier`, `individual_alignment`, `individual_engagement_readiness`, `individual_prior_relationship`, `individual_relevant_signals_present` | Enrichment — Individual |
| Contact-side individual status | `individual_enrichment_status`, `individual_enrichment_date`, `individual_enrichment_confidence`, `individual_research_consent` | Enrichment — Individual Status |

Field definitions are auto-created on activation and idempotent on re-activation. A heal pass migrates existing data when field shapes change between releases.

### Configuration

All admin configuration lives at **Settings → Contact Enrichment**:

- **API Settings** — Anthropic API key (encrypted at rest using WordPress auth salts), model selection, max searches per enrichment, Test Connection button
- **Contact Context** — Markdown modules framing the use case for individual research (donor prospecting, cohort prep, sales, board recruitment). Includes three starter examples.
- **Company Context** — Markdown modules framing how the requesting organization thinks about company research. Includes one starter example.
- **Focus Areas** — admin-editable values for the `org_focus_areas` multi-select field
- **Capacity Tiers** — admin-editable values for the `individual_capacity_tier` field (defaults are donor-flavored; rewrite for non-fundraising use cases)
- **Danger Zone** — bulk "Resync all contacts" with typed confirmation gate

The Getting Started panel above the tabs auto-hides once at least one enrichment surface is fully configured. It re-appears if anything regresses.

## Privacy and ethics

Individual research is grounded in [Apra's Statement of Ethics](https://www.aprahome.org/Resources/Statement-of-Ethics):

- **Source provenance.** Public sources only. Inline citation of every claim with Markdown links to source URLs.
- **Relevance.** Research restricted to information bearing on the use case the admin's context modules define. Personal-life details, family information, and aggregator-site data are out of scope by prompt design — even if findable.
- **Confidentiality.** Per-contact `individual_research_consent` field gates research at the cron-job level. Restricted contacts cannot be researched even if an admin clicks Enrich.
- **Honest uncertainty.** "Unknown" is the expected answer for non-public-figure subjects. Confidence values are calibrated low by design.

The privacy posture is documented at length in [docs/fluentcrm-enrichment-research.md](docs/fluentcrm-enrichment-research.md#individual-contact-research-added-v070).

## Cost

Each enrichment makes one Anthropic Messages API call with web search enabled.

- **Web search:** $10 per 1,000 searches. Typical company-research call: 6–10 searches. Typical contact-research call: 5–8.
- **Tokens:** Sonnet 4.6 default at $3/MTok input + $15/MTok output. A typical enrichment with a moderate-sized context module runs ~$0.05–$0.15 total (search + tokens).

The plugin's deferred-feature analysis covers prompt caching as a potential cost optimization. See [docs/fluentcrm-enrichment-research.md](docs/fluentcrm-enrichment-research.md#future-consideration-prompt-caching-and-files-api).

## Development

### Coding conventions

- WordPress core style (snake_case methods, `wp_*` for utilities), not PSR
- Function and constant prefix `FCE_*` / `fce_*`
- Capability check + nonce check on every form post and AJAX handler
- Sanitize on save, escape on render
- All FluentCRM data writes go through public APIs (`FluentCrmApi('companies')->createOrUpdate`, `Subscriber::syncCustomFieldValues`, model `::create()`) — no direct `wpdb` writes to FluentCRM tables

### Testing

The plugin has been verified live throughout its development against a real WordPress + FluentCRM installation. Each release commit's message documents what was verified. There's no automated test suite; verification is manual and tied to release commits.

### Engineering documentation

- **[CLAUDE.md](CLAUDE.md)** — engineering context for future maintainers (and AI assistants) working on the plugin. Documents non-obvious decisions, FluentCRM API quirks, and the rationale behind specific design choices.
- **[docs/fluentcrm-enrichment-research.md](docs/fluentcrm-enrichment-research.md)** — pre-build reconnaissance, decisions made during the build, deferred features (prompt caching, Files API), and the research-doc record for individual contact research.
- **[docs/fields-reference.md](docs/fields-reference.md)** — operational reference for all custom fields the plugin creates and manages.

### Contributing

Issues and pull requests welcome on [GitHub](https://github.com/WeMakeGood/fluentcrm-contact-enrichment). Substantive PRs benefit from a brief discussion in an issue first so we can align on approach.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full text. Bundled dependency:

- [erusev/parsedown](https://github.com/erusev/parsedown) — MIT license

## Repository

- GitHub: <https://github.com/WeMakeGood/fluentcrm-contact-enrichment>
- Sister plugin: [fluentcrm-company-rollups](https://github.com/WeMakeGood/fluentcrm-company-rollups) — adds aggregation rollups to the FluentCRM company profile
- Owner: [Make Good](https://wemakegood.org)
