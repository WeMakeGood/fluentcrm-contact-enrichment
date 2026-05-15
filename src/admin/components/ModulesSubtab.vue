<script setup>
// Two-column module manager: list rail on the left, editor on the
// right. Selection state is local to this component; the parent
// (ContextModulesTab) only sees the modules array via v-model and
// dirty events. Auto-selects the first module on mount; falls back
// to an empty state when there are zero modules.
//
// Starter examples and the meta-prompt widget live in a sidebar
// below the list — they help you write modules, so they belong
// alongside the modules surface.

import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import draggable from 'vuedraggable';
import { Plus } from '@element-plus/icons-vue';

import ModuleListItem from './ModuleListItem.vue';
import ModuleEditor from './ModuleEditor.vue';
import MetaPromptWidget from './MetaPromptWidget.vue';

const props = defineProps({
  modules: { type: Array, required: true },
  surface: { type: String, required: true }, // 'contact' | 'company'
  starterExamples: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modules', 'dirty']);

const route = useRoute();
const router = useRouter();

// Selection lives in the URL as ?moduleIndex=N. Default to 0 when
// there are modules and the param is missing/invalid. Selection
// resets to -1 when there are no modules at all.
const selectedIndex = computed(() => {
  if (props.modules.length === 0) return -1;
  const raw = parseInt(route.query.moduleIndex, 10);
  if (Number.isNaN(raw) || raw < 0 || raw >= props.modules.length) return 0;
  return raw;
});

function setSelectedIndex(i) {
  router.replace({
    path: route.path,
    query: { ...route.query, moduleIndex: String(i) },
  });
}

const hasModules = computed(() => props.modules.length > 0);
const selectedModule = computed(() => {
  if (selectedIndex.value < 0 || selectedIndex.value >= props.modules.length) return null;
  return props.modules[selectedIndex.value];
});

function selectIndex(i) {
  setSelectedIndex(i);
}

function updateSelected(updatedModule) {
  if (selectedIndex.value < 0) return;
  const next = [...props.modules];
  next[selectedIndex.value] = updatedModule;
  emit('update:modules', next);
  emit('dirty');
}

function addModule() {
  const next = [...props.modules, {
    title: '',
    content: '',
    active: true,
    order: props.modules.length,
  }];
  emit('update:modules', next);
  emit('dirty');
  // Auto-select the new module by moving the URL pointer.
  setSelectedIndex(next.length - 1);
}

function removeAt(index) {
  const next = [...props.modules];
  next.splice(index, 1);
  emit('update:modules', next);
  emit('dirty');

  // Adjust selection: if we removed the selected one, fall back to
  // the previous (or first remaining). When the list goes empty,
  // clear the moduleIndex param entirely.
  if (next.length === 0) {
    const { moduleIndex, ...rest } = route.query;
    router.replace({ path: route.path, query: rest });
    return;
  }
  let nextIndex = selectedIndex.value;
  if (nextIndex === index) {
    nextIndex = Math.min(index, next.length - 1);
  } else if (nextIndex > index) {
    nextIndex -= 1;
  }
  setSelectedIndex(nextIndex);
}

function onDragEnd(event) {
  // vuedraggable's @end gives us oldIndex/newIndex; keep selection
  // pinned to the same module after the reorder.
  emit('dirty');
  if (selectedIndex.value < 0) return;

  const { oldIndex, newIndex } = event;
  let nextIndex = selectedIndex.value;
  if (oldIndex === selectedIndex.value) {
    nextIndex = newIndex;
  } else if (oldIndex < selectedIndex.value && newIndex >= selectedIndex.value) {
    nextIndex -= 1;
  } else if (oldIndex > selectedIndex.value && newIndex <= selectedIndex.value) {
    nextIndex += 1;
  }
  if (nextIndex !== selectedIndex.value) {
    setSelectedIndex(nextIndex);
  }
}

// Models that wire draggable's v-model into the parent's modules prop.
const draggableList = computed({
  get: () => props.modules,
  set: (val) => emit('update:modules', val),
});
</script>

<template>
  <div class="fce-modules-wrapper">
    <!-- Inline helpers: collapsible, full-width, above the two-column layout.
         Closed by default so it doesn't crowd out the actual editing surface.
         Most admins open it once when setting up a new install. -->
    <details v-if="starterExamples.length" class="fce-modules-helpers">
      <summary>
        Need help getting started? Show starter examples and the meta-prompt
      </summary>
      <div class="fce-modules-helpers-body">
        <el-collapse>
          <el-collapse-item
            v-for="(ex, i) in starterExamples"
            :key="i"
            :name="i"
            :title="`Example: ${ex.title}`"
          >
            <p class="fce-starter-hint">
              Copy into a new module as a starting point, then edit the placeholders.
            </p>
            <pre class="fce-starter-body">{{ ex.body }}</pre>
          </el-collapse-item>
        </el-collapse>

        <MetaPromptWidget :surface="surface" />
      </div>
    </details>

    <div class="fce-modules-subtab">
      <!-- Left rail: list of modules -->
      <div class="fce-modules-rail">
        <div class="fce-modules-rail-header">
          <h3>Modules</h3>
          <el-button size="small" :icon="Plus" @click="addModule">Add</el-button>
        </div>

        <div v-if="hasModules" class="fce-modules-list">
          <draggable
            v-model="draggableList"
            item-key="$id"
            handle=".fce-list-drag-handle"
            :animation="150"
            @end="onDragEnd"
          >
            <template #item="{ element, index }">
              <ModuleListItem
                :module="element"
                :selected="index === selectedIndex"
                @select="selectIndex(index)"
                @remove="removeAt(index)"
              />
            </template>
          </draggable>
        </div>

        <div v-else class="fce-modules-empty">
          <p>No modules yet.</p>
          <el-button :icon="Plus" @click="addModule">Add your first module</el-button>
        </div>
      </div>

      <!-- Right pane: editor for the selected module -->
      <div class="fce-modules-detail">
        <ModuleEditor
          v-if="selectedModule"
          :model-value="selectedModule"
          @update:model-value="updateSelected"
        />
        <div v-else class="fce-modules-empty-detail">
          <p>Select a module on the left, or add one to get started.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fce-modules-wrapper {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Inline helpers, collapsed by default */
.fce-modules-helpers {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  background: var(--el-fill-color-lighter);
}
.fce-modules-helpers > summary {
  cursor: pointer;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  color: var(--el-color-primary);
  list-style: none;
  display: flex;
  align-items: center;
  gap: 6px;
}
.fce-modules-helpers > summary::before {
  content: '▸';
  font-size: 10px;
  transition: transform 0.15s ease;
}
.fce-modules-helpers[open] > summary::before {
  transform: rotate(90deg);
}
.fce-modules-helpers > summary::-webkit-details-marker {
  display: none;
}
.fce-modules-helpers > summary:hover {
  background: var(--el-fill-color);
}
.fce-modules-helpers-body {
  padding: 12px 14px;
  border-top: 1px solid var(--el-border-color-lighter);
  background: var(--el-bg-color);
  border-radius: 0 0 6px 6px;
}

.fce-modules-helpers :deep(.el-collapse) {
  border-top: 1px solid var(--el-border-color-lighter);
  border-bottom: 1px solid var(--el-border-color-lighter);
  margin-bottom: 12px;
}
.fce-modules-helpers :deep(.el-collapse-item__header) {
  font-size: 13px;
  background: transparent;
}

.fce-starter-hint {
  margin: 0 0 8px 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.fce-starter-body {
  margin: 0;
  padding: 12px;
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--el-fill-color-lighter);
  border-radius: 4px;
  border: 1px solid var(--el-border-color-lighter);
  max-height: 320px;
  overflow-y: auto;
}

/* Two-column layout: list rail + editor */
.fce-modules-subtab {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 20px;
  align-items: start;
}

@media (max-width: 900px) {
  .fce-modules-subtab {
    grid-template-columns: 1fr;
  }
}

/* Left rail */
.fce-modules-rail {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.fce-modules-rail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.fce-modules-rail-header h3 {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--el-text-color-secondary);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.fce-modules-list {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  background: var(--el-bg-color);
  overflow: hidden;
}

.fce-modules-empty {
  padding: 24px 16px;
  text-align: center;
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  background: var(--el-fill-color-lighter);
}
.fce-modules-empty p {
  margin: 0 0 12px 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

/* Right pane */
.fce-modules-detail {
  min-width: 0; /* let inputs shrink in the grid */
}

.fce-modules-empty-detail {
  padding: 48px 24px;
  text-align: center;
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  background: var(--el-fill-color-lighter);
}
.fce-modules-empty-detail p {
  margin: 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
</style>
