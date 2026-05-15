<script setup>
// Reflects the AI provider status that FCE_Provider_Bridge::inspect()
// reports server-side. Snapshot is shipped on initial page load via
// window.FCEAdmin.health; we don't poll. If admins change provider
// config in FluentCRM and want to see the updated status, they
// reload the page.

import { computed } from 'vue';

const props = defineProps({
  health: { type: Object, default: () => ({}) },
  companyEnabled: { type: Boolean, default: false },
});

const aiSettingsUrl = computed(() =>
  (window.FCEAdmin && window.FCEAdmin.aiSettingsUrl) || ''
);

const variant = computed(() => {
  switch (props.health?.status) {
    case 'ready':
      return 'success';
    case 'unsupported':
    case 'disabled':
    case 'not_configured':
      return 'warning';
    default:
      return 'error';
  }
});

const title = computed(() => {
  const s = props.health?.status;
  if (s === 'ready') {
    const label = providerLabel(props.health?.provider);
    return `Enrichment ready (${label})`;
  }
  if (s === 'unsupported') return 'Configured for unsupported provider';
  if (s === 'disabled') return 'AI is configured but disabled';
  if (s === 'not_configured') return 'AI provider not configured';
  return 'FluentCRM 3.0+ not detected';
});

function providerLabel(slug) {
  return { claude: 'Claude', open_ai: 'OpenAI', gemini: 'Gemini' }[slug] || slug || 'Unknown';
}
</script>

<template>
  <el-alert
    :title="title"
    :type="variant"
    :closable="false"
    show-icon
    class="fce-health-banner"
  >
    <template v-if="health?.status === 'ready'">
      Using model <code>{{ health.model }}</code>.
      Web search:
      <strong v-if="health.provider === 'claude'">enabled</strong>
      <span v-else>not enabled for this provider</span>.
      <br />
      <span class="fce-banner-sub">
        FluentCRM Company module:
        <strong>{{ companyEnabled ? 'enabled' : 'disabled' }}</strong>.
        <template v-if="!companyEnabled">
          Only contact enrichment is available.
        </template>
      </span>
    </template>

    <template v-else-if="health?.status === 'unsupported'">
      FluentCRM is set to
      <strong>{{ providerLabel(health.provider) }}</strong>.
      This release supports Claude only —
      <a :href="aiSettingsUrl">switch the provider in FluentCRM → Settings → AI Configuration</a>,
      or wait for v1.0.1.
    </template>

    <template v-else-if="health?.status === 'disabled'">
      Enable AI in
      <a :href="aiSettingsUrl">FluentCRM → Settings → AI Configuration</a>
      before running enrichment.
    </template>

    <template v-else-if="health?.status === 'not_configured'">
      Choose a provider and add an API key in
      <a :href="aiSettingsUrl">FluentCRM → Settings → AI Configuration</a>.
    </template>

    <template v-else>
      {{ health?.message || 'FluentCRM 3.0+ is required.' }}
    </template>
  </el-alert>
</template>

<style scoped>
.fce-health-banner {
  margin-bottom: 16px;
}
.fce-banner-sub {
  display: inline-block;
  margin-top: 4px;
  color: var(--el-color-info);
  font-size: 13px;
}
code {
  background: var(--el-fill-color-light);
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
