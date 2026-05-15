<script setup>
// Danger Zone: bulk operations that affect many records at once.
//
// Currently houses one action — push every company's cached org_*
// values down to its linked contacts. Useful after the company-side
// fields were updated in bulk (e.g. a migration) and contacts have
// drifted. Synchronous on the server; the admin clicked the button
// and is waiting on the count.

import { ref, computed } from 'vue';
import { Warning } from '@element-plus/icons-vue';

import { api, ApiError } from '../api.js';
import { useNotify } from '../composables/useNotify.js';

const notify = useNotify();

const confirmation = ref('');
const running = ref(false);
const lastResult = ref(null);

const canRun = computed(() => confirmation.value === 'RESYNC');

async function runResync() {
  if (!canRun.value) return;

  running.value = true;
  lastResult.value = null;

  try {
    const result = await api.bulkResync.run(confirmation.value);
    lastResult.value = result;
    confirmation.value = '';
    notify.success(result.message || 'Resync complete.');
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Resync failed.');
  } finally {
    running.value = false;
  }
}
</script>

<template>
  <el-card shadow="never" class="fce-danger">
    <template #header>
      <div class="fce-danger-header">
        <el-icon class="fce-danger-icon"><Warning /></el-icon>
        <div>
          <h2>Danger Zone</h2>
          <p class="fce-danger-subtitle">
            Bulk operations that affect many records at once. Typed confirmation is required;
            actions are not reversible without re-running an opposite operation.
          </p>
        </div>
      </div>
    </template>

    <section class="fce-danger-action">
      <h3>Resync all company values to contacts</h3>
      <p>
        For every company that has cached <code>org_*</code> values (from a prior enrichment),
        push those values down to every contact whose primary company matches. Useful when
        contact-side values have drifted from their company source.
      </p>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        class="fce-danger-detail"
      >
        <p>What this does, in order:</p>
        <ul>
          <li>Reads every company in the database.</li>
          <li>Skips companies with no cached enrichment values (you'll see a count of these).</li>
          <li>For each company with cached values, writes those values to every contact whose primary <code>company_id</code> matches.</li>
        </ul>
        <p>
          <strong>Existing contact values are overwritten</strong> for the mirrored fields. Other
          contact custom fields are not touched. The enrichment status fields are not affected.
        </p>
      </el-alert>

      <el-form @submit.prevent="runResync" class="fce-danger-form">
        <el-form-item label="Type RESYNC to confirm">
          <el-input
            v-model="confirmation"
            placeholder="RESYNC"
            :disabled="running"
            autocomplete="off"
          />
        </el-form-item>
        <el-button
          type="danger"
          :disabled="!canRun"
          :loading="running"
          @click="runResync"
        >
          Run bulk resync
        </el-button>
      </el-form>

      <el-alert
        v-if="lastResult"
        type="success"
        :closable="false"
        show-icon
        class="fce-danger-result"
      >
        <p><strong>Resync complete.</strong></p>
        <ul>
          <li><strong>{{ lastResult.companies_processed }}</strong> companies processed</li>
          <li><strong>{{ lastResult.contacts_updated }}</strong> contacts updated</li>
          <li><strong>{{ lastResult.companies_skipped }}</strong> companies skipped (no cached enrichment values)</li>
        </ul>
      </el-alert>
    </section>
  </el-card>
</template>

<style scoped>
.fce-danger {
  border-color: var(--el-color-danger-light-5);
}
.fce-danger :deep(.el-card__header) {
  border-bottom-color: var(--el-color-danger-light-5);
  background: var(--el-color-danger-light-9);
}

.fce-danger-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.fce-danger-icon {
  font-size: 28px;
  color: var(--el-color-danger);
  margin-top: 4px;
  flex-shrink: 0;
}
.fce-danger-header h2 {
  margin: 0 0 4px 0;
  font-size: 18px;
  font-weight: 600;
  color: var(--el-color-danger);
}
.fce-danger-subtitle {
  margin: 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
  max-width: 60ch;
}

.fce-danger-action h3 {
  margin: 0 0 8px 0;
  font-size: 15px;
  font-weight: 600;
}
.fce-danger-action > p {
  margin: 0 0 12px 0;
  color: var(--el-text-color-regular);
  font-size: 14px;
  line-height: 1.6;
}

.fce-danger-detail {
  margin: 0 0 16px 0;
}
.fce-danger-detail :deep(p) {
  margin: 0 0 6px 0;
}
.fce-danger-detail :deep(p:last-child) {
  margin: 6px 0 0 0;
}
.fce-danger-detail :deep(ul) {
  margin: 0;
  padding-left: 20px;
}
.fce-danger-detail :deep(li) {
  margin-bottom: 2px;
}

.fce-danger-form {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  margin-bottom: 16px;
}
.fce-danger-form :deep(.el-form-item) {
  margin-bottom: 0;
  flex: 1;
  max-width: 320px;
}

.fce-danger-result :deep(ul) {
  margin: 4px 0 0 0;
  padding-left: 20px;
}
.fce-danger-result :deep(li) {
  margin-bottom: 2px;
}

code {
  background: var(--el-fill-color-light);
  padding: 1px 6px;
  border-radius: 4px;
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
</style>
