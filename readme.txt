=== FluentCRM Contact Enrichment ===
Contributors: wemakegood
Tags: fluentcrm, crm, claude, anthropic, ai, enrichment, research, fundraising, prospect research
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Research companies and individual contacts in FluentCRM using the Claude API. Structured field outputs and narrative notes, grounded in your organization's framing.

== Description ==

FluentCRM Contact Enrichment adds two research surfaces to FluentCRM:

**Company research.** An "Enrich" button on every FluentCRM company profile. Clicking it sends Claude (Anthropic's AI) to research the organization with web search, returning structured fields (organization type, sector, employee range, revenue range, geographic scope, focus areas, partnership models, alignment score) plus a four-section narrative note. The structured fields are mirrored onto every contact whose primary company is that company, so they're filterable and segmentable in FluentCRM.

**Individual contact research.** A separate "Enrich" button on every contact profile, designed for use cases where the *person* is the research target — donor prospecting, cohort program participant prep, B2B sales / partnership stakeholder research, board recruitment. Grounded in Apra's professional standards for prospect research: integrity, accuracy, source provenance, and the *relevance principle* (research restricted to information bearing on the relationship the requesting organization is trying to build). Includes a per-contact opt-out flag that blocks research at the cron-job level — no API call is made for contacts whose research consent is set to Restricted.

= Designed for general use =

The plugin is general-purpose. The two surfaces share the same machinery; the differentiator is **admin-configurable Markdown context modules** that ground each surface in your organization's priorities and use case. A nonprofit fundraising team's modules describe donor research; a leadership-development program's would describe cohort prep; a B2B sales team's would describe stakeholder research. The plugin doesn't bake in any single use case.

= What gets created =

On activation, the plugin creates the FluentCRM custom fields it needs (no overwrites of existing fields with the same slugs):

* **Company fields:** Enrichment Status, Date Enriched, Enrichment Confidence, plus mirrored org_* fields for the 8 org-research outputs.
* **Contact fields (org research):** 8 org_* fields mirrored from the company side, used for FluentCRM segment-builder filtering.
* **Contact fields (individual research):** 4 individual_* status fields (status, date, confidence, research consent) plus 5 individual_* outputs (capacity tier, alignment, engagement readiness, prior relationship, relevant signals present).

The plugin also fills FluentCRM's *built-in* company columns when they're empty (industry, description, address, LinkedIn / Facebook / Twitter URLs, employee count, website) — but only when empty, never overwriting admin-curated values.

= Privacy and ethics =

Individual research is grounded in [Apra's Statement of Ethics](https://www.aprahome.org/Resources/Statement-of-Ethics) — the canonical professional standard for prospect research. The system prompt enforces:

* **Source provenance.** Public sources only. Inline citation of every claim.
* **Relevance.** Research restricted to information bearing on the use case the admin's context modules define.
* **Confidentiality.** No personal-life details, family information, or aggregator-site data. The Apra Social Media Responsibility principle applies (professional public information is fair game; personal social media beyond professional context is not).
* **Honest uncertainty.** "Unknown" is the expected answer for many fields, especially for non-public-figure subjects. The plugin's confidence values are calibrated low by design.

A per-contact `individual_research_consent` field (default Allowed) lets admins block research on specific contacts. When set to Restricted, the cron job short-circuits before any API call is made.

== Installation ==

1. Install [FluentCRM](https://wordpress.org/plugins/fluent-crm/) and activate it. The plugin will not function without FluentCRM.
2. Upload the plugin folder to `wp-content/plugins/fluentcrm-contact-enrichment/`, or install through the WordPress plugin admin.
3. Activate FluentCRM Contact Enrichment.
4. Go to **Settings → Contact Enrichment**. The Getting Started panel walks you through the three steps to be ready to enrich:
   * Add your Anthropic API key (sign up at [console.anthropic.com](https://console.anthropic.com))
   * Configure at least one Company Context module (for company research) or Contact Context module (for individual research) — both tabs include starter examples you can copy
   * Click "Test Connection" on the API Settings tab to confirm the key works and that web search is enabled at your Anthropic organization level
5. Open any FluentCRM company or contact profile. The "Enrichment" section in the sidebar carries the Enrich button.

= Required configuration =

* An Anthropic API key with web search enabled at the organization level. You can verify this in your [Claude Console privacy settings](https://platform.claude.com/settings/privacy) — look for the Web Search toggle.
* FluentCRM 2.5 or later, installed and active.

== Frequently Asked Questions ==

= Does this work without FluentCRM? =

No. FluentCRM is a hard dependency. Plugin activation registers FluentCRM-specific custom fields and profile sections.

= What does enrichment cost? =

Each enrichment makes one Anthropic Messages API call with web search enabled. Web search is billed at $10 per 1,000 searches plus token costs. A typical company-research call uses 6–10 searches; a contact-research call typically uses 5–8. At Sonnet 4.6 pricing, a single enrichment runs about $0.05–$0.15 depending on context module size and search count. Check current pricing at [anthropic.com/pricing](https://www.anthropic.com/pricing).

= Can I use this for non-fundraising research? =

Yes. The plugin is intentionally general-purpose. The Contact Context settings tab includes starter examples for fundraising prospect research, cohort program participant prep, and B2B sales / partnership stakeholder research. The "capacity tier" field's allowed values are admin-configurable on the Capacity Tiers tab — defaults are donor-flavored (Major / Mid / Standard / Unknown) but you can rewrite them to fit your use case (e.g. Senior Leader / Mid-Career / Emerging / Unknown for cohort programs).

= How do I prevent research on a specific contact? =

Set their `Research Consent` field (in the Custom Profile Data sidebar) to "Restricted." When you click Enrich on that contact, the plugin will set status to Restricted and return without making an API call or writing any data. This is the load-bearing privacy mechanism — it works at the cron-job level, not just the UI.

= How is the research grounded in my organization's priorities? =

Two settings tabs hold the framing:

* **Company Context** — Markdown modules describing your mission, what alignment means to you, partnership models you actually use, geographic priorities. Used by company research.
* **Contact Context** — Markdown modules describing your use case for individual research (donor prospecting, cohort prep, sales, board recruitment), what relevant means for that use case, and any practitioner conventions. Used by contact research.

Each tab includes a "Show example" collapsible block with starter content you can copy and adapt. Active modules are concatenated into the system prompt that goes to Claude on every enrichment, so the AI reads them as instructions before researching anything.

= What happens if research fails? =

The status field flips to Failed and a clearly-titled error note is added to the record (a CompanyNote for company enrichment, a SubscriberNote for contact enrichment) describing what went wrong. You can re-run by clicking Enrich again — same-day re-runs replace the failure note rather than piling them up.

= Can I segment FluentCRM contacts on enrichment values? =

Yes for contact-side enrichment values. The org_* fields (mirrored from the company) and individual_* fields (intrinsic to the contact) are real FluentCRM contact custom fields, so they appear in the segment builder, dynamic segments, automation conditions, and email targeting. The company-side enrichment fields appear on the company profile but are not segmentable as company fields — that's a FluentCRM limitation, not a plugin limitation.

= Does the plugin overwrite existing data? =

No. The plugin's "fill if empty" rule applies to FluentCRM's native company columns (industry, description, address, social URLs, employee count, website) — admin-curated values are never overwritten. Custom field values are written by the enrichment job and replace prior values from the same job, but unrelated custom fields on the same record are untouched. The same-day note replacement rule means re-clicking Enrich on the same day updates today's note rather than creating duplicates.

= Are research notes saved? =

Yes. Every successful enrichment writes a four-section narrative note attached to the record — a CompanyNote for company research ("Enrichment Research — YYYY-MM-DD") and a SubscriberNote for contact research ("Contact Research — YYYY-MM-DD"). The notes use proper Markdown rendering, including clickable links to source pages Claude found during research. They're visible in the FluentCRM Notes tab on the relevant profile.

= What if I move a contact between companies? =

The contact-side org_* fields stay populated with the previous company's values until either (a) the new company is enriched (which writes the new values), or (b) the admin clicks "Sync to Contacts" on the new company's profile section. The bulk "Resync all contacts" Danger Zone in the settings page can fix drift across many companies at once if needed.

== Changelog ==

= 0.8.2 =
* Documentation-only release. Rewrites readme.txt as legitimately user-facing documentation covering both research surfaces, the Apra-grounded privacy posture for individual research, and an expanded FAQ. Updates the plugin header description to reflect both surfaces. Adds a separate README.md as the GitHub landing page (developer-facing, with architecture overview, integration points, and links to engineering docs).

= 0.8.1 =
* Reorders the settings tabs so Contact Context appears before Company Context, reflecting the more common usage pattern.

= 0.8.0 =
* New Getting Started panel that walks first-time admins through the three setup steps. Auto-hides once at least one enrichment surface is configured.
* Both context-module tabs now include collapsible "Show example" blocks with starter Markdown.
* Test Connection success message points to the next setup step when no context modules are configured yet.

= 0.7.1 =
* Internal cleanup: removed dead `cited_text` plumbing from the Claude client and renamed org-side mapper constants for clarity. No user-facing changes.

= 0.7.0 =
* Adds individual contact research as a parallel surface to company research. The plugin now answers two distinct questions: "what kind of organization is this?" (company side) and "who is this person, and how should we engage them?" (contact side).
* Use cases supported: nonprofit fundraising prospect research, cohort program participant prep, B2B sales / partnership stakeholder research, board recruitment.
* Grounded in Apra's Statement of Ethics with explicit relevance, source-provenance, and confidentiality gates.
* New contact custom fields, "Individual Enrichment" profile section on contacts, per-contact `individual_research_consent` opt-out, two new admin settings tabs (Contact Context, Capacity Tiers).

= 0.6.1 =
* Excludes plugin-managed contact fields from the FluentCRM Company Rollups plugin's configuration UI and computation.

= 0.6.0 =
* Per-company "Sync to Contacts" button on company profiles. Bulk "Resync all contacts" Danger Zone in admin settings.

= 0.5.1 =
* Narrowed the admin-vars filter so only the three enrichment status fields are hidden from FluentCRM's company surfaces. The 8 org_* fields stay visible for filtering and segmentation.

= 0.5.0 =
* The enrichment status fields no longer appear in FluentCRM's "Custom Data" sidebar — rendered in the plugin's own section instead.

= 0.4.0 =
* The 8 org_* enrichment values are now also cached on the company record. Reactivation runs a one-time heal pass to migrate existing data.

= 0.3.0 =
* Contact-side `org_sector` field now uses FluentCRM's canonical 147-item industry list (the same vocabulary the company-profile Industry dropdown uses).

= 0.2.0 =
* Enrichment also fills FluentCRM's native company fields when they're empty: industry, description, address, LinkedIn/Facebook/Twitter URLs, employee count.

= 0.1.0 =
* Initial release: Enrich button on company profile, WP-Cron background job, Claude API integration with web search, structured fields plus four-section narrative note.
