// Vue Router config for the Contact Enrichment admin app.
//
// Hash mode (createWebHashHistory) so the WP admin URL stays
// `admin.php?page=fluentcrm-contact-enrichment` and the active tab
// lives in the hash (e.g. `#/contact-context/modules`). The server
// doesn't have to know anything about these routes.
//
// Visibility of routes depends on the FluentCRM Company module
// state, which we receive via window.FCEAdmin. Routes whose tab
// shouldn't appear simply aren't registered — the route map matches
// the tab list in App.vue so a deep-linked URL to a hidden tab
// resolves to the catch-all "redirect to dashboard."

import { createRouter, createWebHashHistory } from 'vue-router';

import Dashboard from './tabs/Dashboard.vue';
import ContactContext from './tabs/ContactContext.vue';
import CompanyContext from './tabs/CompanyContext.vue';
import FocusAreas from './tabs/FocusAreas.vue';
import CapacityTiers from './tabs/CapacityTiers.vue';
import DangerZone from './tabs/DangerZone.vue';

const config = window.FCEAdmin || {};
const companyEnabled = !!config.companyOn;

// Tab metadata used by both the router and the TabNav component.
// Exporting from here keeps the two in sync: changing visibility
// or order only requires editing this file.
export const tabs = [
  { key: 'dashboard',       path: '/dashboard',       label: 'Dashboard',        visible: true,           component: Dashboard },
  { key: 'contact_context', path: '/contact-context', label: 'Contact Context',  visible: true,           component: ContactContext },
  { key: 'company_context', path: '/company-context', label: 'Company Context',  visible: companyEnabled, component: CompanyContext },
  { key: 'focus_areas',     path: '/focus-areas',     label: 'Focus Areas',      visible: true,           component: FocusAreas },
  { key: 'capacity_tiers',  path: '/capacity-tiers',  label: 'Capacity Tiers',   visible: true,           component: CapacityTiers },
  { key: 'danger_zone',     path: '/danger-zone',     label: 'Danger Zone',      visible: companyEnabled, component: DangerZone },
];

const routes = [
  ...tabs
    .filter((t) => t.visible)
    .map((t) => ({
      path: t.path,
      name: t.key,
      component: t.component,
    })),
  // Subroutes for the context tabs land at the same component; the
  // subtab itself reads the matched route to decide which subtab
  // pane to show. This keeps the URL structure declarative
  // (/contact-context/modules vs /contact-context/lookup) without
  // requiring a nested component layout.
  ...(companyEnabled
    ? [
        { path: '/company-context/modules', name: 'company_context_modules', component: CompanyContext },
        { path: '/company-context/lookup',  name: 'company_context_lookup',  component: CompanyContext },
      ]
    : []),
  { path: '/contact-context/modules', name: 'contact_context_modules', component: ContactContext },
  { path: '/contact-context/lookup',  name: 'contact_context_lookup',  component: ContactContext },
  // Anything else (including the initial `/`) goes to Dashboard.
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
});
