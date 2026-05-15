// Unsaved-changes guard composable. Two listeners:
//
//  1. window.beforeunload — catches reloads, tab close, typing a
//     new URL. Browser shows its own generic "Changes may not be
//     saved" dialog; we can't customize the text in modern browsers,
//     but returning a string from the handler is what triggers it.
//
//  2. router.beforeEach — catches in-app navigation between tabs.
//     beforeunload doesn't fire here because the page isn't reloading.
//     We call native confirm() and either let the navigation proceed
//     or cancel it.
//
// Subtab navigation within the same parent tab (Modules ↔ Lookup
// inside Contact Context, or moduleIndex changes) is *not* guarded
// — that's just UI state inside the same save context, and prompting
// would be noise. We detect "real" navigation by comparing the top-
// level tab path segment.
//
// Usage:
//
//   import { ref } from 'vue';
//   import { useUnsavedChangesGuard } from '../composables/useUnsavedChangesGuard.js';
//
//   const dirty = ref(false);
//   useUnsavedChangesGuard(dirty);
//
//   // ... mark dirty.value = true when the form changes,
//   // dirty.value = false after a successful save.

import { onBeforeUnmount, onMounted } from 'vue';
import { useRouter } from 'vue-router';

/**
 * Top-level tab path: '/contact-context/modules' → '/contact-context'.
 * Used to decide whether a router navigation is "real" (different
 * parent tab) vs internal (same tab, different subroute).
 */
function topLevelPath(path) {
  const segments = (path || '').split('/').filter(Boolean);
  return segments.length > 0 ? '/' + segments[0] : '/';
}

export function useUnsavedChangesGuard(dirtyRef, options = {}) {
  const message = options.message || 'You have unsaved changes. Leave anyway?';
  const router = useRouter();

  let removeRouterGuard = null;

  function beforeUnloadHandler(event) {
    if (!dirtyRef.value) return;
    // Modern browsers ignore custom messages but still show a dialog
    // when the handler returns a string. preventDefault() + returnValue
    // is the cross-browser-compatible incantation.
    event.preventDefault();
    event.returnValue = message;
    return message;
  }

  onMounted(() => {
    window.addEventListener('beforeunload', beforeUnloadHandler);

    removeRouterGuard = router.beforeEach((to, from) => {
      if (!dirtyRef.value) return true;
      // Only prompt for cross-tab navigation; subtab and query-param
      // changes within the same parent tab pass through.
      if (topLevelPath(to.path) === topLevelPath(from.path)) return true;

      // eslint-disable-next-line no-alert
      const ok = window.confirm(message);
      if (ok) {
        // Clear dirty so a quick reload after confirming doesn't
        // prompt again with the now-discarded state.
        dirtyRef.value = false;
        return true;
      }
      return false;
    });
  });

  onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    if (removeRouterGuard) {
      removeRouterGuard();
      removeRouterGuard = null;
    }
  });
}
