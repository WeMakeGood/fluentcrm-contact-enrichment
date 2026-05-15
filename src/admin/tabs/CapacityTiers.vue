<script setup>
// Capacity Tiers: editable vocabulary for the individual_capacity_tier
// custom field. Defaults are donor-flavored (Major / Mid / Standard /
// Unknown); admins rewrite them per use case — cohort programs might
// use Senior Leader / Mid-Career / Emerging / Unknown, B2B might use
// Decision Maker / Influencer / End User / Unknown.
//
// Mirrors the Focus Areas pattern. Same drag-to-reorder list + add
// row. One addition: a "Restore defaults" link, since admins who
// edit themselves into a corner can ask for the defaults back without
// having to know the magic empty-save-falls-back-to-defaults trick.

import { ref, onMounted, computed } from 'vue';
import draggable from 'vuedraggable';
import { Rank, Delete } from '@element-plus/icons-vue';
import { ElMessageBox } from 'element-plus';

import { api, ApiError } from '../api.js';
import { useNotify } from '../composables/useNotify.js';
import { useUnsavedChangesGuard } from '../composables/useUnsavedChangesGuard.js';

const notify = useNotify();

const options = ref([]);
const defaults = ref([]);
const newOption = ref('');
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);

useUnsavedChangesGuard(dirty);

const canAdd = computed(() => newOption.value.trim().length > 0);

onMounted(async () => {
  loading.value = true;
  try {
    const result = await api.capacityTiers.get();
    options.value = Array.isArray(result.options) ? [...result.options] : [];
    defaults.value = Array.isArray(result.defaults) ? [...result.defaults] : [];
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not load capacity tiers.');
  } finally {
    loading.value = false;
  }
});

function addOption() {
  const value = newOption.value.trim();
  if (!value) return;
  if (options.value.includes(value)) {
    notify.warning(`"${value}" is already in the list.`);
    return;
  }
  options.value.push(value);
  newOption.value = '';
  dirty.value = true;
}

function removeOption(index) {
  options.value.splice(index, 1);
  dirty.value = true;
}

function onDragEnd() {
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const result = await api.capacityTiers.save(options.value);
    options.value = Array.isArray(result.options) ? [...result.options] : options.value;
    dirty.value = false;
    if (result.used_defaults) {
      notify.warning(result.message);
    } else {
      notify.success(result.message || 'Capacity tiers saved.');
    }
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not save capacity tiers.');
  } finally {
    saving.value = false;
  }
}

async function restoreDefaults() {
  try {
    await ElMessageBox.confirm(
      'This replaces your current list with the donor-flavored defaults (Major / Mid / Standard / Unknown). Existing values stored on contacts are not changed.',
      'Restore defaults?',
      {
        confirmButtonText: 'Restore defaults',
        cancelButtonText: 'Cancel',
        type: 'warning',
      }
    );
  } catch {
    return; // User canceled
  }

  options.value = [...defaults.value];
  dirty.value = true;
  await save();
}
</script>

<template>
  <el-card shadow="never" v-loading="loading">
    <template #header>
      <div class="fce-tab-header">
        <div>
          <h2>Capacity Tiers</h2>
          <p class="fce-tab-subtitle">
            Editable values for the <code>individual_capacity_tier</code> field. Defaults are
            donor-flavored (Major / Mid / Standard / Unknown); rewrite them for cohort programs,
            B2B prospecting, or board recruitment as appropriate. Drag to reorder.
          </p>
        </div>
        <el-button
          type="primary"
          @click="save"
          :loading="saving"
          :disabled="!dirty"
        >
          Save changes
        </el-button>
      </div>
    </template>

    <draggable
      v-if="options.length"
      v-model="options"
      tag="ul"
      class="fce-option-list"
      item-key="$value"
      handle=".fce-drag-handle"
      :animation="150"
      @end="onDragEnd"
    >
      <template #item="{ element, index }">
        <li>
          <span class="fce-drag-handle" aria-label="Drag to reorder">
            <el-icon><Rank /></el-icon>
          </span>
          <span class="fce-option-value">{{ element }}</span>
          <el-button
            size="small"
            type="danger"
            plain
            :icon="Delete"
            @click="removeOption(index)"
            aria-label="Remove"
          />
        </li>
      </template>
    </draggable>

    <el-empty
      v-else-if="!loading"
      description="No capacity tiers. Saving an empty list restores the defaults."
      :image-size="80"
    />

    <div class="fce-add-row">
      <el-input
        v-model="newOption"
        placeholder="Add a capacity tier"
        @keyup.enter="addOption"
        clearable
      />
      <el-button :disabled="!canAdd" @click="addOption">Add</el-button>
    </div>

    <div class="fce-restore-row" v-if="defaults.length">
      <el-button link type="primary" @click="restoreDefaults">
        Restore donor-flavored defaults
      </el-button>
    </div>
  </el-card>
</template>

<style scoped>
.fce-tab-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.fce-tab-header h2 {
  margin: 0 0 4px 0;
  font-size: 18px;
  font-weight: 600;
}
.fce-tab-subtitle {
  margin: 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
  max-width: 60ch;
}
code {
  background: var(--el-fill-color-light);
  padding: 1px 6px;
  border-radius: 4px;
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}

.fce-option-list {
  list-style: none;
  margin: 0 0 16px 0;
  padding: 0;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  overflow: hidden;
}
.fce-option-list li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-lighter);
}
.fce-option-list li:last-child {
  border-bottom: none;
}

.fce-drag-handle {
  cursor: grab;
  color: var(--el-text-color-secondary);
  display: flex;
  align-items: center;
  user-select: none;
}
.fce-drag-handle:active {
  cursor: grabbing;
}

.fce-option-list :deep(.sortable-chosen) {
  background: var(--el-fill-color-lighter);
}
.fce-option-list :deep(.sortable-ghost) {
  opacity: 0.4;
}

.fce-option-value {
  flex: 1;
}

.fce-add-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.fce-add-row .el-input {
  flex: 1;
}

.fce-restore-row {
  margin-top: 12px;
  font-size: 13px;
}
</style>
