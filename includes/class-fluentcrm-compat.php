<?php
/**
 * Thin shims over FluentCRM features that can be opt-in or version-gated.
 *
 * Right now the only shim is the company-module detector. Pre-v1.0.0 the
 * plugin assumed companies always worked; v1.0.0 gates company-side features
 * on the module being enabled so contact-only installs (the default) don't
 * see vestigial company UI.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_FluentCRM_Compat {

	/**
	 * Whether FluentCRM's optional Company module is enabled on this install.
	 *
	 * Companies are an experimental/opt-in surface in FluentCRM. When the
	 * module is off, the companies table, profile page, and custom fields
	 * still exist in the schema but are invisible to admins. Our plugin
	 * skips company-side registration in that state so the admin UI stays
	 * coherent.
	 *
	 * Reads through FluentCRM's public helper, which itself reads
	 * `_fluentcrm_experimental_settings.company_module` (a 'yes'/'no'
	 * string).
	 *
	 * @return bool
	 */
	public static function is_company_module_enabled() {
		if ( ! class_exists( '\\FluentCrm\\App\\Services\\Helper' ) ) {
			return false;
		}
		return (bool) \FluentCrm\App\Services\Helper::isCompanyEnabled();
	}
}
