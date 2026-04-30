<?php
/**
 * Plugin Name:     FluentCRM Contact Enrichment
 * Plugin URI:      https://github.com/WeMakeGood/fluentcrm-contact-enrichment
 * Description:     Enriches FluentCRM company records using the Claude API. Researches the organization, writes structured org-profile fields to all linked contacts, and stores a narrative research note on the company.
 * Author:          Make Good
 * Author URI:      https://wemakegood.org
 * Text Domain:     fluentcrm-contact-enrichment
 * Domain Path:     /languages
 * Version:         0.2.0
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: fluent-crm
 *
 * @package         Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

const FCE_VERSION = '0.2.0';

const FCE_OPT_API_KEY        = 'fce_api_key';
const FCE_OPT_MODEL          = 'fce_model';
const FCE_OPT_MAX_SEARCHES   = 'fce_max_searches';
const FCE_OPT_CONTEXT_MODS   = 'fce_context_modules';
const FCE_OPT_FOCUS_AREAS    = 'fce_focus_area_options';

const FCE_MENU_SLUG          = 'fluentcrm-contact-enrichment';
const FCE_SECTION_KEY        = 'fce_enrichment';

const FCE_CRON_HOOK          = 'fce_run_enrichment_job';
const FCE_AJAX_TRIGGER       = 'fce_trigger_enrichment';

const FCE_NONCE_SETTINGS     = 'fce_save_settings';
const FCE_NONCE_TRIGGER      = 'fce_trigger_enrichment_nonce';

// Field slugs — referenced from registrar, mapper, and section render.
const FCE_FIELD_STATUS       = 'enrichment_status';
const FCE_FIELD_DATE         = 'enrichment_date';
const FCE_FIELD_CONFIDENCE   = 'enrichment_confidence';

const FCE_GROUP_COMPANY      = 'Enrichment';
const FCE_GROUP_ORG_PROFILE  = 'Enrichment — Org Profile';
const FCE_GROUP_ALIGNMENT    = 'Enrichment — Alignment';

const FCE_CAPABILITY         = 'manage_options';

define( 'FCE_PLUGIN_FILE', __FILE__ );
define( 'FCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FCE_PLUGIN_DIR . 'includes/class-field-registrar.php';
require_once FCE_PLUGIN_DIR . 'includes/class-context-modules.php';
require_once FCE_PLUGIN_DIR . 'includes/class-claude-client.php';
require_once FCE_PLUGIN_DIR . 'includes/class-data-mapper.php';
require_once FCE_PLUGIN_DIR . 'includes/class-enrichment-job.php';
require_once FCE_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once FCE_PLUGIN_DIR . 'includes/class-company-section.php';

/**
 * Activation: register all custom fields. FluentCRM may not be active at the
 * time this runs; the registrar tolerates that and re-runs on the
 * `fluent_crm/after_init_app` hook.
 */
register_activation_hook( __FILE__, array( 'FCE_Field_Registrar', 'on_activate' ) );

/**
 * Bootstrap. Ordering matters:
 *  - Field registrar binds early so it can run on FluentCRM init too.
 *  - Cron handler binds at plugins_loaded so events fired by other plugins
 *    can still reach our hook.
 *  - Section, settings, and ajax bind on init.
 */
add_action( 'plugins_loaded', 'fce_bootstrap' );

function fce_bootstrap() {
	FCE_Field_Registrar::register_hooks();
	FCE_Enrichment_Job::register_hooks();
	FCE_Admin_Settings::register_hooks();
	FCE_Company_Section::register_hooks();
}
