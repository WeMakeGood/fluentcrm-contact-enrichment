<script setup>
// Multi-select picker for FluentCRM custom fields, grouped by the
// field's `group` property. Groups are collapsible; groups with at
// least one selected field expand by default, others stay closed
// so the picker reads as a tidy list of category names rather than
// a wall of checkboxes.

import { computed, ref, watch } from 'vue';

const props = defineProps({
  available: { type: Array, required: true }, // [{slug, label, group, type, ...}]
  selected: { type: Array, required: true }, // string[]
  loading: { type: Boolean, default: false },
  showSlugs: { type: Boolean, default: false },
});

defineEmits(['update:selected']);

const groupedFields = computed(() => {
  const groups = new Map();
  for (const field of props.available) {
    const key = field.group || 'Other';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(field);
  }
  return Array.from(groups.entries()).map(([name, fields]) => ({ name, fields }));
});

// Per-group counts (selected of total). Drives the small "n of m"
// badge next to each group header so admins can scan group state
// without opening every group.
const groupCounts = computed(() => {
  const counts = {};
  for (const group of groupedFields.value) {
    const total = group.fields.length;
    let selected = 0;
    for (const f of group.fields) {
      if (props.selected.includes(f.slug)) selected += 1;
    }
    counts[group.name] = { selected, total };
  }
  return counts;
});

// Track which groups are open. Groups with any selected fields
// default to open; empty-selection groups default to closed.
const openGroups = ref([]);

watch(
  [groupedFields, () => props.selected],
  ([groups]) => {
    if (openGroups.value.length === 0) {
      openGroups.value = groups
        .filter((g) => groupCounts.value[g.name].selected > 0)
        .map((g) => g.name);
    }
  },
  { immediate: true }
);
</script>

<template>
  <div class="fce-lookup-picker" v-loading="loading">
    <div v-if="!loading && available.length === 0" class="fce-lookup-empty">
      No eligible custom fields found. The picker excludes plugin-managed slugs
      and FluentCRM's built-in fields.
    </div>

    <el-collapse v-else v-model="openGroups" class="fce-lookup-groups">
      <el-collapse-item
        v-for="group in groupedFields"
        :key="group.name"
        :name="group.name"
      >
        <template #title>
          <span class="fce-lookup-group-title">{{ group.name }}</span>
          <span class="fce-lookup-group-count" :class="{ 'has-selection': groupCounts[group.name].selected > 0 }">
            {{ groupCounts[group.name].selected }} / {{ groupCounts[group.name].total }}
          </span>
        </template>

        <el-checkbox-group
          :model-value="selected"
          @update:model-value="$emit('update:selected', $event)"
        >
          <div class="fce-lookup-group-fields">
            <el-checkbox
              v-for="field in group.fields"
              :key="field.slug"
              :label="field.slug"
              :value="field.slug"
            >
              <span class="fce-lookup-label">{{ field.label }}</span>
              <span v-if="showSlugs" class="fce-lookup-slug">{{ field.slug }}</span>
            </el-checkbox>
          </div>
        </el-checkbox-group>
      </el-collapse-item>
    </el-collapse>
  </div>
</template>

<style scoped>
.fce-lookup-picker {
  min-height: 60px;
}

.fce-lookup-empty {
  color: var(--el-text-color-secondary);
  font-size: 13px;
  padding: 16px;
  background: var(--el-fill-color-lighter);
  border-radius: 6px;
}

.fce-lookup-groups {
  border-top: 1px solid var(--el-border-color-lighter);
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.fce-lookup-group-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}
.fce-lookup-group-count {
  margin-left: 12px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  font-weight: normal;
}
.fce-lookup-group-count.has-selection {
  color: var(--el-color-primary);
  font-weight: 500;
}

.fce-lookup-group-fields {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 6px 16px;
  padding: 8px 0 12px 0;
}

.fce-lookup-picker :deep(.el-checkbox) {
  margin-right: 0;
  height: auto;
  padding: 4px 0;
  align-items: flex-start;
}
.fce-lookup-picker :deep(.el-checkbox__label) {
  padding-left: 8px;
  line-height: 1.4;
  font-weight: normal;
}
.fce-lookup-label {
  display: block;
  color: var(--el-text-color-primary);
  font-size: 13px;
}
.fce-lookup-slug {
  display: block;
  color: var(--el-text-color-secondary);
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 11px;
  margin-top: 2px;
}
</style>
