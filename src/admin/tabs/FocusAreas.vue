<script setup>
// Focus Areas: an editable, drag-to-reorder list of strings used as
// the option set for the org_focus_areas custom field (multi-select).
// The list goes to FluentCRM's contact + company field definitions
// via FCE_Field_Registrar::sync_focus_area_options() on save.

import { ref, onMounted, computed } from 'vue';
import draggable from 'vuedraggable';
import { Rank, Delete } from '@element-plus/icons-vue';

import { api, ApiError } from '../api.js';
import { useNotify } from '../composables/useNotify.js';
import { useUnsavedChangesGuard } from '../composables/useUnsavedChangesGuard.js';

const notify = useNotify();

const options = ref([]);
const newOption = ref('');
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);

useUnsavedChangesGuard(dirty);

const canAdd = computed(() => newOption.value.trim().length > 0);

onMounted(async () => {
  loading.value = true;
  try {
    const result = await api.focusAreas.get();
    options.value = Array.isArray(result.options) ? [...result.options] : [];
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not load focus areas.');
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
  // vuedraggable mutates options.value in place via v-model; we just
  // need to mark the form dirty so Save activates.
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const result = await api.focusAreas.save(options.value);
    options.value = Array.isArray(result.options) ? [...result.options] : options.value;
    dirty.value = false;
    notify.success(result.message || 'Focus areas saved.');
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not save focus areas.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <el-card shadow="never" v-loading="loading">
    <template #header>
      <div class="fce-tab-header">
        <div>
          <h2>Focus Areas</h2>
          <p class="fce-tab-subtitle">
            Editable values for the <code>org_focus_areas</code> field. Used as the option list
            when an admin segments contacts by focus area, and as the constrained vocabulary
            Claude picks from during company enrichment. Drag to reorder.
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
      description="No focus areas yet. Add one below."
      :image-size="80"
    />

    <div class="fce-add-row">
      <el-input
        v-model="newOption"
        placeholder="Add a focus area"
        @keyup.enter="addOption"
        clearable
      />
      <el-button :disabled="!canAdd" @click="addOption">Add</el-button>
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

/* vuedraggable adds these classes during drag */
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
</style>
