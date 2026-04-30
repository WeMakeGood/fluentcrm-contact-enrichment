=== FluentCRM Contact Enrichment ===
Contributors: wemakegood
Tags: fluentcrm, crm, claude, anthropic, enrichment, research
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
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

= 0.1.0 =
* Initial release.
* Auto-creates company and contact custom fields.
* Admin settings page with API key, context modules, and focus-area configuration.
* Enrich button on company profile triggers a WP-Cron background job.
* Claude API integration with web search; structured JSON response mapped to FluentCRM fields.
* Four-section narrative note created on company record.
* Org-profile fields pushed to all contacts with primary company_id matching the company.
