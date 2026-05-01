=== FluentCRM Contact Enrichment ===
Contributors: wemakegood
Tags: fluentcrm, crm, claude, anthropic, enrichment, research
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enrich FluentCRM company records using the Claude API. Researches the organization, writes structured org-profile fields to linked contacts, and stores a narrative note.

== Description ==

FluentCRM Contact Enrichment adds an "Enrich" button to FluentCRM company profiles. When clicked, the plugin schedules a background job that:

1. Calls the Claude API with web search enabled to research the organization.
2. Parses a structured JSON response.
3. Writes organization-profile custom fields to every contact whose primary company matches the company being enriched (organization type, sector, employee range, revenue range, geographic scope, focus areas, partnership models, alignment score).
4. Saves a narrative four-section research note on the company record.

The plugin is general-purpose. Customization happens through admin-configurable Markdown context modules that ground the research in your organization's priorities and framing.

= Required configuration =

* An Anthropic API key with web search enabled at the organization level (see https://platform.claude.com/settings/privacy).
* FluentCRM must be installed and active.

= How enrichment is scoped =

Enrichment writes to the **primary** `company_id` on each contact record. Contacts associated to a company only via the many-to-many pivot table are not updated, by design — to avoid double-writing when a contact has more than one company association.

= Custom fields created on activation =

On the company record, in group "Enrichment":

* Enrichment Status (Not Enriched / Pending / Processing / Complete / Failed)
* Date Enriched
* Enrichment Confidence (High / Medium / Low)

On contact records, in group "Enrichment — Org Profile":

* Organization Type, Sector / Industry, Employee Range, Revenue Range
* Geographic Scope (multi-select)
* Focus Areas (multi-select; admin-configurable options)
* Partnership Models (multi-select)

On contact records, in group "Enrichment — Alignment":

* Alignment Score (Strong / Moderate / Weak / Unknown)

Existing fields with the same slugs are not overwritten.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install via the WordPress plugin admin.
2. Ensure FluentCRM is installed and active.
3. Activate FluentCRM Contact Enrichment.
4. Visit Settings → Contact Enrichment to add your Anthropic API key, configure context modules, and review focus-area options.
5. Open any FluentCRM company profile and click "Enrich This Company."

== Frequently Asked Questions ==

= Does this work without FluentCRM? =

No. FluentCRM is a hard dependency.

= What does enrichment cost? =

Each enrichment makes one Anthropic Messages API call with web search. Web search is billed at $10 per 1,000 searches plus token costs. A typical org-research call uses 6–10 searches. Check current pricing at https://www.anthropic.com/pricing.

= How is the research grounded in my organization's priorities? =

In Settings → Contact Enrichment → Context Modules, add Markdown modules describing your mission, what alignment means to you, partnership priorities, geographic focus, etc. Each active module is injected into the research prompt in order, so Claude reads them as part of its instructions before researching the target organization.

= What happens if a research run fails? =

The company's Enrichment Status flips to Failed and a note is added describing the failure. You can re-run by clicking Enrich again.

= Can I see the enrichment in the company list, or filter on it? =

The Enrichment Status field appears on the company record. FluentCRM does not currently expose extension points for company list columns or segment filters that would let custom company fields appear in those surfaces.

== Changelog ==

= 0.7.1 =
* Internal cleanup, no user-facing changes. Removed dead `cited_text` plumbing from the Claude client (an early citation-handling experiment that was never wired into anything) and clarified the org-side mapper's constants (`ORG_SINGLE_SELECT_FALLBACKS`, `ORG_MULTI_SELECT_FIELDS`) so they don't mislead about the contact-side mapper.

= 0.7.0 =
* Adds individual contact research as a parallel surface to the existing company research. The same plugin now answers two distinct questions: "what kind of organization is this?" (company side, since v0.1.0) and "who is this person, and how should we engage them?" (contact side, new in v0.7.0).
* Use cases the contact-research surface supports: nonprofit fundraising prospect research, cohort program participant prep, B2B sales / partnership stakeholder research, board recruitment. The framing comes from admin-configurable contact context modules; the plugin doesn't bake in a single use case.
* Grounded in Apra's Statement of Ethics — Integrity, Accuracy, Accountability, Confidentiality, Source Provenance, and the Relevance principle (research restricted to information bearing on the relationship the requesting organization is trying to build). The system prompt enforces this discipline on every contact enrichment.
* New contact custom fields: `individual_enrichment_status`, `individual_enrichment_date`, `individual_enrichment_confidence`, `individual_research_consent` (status), plus five research outputs (`individual_capacity_tier`, `individual_alignment`, `individual_engagement_readiness`, `individual_prior_relationship`, `individual_relevant_signals_present`). Capacity tier values are admin-configurable so non-fundraising use cases can rewrite them.
* New "Individual Enrichment" profile section on the contact, with status display, an Enrich/Re-enrich button, and a link to the most recent research note.
* Per-contact opt-out via the `individual_research_consent` field (default Allowed). Setting it to Restricted blocks enrichment for that contact; the cron job short-circuits before any API call is made.
* Two new admin settings tabs: Contact Context (Markdown modules framing the use case) and Capacity Tiers (admin-editable values for the capacity tier field).
* Output is written as a four-section subscriber note: Personal Context, Relevant Background, Alignment Assessment, Recommended Approach. Same-day re-runs replace the existing note; cross-day re-enrichments preserve historical analyses.

= 0.6.1 =
* Excludes the 8 org_* contact custom fields from the FluentCRM Company Rollups plugin's configuration UI and computation (when that plugin is also active, version 0.2.0+). Rolling up enrichment values across contacts always returned the same value (since they're mirrored from the company record) and was confusing in the rollup section.

= 0.6.0 =
* Adds two sync surfaces backed by the company-side org_* cache introduced in v0.4.0:
  * **Per-company "Sync to Contacts"** button on the company profile section. Reads the company's cached enrichment values and pushes them to every primary-linked contact's custom fields. No API call, no cron — fast and free. Use it when a contact is added to an already-enriched company, or when contact values have drifted from the company.
  * **Bulk "Resync all contacts" Danger Zone tab** in Settings → Contact Enrichment. Walks every company that has cached enrichment values and resyncs all their primary contacts at once. Typed-confirmation (RESYNC) gate, synchronous execution, summary count of companies processed and skipped on completion.
* Both surfaces convert FluentCRM's internal multi-select array format (used on the company side) back to comma-joined strings before writing to contacts, so the contact-side format stays consistent.
* Companies without cached enrichment values are skipped in the bulk run and noted in the success message.

= 0.5.1 =
* Narrowed the v0.5.0 admin-vars filter so only the three enrichment status fields (Enrichment Status, Date Enriched, Confidence) are hidden from FluentCRM's company surfaces. The 8 org_* fields are now visible everywhere again — Custom Data sidebar, list-view filter chips, and list-view custom column dropdown — restoring company-level filtering and segmentation that v0.5.0 inadvertently removed.
* The plugin's Enrichment section header still shows the three status fields. The 8 org_* fields are no longer duplicated in the section since FluentCRM's sidebar handles their display.

= 0.5.0 =
* The 11 enrichment fields no longer appear in FluentCRM's "Custom Data" sidebar on the company profile. They now render in the plugin's own Enrichment section instead, organized by group (Enrichment / Org Profile / Alignment) for cleaner reading.
* Field definitions still exist in FluentCRM's normal management UI (Settings → Custom Fields) — only the company profile's display surface is filtered. All read/write paths continue to work identically.

= 0.4.0 =
* The 8 org_* enrichment values (org_type, org_sector, org_employees, org_revenue, org_geo_scope, org_focus_areas, org_partnership_models, org_alignment_score) are now also cached on the company record. Previously they lived only on contacts, which made the company the wrong source of truth for organizational data. Now the company is canonical.
* Reactivation runs a one-time heal pass: for each company that has an enriched contact but no cached org_* values, copies the most-recently-updated contact's values to the company.
* Enrichment runs now write to both surfaces in a single pass.
* This sets up the next release's "Sync to Contacts" buttons.

= 0.3.0 =
* Contact-side `org_sector` field now uses FluentCRM's canonical 147-item industry list (the same vocabulary FluentCRM's company-profile Industry dropdown uses). Previously used a separate 10-item list which produced inconsistent values between the two fields.
* `org_sector` is now derived from the native `industry` value rather than asked of Claude separately — guarantees the contact-side and company-side industry values always match.
* On upgrade, stored `org_sector` values that aren't in the new list are cleared. Re-enrichment refills them. No mapping is performed; the old vocabulary doesn't have clean targets in the FluentCRM list.

= 0.2.0 =
* Enrichment now also fills FluentCRM's native company fields when they're empty: Industry (validated against FluentCRM's 147-item industry list), Description (1–2 sentence summary), Headquarters address (city, state, postal code, country, street), LinkedIn / Facebook / Twitter URLs, and Number of Employees (derived from the org_employees bucket midpoint).
* Native fields are only written when the existing column is empty. Admin-curated values are never overwritten.
* The success note footer lists which native fields were populated for the run.

= 0.1.0 =
* Initial release.
* Auto-creates company and contact custom fields.
* Admin settings page with API key, context modules, and focus-area configuration.
* Enrich button on company profile triggers a WP-Cron background job.
* Claude API integration with web search; structured JSON response mapped to FluentCRM fields.
* Four-section narrative note created on company record.
* Org-profile fields pushed to all contacts with primary company_id matching the company.
