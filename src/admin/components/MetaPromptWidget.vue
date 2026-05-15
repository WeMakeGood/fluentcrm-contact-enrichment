<script setup>
// Collapsible click-to-copy widget for the meta-prompt that admins
// paste into their own LLM (Claude.ai, ChatGPT, internal agent) to
// help generate a finished context module. Prompt content is fetched
// lazily on first expansion.

import { ref } from 'vue';
import { CopyDocument, Check } from '@element-plus/icons-vue';

import { api, ApiError } from '../api.js';
import { useNotify } from '../composables/useNotify.js';

const props = defineProps({
  surface: { type: String, required: true }, // 'contact' | 'company'
});

const notify = useNotify();

const expanded = ref(false);
const prompt = ref('');
const loading = ref(false);
const available = ref(true);
const copied = ref(false);

async function ensureLoaded() {
  if (prompt.value || !available.value) return;
  loading.value = true;
  try {
    const result = await api.metaPrompt.get(props.surface);
    prompt.value = result.prompt || '';
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) {
      // Prompt file is missing — hide the widget silently. Same
      // behavior as the legacy PHP path: missing file → no widget.
      available.value = false;
    } else {
      notify.error(e instanceof ApiError ? e.message : 'Could not load the meta-prompt.');
    }
  } finally {
    loading.value = false;
  }
}

async function toggle() {
  if (!expanded.value) {
    await ensureLoaded();
  }
  expanded.value = !expanded.value;
}

async function copyToClipboard() {
  if (!prompt.value) return;
  try {
    await navigator.clipboard.writeText(prompt.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
    notify.success('Meta-prompt copied to clipboard.');
  } catch {
    notify.error('Could not copy to clipboard. Select the text manually.');
  }
}
</script>

<template>
  <div v-if="available" class="fce-meta-prompt">
    <el-button link type="primary" @click="toggle" :loading="loading">
      {{ expanded ? '↑ Hide' : '↓ Show' }} the meta-prompt for {{ surface }} context modules
    </el-button>

    <p class="fce-meta-prompt-hint">
      Paste this into your own LLM (Claude.ai, ChatGPT, or any tool that already has your
      organization's context loaded). The LLM uses what it knows about you to draft a
      finished Markdown module you can paste back into the editor above.
    </p>

    <div v-if="expanded && prompt" class="fce-meta-prompt-body">
      <div class="fce-meta-prompt-actions">
        <el-button
          size="small"
          @click="copyToClipboard"
          :icon="copied ? Check : CopyDocument"
        >
          {{ copied ? 'Copied' : 'Copy to clipboard' }}
        </el-button>
      </div>
      <pre class="fce-meta-prompt-text">{{ prompt }}</pre>
    </div>
  </div>
</template>

<style scoped>
.fce-meta-prompt {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.fce-meta-prompt-hint {
  margin: 4px 0 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  max-width: 70ch;
}

.fce-meta-prompt-body {
  margin-top: 12px;
  background: var(--el-fill-color-lighter);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  overflow: hidden;
}
.fce-meta-prompt-actions {
  display: flex;
  justify-content: flex-end;
  padding: 8px 12px;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-lighter);
}
.fce-meta-prompt-text {
  margin: 0;
  padding: 16px;
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 480px;
  overflow-y: auto;
  color: var(--el-text-color-primary);
}
</style>
