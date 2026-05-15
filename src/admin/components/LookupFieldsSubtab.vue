<script setup>
// Subtab wrapper around LookupFieldPicker. Owns the contextual help
// text, the "show technical slugs" toggle, and the surface-specific
// guidance. Picker stays presentational so it can be reused later
// (e.g. inside MCP tool configuration).

import { ref } from 'vue';
import LookupFieldPicker from './LookupFieldPicker.vue';

const props = defineProps({
  available: { type: Array, required: true },
  selected: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  help: { type: String, required: true },
});

defineEmits(['update:selected']);

const showSlugs = ref(false);
</script>

<template>
  <div class="fce-lookup-subtab">
    <p class="fce-lookup-help">{{ help }}</p>

    <div class="fce-lookup-controls">
      <el-checkbox v-model="showSlugs" size="small">
        Show technical slugs
      </el-checkbox>
    </div>

    <LookupFieldPicker
      :available="available"
      :selected="selected"
      :loading="loading"
      :show-slugs="showSlugs"
      @update:selected="$emit('update:selected', $event)"
    />
  </div>
</template>

<style scoped>
.fce-lookup-subtab {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.fce-lookup-help {
  margin: 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
  line-height: 1.6;
  max-width: 70ch;
}

.fce-lookup-controls {
  display: flex;
  justify-content: flex-end;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}
</style>
