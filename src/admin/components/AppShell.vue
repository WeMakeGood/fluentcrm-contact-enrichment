<script setup>
// Outer frame for the admin app: page title, health banner, tab
// navigation, and the active tab body. Active tab content is passed
// in via the default slot so App.vue can swap components based on
// the active tab key without AppShell needing to know about each
// tab's implementation.
//
// The health banner only renders when there's something the admin
// needs to do (warning / error states). Steady-state "ready" is
// shown on the Dashboard tab; surfacing it at the top of every
// settings tab is visual noise.

import { computed } from 'vue';

import HealthBanner from './HealthBanner.vue';
import TabNav from './TabNav.vue';

const props = defineProps({
  title: { type: String, required: true },
  tabs: { type: Array, required: true },
  activeTab: { type: String, required: true },
  health: { type: Object, default: () => ({}) },
  companyEnabled: { type: Boolean, default: false },
});

const showBanner = computed(() => {
  const status = props.health?.status;
  return status && status !== 'ready';
});
</script>

<template>
  <div class="fce-app-shell">
    <header class="fce-app-header">
      <h1>{{ title }}</h1>
    </header>

    <HealthBanner
      v-if="showBanner"
      :health="health"
      :company-enabled="companyEnabled"
    />

    <TabNav
      :active-tab="activeTab"
      :tabs="tabs"
    />

    <main class="fce-app-body">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.fce-app-shell {
  max-width: 1100px;
  /* Match WP admin's .wrap edge margins so we sit flush with the
     admin chrome on the right and breathe on the left without
     inheriting .wrap's input-styling baggage. */
  margin: 20px 20px 20px 2px;
}
.fce-app-header h1 {
  margin: 0 0 16px 0;
  font-size: 22px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}
.fce-app-body {
  /* The active tab component owns its own card/padding */
}
</style>
