<script setup>
// Thin wrapper around ContextModulesTab for the company surface.

import ContextModulesTab from './ContextModulesTab.vue';

const starterExamples = [
  {
    title: 'a starter Company Context module',
    body: `# About our organization

We are [organization name], [one-sentence description of mission and population served]. We work primarily in [geographic scope] and partner with organizations whose values and capacity align with our work.

# What "alignment" means to us

Strong alignment looks like:
- [Concrete example of a clearly-aligned partner]
- [Another concrete example]
- Demonstrated commitment to [specific value or practice that matters to us]

Moderate alignment looks like:
- [Example of a partner who fits but with caveats]

Weak or no alignment looks like:
- [Example of a partner whose values diverge from ours]
- Organizations whose primary mission conflicts with [our mission area]

# Partnership models we actually use

When evaluating partnership_models for a company, only return values that match how we actually engage:
- We do accept: [list the partnership types we use, e.g. Donation, Sponsorship, Grant]
- We do NOT pursue: [list types we don't use, e.g. Cause Marketing if we're a small grassroots org]

# Geographic priorities

Our highest-priority geographies are [list]. We can engage organizations operating outside these areas if alignment is strong on other dimensions, but factor geography into the alignment score.`,
  },
];
</script>

<template>
  <ContextModulesTab
    surface="company"
    title="Company Context"
    intro="Markdown injected into every company-research enrichment prompt. Define what your organization considers important when researching companies — your mission, what alignment means to you, partnership models you actually use, geographic priorities."
    lookup-fields-help="Company-side fields whose values get injected into the enrichment prompt as 'existing data on file.' Useful for fields that carry stronger signal than Claude can find publicly — internal scoring, grant history, partnership tier, etc."
    :starter-examples="starterExamples"
  />
</template>
