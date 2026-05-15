<script setup>
// Single row in the left-rail list of context modules. Shows the
// module's title, an active/inactive pill, and a trash icon. The row
// is clickable to select; the trash button is on its own click target.

import { computed } from 'vue';
import { Rank, Delete } from '@element-plus/icons-vue';

const props = defineProps({
  module: { type: Object, required: true },
  selected: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'remove']);

const displayTitle = computed(() => {
  const t = (props.module?.title || '').trim();
  return t || '(untitled module)';
});

function onRowClick(e) {
  // Don't select if the click came from the trash button itself.
  if (e.target.closest('.fce-list-item-remove')) return;
  emit('select');
}
</script>

<template>
  <div
    class="fce-list-item"
    :class="{ 'fce-list-item-selected': selected }"
    @click="onRowClick"
  >
    <span class="fce-list-drag-handle" aria-label="Drag to reorder">
      <el-icon><Rank /></el-icon>
    </span>

    <div class="fce-list-item-body">
      <span class="fce-list-item-title" :class="{ 'fce-list-item-untitled': !module.title }">
        {{ displayTitle }}
      </span>
      <span class="fce-list-item-pill" :class="module.active ? 'is-active' : 'is-inactive'">
        {{ module.active ? 'Active' : 'Inactive' }}
      </span>
    </div>

    <button
      type="button"
      class="fce-list-item-remove"
      @click.stop="$emit('remove')"
      aria-label="Remove module"
    >
      <el-icon><Delete /></el-icon>
    </button>
  </div>
</template>

<style scoped>
.fce-list-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  cursor: pointer;
  border-bottom: 1px solid var(--el-border-color-lighter);
  transition: background-color 0.12s ease;
}
.fce-list-item:last-child {
  border-bottom: none;
}
.fce-list-item:hover {
  background: var(--el-fill-color-lighter);
}
.fce-list-item-selected {
  background: var(--el-color-primary-light-9);
}
.fce-list-item-selected:hover {
  background: var(--el-color-primary-light-9);
}

.fce-list-drag-handle {
  display: flex;
  align-items: center;
  color: var(--el-text-color-placeholder);
  cursor: grab;
  font-size: 14px;
  flex-shrink: 0;
}
.fce-list-drag-handle:active {
  cursor: grabbing;
}

.fce-list-item-body {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.fce-list-item-title {
  flex: 1;
  font-size: 13px;
  font-weight: 500;
  color: var(--el-text-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fce-list-item-untitled {
  font-style: italic;
  color: var(--el-text-color-placeholder);
  font-weight: normal;
}

.fce-list-item-pill {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 10px;
  white-space: nowrap;
}
.fce-list-item-pill.is-active {
  background: var(--el-color-success-light-8);
  color: var(--el-color-success);
}
.fce-list-item-pill.is-inactive {
  background: var(--el-fill-color);
  color: var(--el-text-color-secondary);
}

.fce-list-item-remove {
  flex-shrink: 0;
  background: none;
  border: none;
  padding: 4px;
  cursor: pointer;
  color: var(--el-text-color-placeholder);
  display: flex;
  align-items: center;
  border-radius: 4px;
  transition: color 0.12s ease, background-color 0.12s ease;
}
.fce-list-item-remove:hover {
  color: var(--el-color-danger);
  background: var(--el-color-danger-light-9);
}
</style>
