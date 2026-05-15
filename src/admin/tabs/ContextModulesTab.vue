<script setup>
// Shared shell for Contact Context and Company Context tabs. Hosts
// two subtabs — Modules (list+detail with helpers) and Lookup Fields
// (grouped checkbox picker). The save button at the top covers both
// subtabs so admins don't have to remember which subtab they edited.

import { ref, onMounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { api, ApiError } from '../api.js';
import { useNotify } from '../composables/useNotify.js';
import { useUnsavedChangesGuard } from '../composables/useUnsavedChangesGuard.js';

import ModulesSubtab from '../components/ModulesSubtab.vue';
import LookupFieldsSubtab from '../components/LookupFieldsSubtab.vue';

const props = defineProps({
  surface: { type: String, required: true }, // 'contact' | 'company'
  title: { type: String, required: true },
  intro: { type: String, required: true },
  lookupFieldsHelp: { type: String, required: true },
  starterExamples: { type: Array, default: () => [] },
});

const notify = useNotify();
const route = useRoute();
const router = useRouter();

const modules = ref([]);
const lookupAvailable = ref([]);
const lookupSelected = ref([]);

const loadingModules = ref(false);
const loadingLookup = ref(false);
const saving = ref(false);
const dirty = ref(false);

useUnsavedChangesGuard(dirty);

// Subtab is derived from the URL: routes ending in /lookup show
// Lookup Fields; everything else (including the base tab path)
// shows Modules. Switching subtabs pushes a route so the URL
// reflects the change.
const subtabBasePath = computed(() => {
  return props.surface === 'contact' ? '/contact-context' : '/company-context';
});

const activeSubtab = computed(() => {
  return route.path.endsWith('/lookup') ? 'lookup' : 'modules';
});

function setSubtab(key) {
  const suffix = key === 'lookup' ? '/lookup' : '/modules';
  router.push({ path: subtabBasePath.value + suffix, query: route.query });
}

const canSave = computed(() => dirty.value && !saving.value);

onMounted(() => {
  reload();
});

async function reload() {
  loadingModules.value = true;
  loadingLookup.value = true;

  try {
    const [modulesResult, lookupResult] = await Promise.all([
      api.contextModules.get(props.surface),
      api.lookupFields.get(props.surface),
    ]);
    modules.value = Array.isArray(modulesResult.modules) ? [...modulesResult.modules] : [];
    lookupAvailable.value = Array.isArray(lookupResult.available) ? [...lookupResult.available] : [];
    lookupSelected.value = Array.isArray(lookupResult.selected) ? [...lookupResult.selected] : [];
    dirty.value = false;
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not load context settings.');
  } finally {
    loadingModules.value = false;
    loadingLookup.value = false;
  }
}

function onModulesChange(next) {
  modules.value = next;
}

function onLookupChange(slugs) {
  lookupSelected.value = slugs;
  dirty.value = true;
}

function markDirty() {
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const [modulesResult, lookupResult] = await Promise.all([
      api.contextModules.save(props.surface, modules.value),
      api.lookupFields.save(props.surface, lookupSelected.value),
    ]);
    modules.value = Array.isArray(modulesResult.modules) ? [...modulesResult.modules] : modules.value;
    lookupSelected.value = Array.isArray(lookupResult.selected) ? [...lookupResult.selected] : lookupSelected.value;
    dirty.value = false;
    notify.success('Context settings saved.');
  } catch (e) {
    notify.error(e instanceof ApiError ? e.message : 'Could not save context settings.');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <el-card shadow="never" v-loading="loadingModules && loadingLookup">
    <template #header>
      <div class="fce-tab-header">
        <div>
          <h2>{{ title }}</h2>
          <p class="fce-tab-subtitle">{{ intro }}</p>
        </div>
        <el-button
          type="primary"
          @click="save"
          :loading="saving"
          :disabled="!canSave"
        >
          Save changes
        </el-button>
      </div>
    </template>

    <el-tabs
      :model-value="activeSubtab"
      @update:model-value="setSubtab"
      class="fce-context-subtabs"
    >
      <el-tab-pane label="Modules" name="modules">
        <ModulesSubtab
          v-if="activeSubtab === 'modules'"
          :modules="modules"
          :surface="surface"
          :starter-examples="starterExamples"
          @update:modules="onModulesChange"
          @dirty="markDirty"
        />
      </el-tab-pane>

      <el-tab-pane label="Lookup Fields" name="lookup">
        <LookupFieldsSubtab
          v-if="activeSubtab === 'lookup'"
          :available="lookupAvailable"
          :selected="lookupSelected"
          :loading="loadingLookup"
          :help="lookupFieldsHelp"
          @update:selected="onLookupChange"
        />
      </el-tab-pane>
    </el-tabs>
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

.fce-context-subtabs {
  /* Tab content has its own padding rhythm; remove Element Plus's
     default tab content padding so the subtab components own their
     spacing entirely. */
}
.fce-context-subtabs :deep(.el-tabs__content) {
  padding-top: 16px;
}
</style>
