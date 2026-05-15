<script setup>
// Dashboard tab: plugin orientation, configured state, and authorship.
// First tab in the navigation. Static content + the health snapshot
// from window.FCEAdmin (set by FCE_Admin_Settings::render_vue_app).
//
// Copy here is a starting point. Refine before shipping v1.0.0.

import { computed } from 'vue';
import { CircleCheck, Warning, CircleClose } from '@element-plus/icons-vue';

const config = window.FCEAdmin || {};
const health = config.health || {};
const companyEnabled = !!config.companyOn;

const aiSettingsUrl = config.aiSettingsUrl || '';

const providerLabel = computed(() => {
  return { claude: 'Claude', open_ai: 'OpenAI', gemini: 'Gemini' }[health.provider] || health.provider || '—';
});

const setupItems = computed(() => {
  return [
    {
      label: 'AI provider',
      ok: health.status === 'ready',
      value:
        health.status === 'ready'
          ? `${providerLabel.value} (${health.model || 'default model'})`
          : 'Not configured',
      hint:
        health.status === 'ready'
          ? null
          : 'Configure in FluentCRM → Settings → AI Configuration.',
      action: health.status === 'ready' ? null : { label: 'Open AI settings', url: aiSettingsUrl },
    },
    {
      label: 'Web search',
      ok: health.status === 'ready' && health.provider === 'claude',
      value:
        health.status === 'ready' && health.provider === 'claude'
          ? 'Enabled (Claude with web_search tool)'
          : 'Not enabled for this provider',
      hint:
        health.status === 'ready' && health.provider !== 'claude'
          ? 'Switch to Claude to enable grounded research with citations.'
          : null,
    },
    {
      label: 'Company module',
      ok: companyEnabled,
      value: companyEnabled
        ? 'Enabled — company research is available'
        : 'Disabled — only contact research is available',
      hint: companyEnabled
        ? null
        : 'Enable FluentCRM → Settings → Experimental → Company module to unlock company enrichment.',
    },
  ];
});

function statusIcon(item) {
  if (item.ok) return CircleCheck;
  if (item.hint) return Warning;
  return CircleClose;
}

function statusClass(item) {
  if (item.ok) return 'fce-setup-ok';
  if (item.hint) return 'fce-setup-warn';
  return 'fce-setup-error';
}
</script>

<template>
  <div class="fce-dashboard">
    <el-card shadow="never" class="fce-dashboard-intro">
      <template #header>
        <h2>FluentCRM Contact Enrichment</h2>
      </template>

      <p class="fce-lede">
        AI-grounded research for FluentCRM companies and contacts. Click <strong>Enrich</strong> on
        any company or contact profile and the plugin runs a web-grounded research pass — returning
        structured fields you can filter and segment by, plus a narrative note with citations.
      </p>

      <h3>What you get</h3>

      <el-row :gutter="16">
        <el-col :span="12">
          <div class="fce-feature">
            <h4>Company research</h4>
            <p>
              Researches the organization with web search, returns structured fields
              (type, sector, employee range, revenue, geographic scope, focus areas,
              partnership models, alignment score), and writes a four-section narrative note.
              Structured values mirror onto every contact whose primary company matches, so
              the data is segmentable on the contact side too.
            </p>
          </div>
        </el-col>
        <el-col :span="12">
          <div class="fce-feature">
            <h4>Individual contact research</h4>
            <p>
              Researches the <em>person</em> — career background, alignment with the requesting
              organization's mission, prior relationship, engagement readiness. Grounded in
              Apra's professional ethics standards for prospect research. Per-contact consent
              flag blocks research at the cron-job level for opted-out contacts.
            </p>
          </div>
        </el-col>
      </el-row>

      <h3>How to use it</h3>

      <ol class="fce-steps">
        <li>
          Configure <strong>at least one</strong> Contact Context module (for individual research)
          or Company Context module (for company research). Modules are Markdown that tells Claude
          what your organization considers important — your mission, what alignment means to you,
          what counts as relevant signal for your use case.
        </li>
        <li>
          Optionally edit Focus Areas (the vocabulary for the multi-select org focus field) and
          Capacity Tiers (the values for individual capacity tier). Defaults work for fundraising;
          rewrite them for cohort programs, B2B prospecting, or board recruitment.
        </li>
        <li>
          Open any FluentCRM company or contact profile, find the <strong>Enrichment</strong>
          section, click <strong>Enrich</strong>. A few seconds later (after the WP-Cron run),
          the structured fields and the research note are on the record.
        </li>
      </ol>
    </el-card>

    <el-card shadow="never" class="fce-dashboard-setup">
      <template #header>
        <h3>Your setup</h3>
      </template>

      <ul class="fce-setup-list">
        <li v-for="item in setupItems" :key="item.label" :class="statusClass(item)">
          <span class="fce-setup-icon">
            <el-icon><component :is="statusIcon(item)" /></el-icon>
          </span>
          <div class="fce-setup-body">
            <div class="fce-setup-row">
              <strong>{{ item.label }}</strong>
              <span class="fce-setup-value">{{ item.value }}</span>
            </div>
            <div v-if="item.hint" class="fce-setup-hint">
              {{ item.hint }}
              <a v-if="item.action" :href="item.action.url">{{ item.action.label }} →</a>
            </div>
          </div>
        </li>
      </ul>
    </el-card>

    <el-card shadow="never" class="fce-dashboard-credits">
      <template #header>
        <h3>About this plugin</h3>
      </template>
      <p>
        Built by <a href="https://wemakegood.org" target="_blank" rel="noopener"><strong>Make Good</strong></a>.
        We help mission-driven organizations stay mission-driven through the work ahead —
        scaling, technology adoption, leadership change, strategic evolution. Active partnership
        work with nonprofits since 2005.
      </p>
      <p class="fce-credits-meta">
        <span>Version {{ config.version || 'unknown' }}</span>
        <span class="fce-credits-sep">·</span>
        <a href="https://github.com/WeMakeGood/fluentcrm-contact-enrichment" target="_blank" rel="noopener">GitHub</a>
        <span class="fce-credits-sep">·</span>
        <a href="https://github.com/WeMakeGood/fluentcrm-contact-enrichment/issues" target="_blank" rel="noopener">Report an issue</a>
      </p>
    </el-card>
  </div>
</template>

<style scoped>
.fce-dashboard {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.fce-dashboard h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}
.fce-dashboard h3 {
  margin: 24px 0 12px 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}
.fce-dashboard h3:first-child {
  margin-top: 0;
}
.fce-dashboard-setup h3,
.fce-dashboard-credits h3 {
  margin: 0;
  font-size: 16px;
}

.fce-lede {
  font-size: 14px;
  line-height: 1.6;
  color: var(--el-text-color-regular);
  margin: 0 0 8px 0;
}

.fce-feature h4 {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 600;
}
.fce-feature p {
  margin: 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--el-text-color-regular);
}

.fce-steps {
  margin: 0;
  padding-left: 20px;
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-regular);
}
.fce-steps li + li {
  margin-top: 8px;
}

.fce-setup-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.fce-setup-list li {
  display: flex;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}
.fce-setup-list li:last-child {
  border-bottom: none;
}
.fce-setup-icon {
  font-size: 20px;
  flex-shrink: 0;
  margin-top: 2px;
}
.fce-setup-ok .fce-setup-icon {
  color: var(--el-color-success);
}
.fce-setup-warn .fce-setup-icon {
  color: var(--el-color-warning);
}
.fce-setup-error .fce-setup-icon {
  color: var(--el-color-danger);
}

.fce-setup-body {
  flex: 1;
}
.fce-setup-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: baseline;
  flex-wrap: wrap;
}
.fce-setup-value {
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.fce-setup-hint {
  margin-top: 4px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.fce-credits-meta {
  margin: 8px 0 0 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.fce-credits-sep {
  margin: 0 6px;
  color: var(--el-text-color-disabled);
}
</style>
