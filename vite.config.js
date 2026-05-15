import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Vite config tuned for a WordPress plugin admin UI:
//  - Output bundled to dist/admin/ (committed to git so end-user
//    installs don't need a build step).
//  - Single entry: src/admin/main.js mounts a Vue app onto
//    #fce-admin in the WordPress admin page.
//  - Deterministic file names so wp_enqueue_script() in PHP can
//    point at stable paths instead of hashed ones.
//  - No code-splitting: the admin page loads once, and a single
//    bundle is simpler to enqueue than a graph of chunks.
export default defineConfig({
  plugins: [vue()],

  build: {
    outDir: 'dist/admin',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,

    rollupOptions: {
      input: resolve(__dirname, 'src/admin/main.js'),
      output: {
        entryFileNames: 'admin.js',
        chunkFileNames: 'admin-[name].js',
        assetFileNames: (assetInfo) => {
          // Vite emits the CSS as `style.css` by default; rename
          // to `admin.css` so the enqueue handle reads naturally.
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'admin.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
});
