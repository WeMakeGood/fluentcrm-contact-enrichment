// Entry point for the Contact Enrichment admin UI.
//
// Mounts a Vue 3 app onto #fce-admin (the mount point rendered by
// FCE_Admin_Settings::render_page). Configuration injected from PHP
// is available on window.FCEAdmin (see wp_localize_script in the
// admin-settings class).

import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import 'element-plus/dist/index.css';

import App from './App.vue';
import { router } from './router.js';

const mountPoint = document.getElementById('fce-admin');

if (mountPoint) {
  const app = createApp(App);
  app.use(ElementPlus);
  app.use(router);
  app.mount(mountPoint);
}
