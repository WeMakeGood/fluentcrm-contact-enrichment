// Thin wrapper around Element Plus's ElMessage so callers don't
// need to import it everywhere and we can adjust default options
// (duration, position) in one place.

import { ElMessage } from 'element-plus';

const DEFAULTS = {
  duration: 4000,
  showClose: true,
};

export function useNotify() {
  return {
    success(message) {
      ElMessage({ ...DEFAULTS, type: 'success', message });
    },
    error(message) {
      ElMessage({ ...DEFAULTS, type: 'error', message, duration: 6000 });
    },
    info(message) {
      ElMessage({ ...DEFAULTS, type: 'info', message });
    },
    warning(message) {
      ElMessage({ ...DEFAULTS, type: 'warning', message });
    },
  };
}
