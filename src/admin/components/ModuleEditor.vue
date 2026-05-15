<script setup>
// Right-pane editor for the currently selected context module.
// Receives the module via v-model:modelValue and emits updates as
// the admin edits. The list rail and remove control live in the
// parent ModulesSubtab.
//
// Content is a real Markdown editor (md-editor-v3) with a small
// toolbar and toggle-able preview. We disable the toolbar bits that
// don't make sense for prompt context (image upload, GitHub flavor,
// preview-only mode) and keep the formatting essentials.

import { MdEditor } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';

const props = defineProps({
  modelValue: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue']);

function updateField(field, value) {
  emit('update:modelValue', { ...props.modelValue, [field]: value });
}

// Curated toolbar. md-editor-v3 exposes its toolbar items as
// strings; this keeps formatting essentials, drops things that
// don't apply (image upload, table of contents, save, mermaid,
// katex). Order roughly follows what we'd want for prose Markdown.
const toolbars = [
  'bold',
  'italic',
  'strikeThrough',
  '-',
  'title',
  'sub',
  'sup',
  'quote',
  'unorderedList',
  'orderedList',
  '-',
  'code',
  'codeRow',
  'link',
  '-',
  'revoke',
  'next',
  '=',
  'preview',
  'previewOnly',
  'fullscreen',
];
</script>

<template>
  <div class="fce-module-editor">
    <el-form-item label="Title" label-position="top">
      <el-input
        :model-value="modelValue.title"
        @update:model-value="updateField('title', $event)"
        placeholder="Short label (e.g. 'Mission and partnership criteria')"
      />
    </el-form-item>

    <el-form-item label="Content (Markdown)" label-position="top">
      <MdEditor
        :model-value="modelValue.content"
        @update:model-value="updateField('content', $event)"
        language="en-US"
        :toolbars="toolbars"
        preview-theme="github"
        :show-code-row-number="false"
        :auto-focus="false"
        class="fce-md-editor"
      />
    </el-form-item>

    <div class="fce-module-footer">
      <el-checkbox
        :model-value="modelValue.active"
        @update:model-value="updateField('active', $event)"
      >
        Active
      </el-checkbox>
      <span class="fce-module-help" v-if="!modelValue.active">
        Inactive modules are saved but not injected into the prompt.
      </span>
    </div>
  </div>
</template>

<style scoped>
.fce-module-editor :deep(.el-form-item) {
  margin-bottom: 16px;
}
.fce-module-editor :deep(.el-form-item:last-of-type) {
  margin-bottom: 12px;
}

/* Constrain the editor's height — md-editor-v3 defaults to viewport
   height which would push the active toggle off-screen. */
.fce-md-editor {
  height: 480px;
  border-radius: 6px;
  border: 1px solid var(--el-border-color);
  overflow: hidden;
}

.fce-module-footer {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-top: 8px;
  border-top: 1px solid var(--el-border-color-lighter);
}
.fce-module-help {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
