<script setup>
// Top-level orchestrator. The router (configured in router.js) owns
// the tab list and which tab/subtab is active; this component just
// renders the shell and lets <router-view> handle the body.

import { computed } from 'vue';
import { useRoute } from 'vue-router';

import AppShell from './components/AppShell.vue';
import { tabs as allTabs } from './router.js';

const config = window.FCEAdmin || {};
const companyEnabled = !!config.companyOn;
const route = useRoute();

// Tabs for the nav: visible only. The active key is derived from
// the current route — context tabs match both their root path and
// any /modules or /lookup subroutes.
const visibleTabs = computed(() => allTabs.filter((t) => t.visible));

const activeTabKey = computed(() => {
  const path = route.path;
  // Match by longest prefix in the tab list (so /contact-context/modules
  // resolves to contact_context).
  for (const tab of visibleTabs.value) {
    if (path === tab.path || path.startsWith(tab.path + '/')) {
      return tab.key;
    }
  }
  return 'dashboard';
});
</script>

<template>
  <AppShell
    title="FluentCRM Contact Enrichment"
    :tabs="visibleTabs"
    :active-tab="activeTabKey"
    :health="config.health || {}"
    :company-enabled="companyEnabled"
  >
    <router-view />
  </AppShell>
</template>

<!--
  Unscoped reset: WordPress admin's forms.css applies a global
  `input[type="text"] { border, box-shadow, line-height, padding }`
  rule that paints a second outline on every Element Plus input.

  Carefully scoped: we only reset bare input/select elements that
  live *inside* an Element Plus text-input wrapper (.el-input or
  .el-select). The textarea element gets its border directly from
  Element Plus — not from a wrapper — so we leave it alone, and
  instead just neutralize WP's box-shadow rule that conflicts with
  Element Plus's focus styling.
-->
<style>
/* Text inputs and selects: WP's element-level border conflicts
   with the Element Plus wrapper border. Strip the inner element
   styling so the wrapper takes over visually. */
#fce-admin .el-input input,
#fce-admin .el-select select {
  border: 0;
  background: transparent;
  box-shadow: none;
  border-radius: 0;
  padding: 0;
  line-height: normal;
  min-height: 0;
  color: inherit;
}
#fce-admin .el-input input:focus,
#fce-admin .el-select select:focus {
  border: 0;
  box-shadow: none;
  outline: none;
}

/* Textareas: WP applies element-level box-shadow on :focus that
   competes with Element Plus's focus ring. Neutralize just the
   conflicting properties; leave Element Plus's border + padding
   on the element intact. */
#fce-admin .el-textarea textarea {
  box-shadow: none;
}
#fce-admin .el-textarea textarea:focus {
  box-shadow: none;
}
</style>
