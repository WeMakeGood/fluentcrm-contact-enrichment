<script setup>
// Thin wrapper around ContextModulesTab for the contact (individual
// research) surface. All UI is in the shared component; this file
// just supplies surface-specific copy and starter examples.

import ContextModulesTab from './ContextModulesTab.vue';

const starterExamples = [
  {
    title: 'donor prospect research',
    body: `# Who we are and what we're researching for

We are [organization name], [one-sentence mission statement]. We're researching individuals as part of donor cultivation, with the goal of identifying people whose values, capacity, and life circumstances suggest they might support our mission.

# What relevant means for us

For each contact, we want to know:
- Their professional context (current role, organizational affiliations, public bio)
- Their philanthropic history (known giving, board service, charitable involvement, recognition in capital campaigns)
- Their alignment with our mission, specifically: [list the 2–3 mission dimensions that matter most]
- Their prior relationship to us (alumni, prior gift, board overlap, mission-aligned advocacy)
- Receptivity signals (recent life events, retirement, new role, public statements about giving)

# What we don't want

- Personal life details unrelated to the giving relationship
- Family information beyond what's professionally public
- Social media content beyond professional context
- Speculative wealth estimates from aggregator sites

# Capacity tiers

When you assign a capacity tier, use:
- Major: indicators of giving capacity at our major-gift threshold ([dollar range if you have one])
- Mid: indicators of meaningful but not transformational giving capacity
- Standard: no specific capacity signals, treat as a standard prospect
- Unknown: insufficient public information to estimate capacity`,
  },
  {
    title: 'cohort program participant prep',
    body: `# Who we are and what we're researching for

We are [program name], a [duration] cohort-based program for [participant description]. We're researching incoming participants so facilitators have context before the cohort starts. The goal is preparation, not gatekeeping.

# What relevant means for us

For each participant, we want to know:
- Their leadership role and current scope of responsibility
- The kinds of challenges they're publicly working on
- Prior leadership development they've engaged with (if any)
- Their organizational context (size, sector, stage)
- Topics they've written or spoken about

# What we don't want

- Personal life details unrelated to their professional development
- Speculation about how they'll perform in the cohort
- Anything that would feel surveillance-like if the participant read it

# Capacity tiers (we use leadership stages instead of fundraising tiers)

When you assign a capacity tier, use the values configured in our Capacity Tiers tab:
- Senior Leader: 15+ years experience, executive scope
- Mid-Career: 7-15 years, expanding scope
- Emerging: <7 years, currently growing into leadership
- Unknown: insufficient public information`,
  },
  {
    title: 'B2B sales / partnership stakeholder research',
    body: `# Who we are and what we're researching for

We are [company name], [what we sell or offer]. We're researching individual stakeholders at companies we're considering as partners or customers. The goal is to understand decision authority, organizational role, and what would make engagement valuable to them.

# What relevant means for us

For each contact, we want to know:
- Their role and decision-making authority within their company
- Their professional history relevant to our offering
- Topics they've written or spoken about that relate to what we sell
- Public statements about their company's strategy that bear on whether our offering would help
- Existing connections to our company or its founders

# What we don't want

- Personal life details
- Compensation or wealth indicators (not relevant for B2B)
- Anything that would feel intrusive if surfaced in an outreach email`,
  },
];
</script>

<template>
  <ContextModulesTab
    surface="contact"
    title="Contact Context"
    intro="Markdown injected into every contact-research enrichment prompt. Define what your organization considers relevant for individual research — your mission, what alignment means for individuals, the use case (donor research, cohort prep, sales, board recruitment), and any practitioner conventions."
    lookup-fields-help="Fields whose values get injected into the enrichment prompt as 'existing data on file.' Use this for fields that hold stronger signal than Claude can find publicly — giving totals from external systems, WooCommerce purchase history, course completion records, internal scoring. The structured outputs (capacity tier, prior relationship, engagement readiness) get calibrated against this data."
    :starter-examples="starterExamples"
  />
</template>
