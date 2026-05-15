<script setup>
// Horizontal tab navigation backed by the router. Clicking a tab
// pushes a route; the active tab is determined by the current route
// (passed in as activeTab prop). Element Plus's el-tabs handles the
// visual treatment.

import { useRouter } from 'vue-router';

const props = defineProps({
  activeTab: { type: String, required: true },
  tabs: { type: Array, required: true },
});

const router = useRouter();

function onTabChange(key) {
  const tab = props.tabs.find((t) => t.key === key);
  if (tab && tab.path) {
    router.push(tab.path);
  }
}
</script>

<template>
  <el-tabs
    :model-value="activeTab"
    @update:model-value="onTabChange"
    class="fce-tab-nav"
  >
    <el-tab-pane
      v-for="tab in tabs"
      :key="tab.key"
      :label="tab.label"
      :name="tab.key"
    />
  </el-tabs>
</template>

<style scoped>
.fce-tab-nav {
  margin-bottom: 16px;
}
</style>
