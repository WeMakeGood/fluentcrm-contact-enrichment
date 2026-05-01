<?php
/**
 * Admin settings page (Settings → Contact Enrichment). Three tabs:
 *  - API Settings (key, model, test connection)
 *  - Context Modules (admin-edited Markdown injected into every research prompt)
 *  - Focus Areas (multi-select option list for the org_focus_areas field)
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Admin_Settings {

	const TAB_API             = 'api';
	const TAB_CONTEXT         = 'context';
	const TAB_FOCUS           = 'focus';
	const TAB_CONTACT_CONTEXT = 'contact_context';
	const TAB_CAPACITY        = 'capacity';
	const TAB_DANGER          = 'danger';

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_fce_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_fce_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_fce_bulk_resync', array( __CLASS__, 'handle_bulk_resync' ) );
	}

	public static function add_menu_page() {
		add_options_page(
			__( 'FluentCRM Contact Enrichment', 'fluentcrm-contact-enrichment' ),
			__( 'Contact Enrichment', 'fluentcrm-contact-enrichment' ),
			FCE_CAPABILITY,
			FCE_MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fluentcrm-contact-enrichment' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_API;
		$valid_tabs = array(
			self::TAB_API,
			self::TAB_CONTEXT,
			self::TAB_FOCUS,
			self::TAB_CONTACT_CONTEXT,
			self::TAB_CAPACITY,
			self::TAB_DANGER,
		);
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = self::TAB_API;
		}

		?>
		<div class="wrap fce-settings">
			<h1><?php esc_html_e( 'FluentCRM Contact Enrichment', 'fluentcrm-contact-enrichment' ); ?></h1>

			<?php self::render_notices(); ?>
			<?php self::render_getting_started(); ?>

			<h2 class="nav-tab-wrapper">
				<?php self::render_tab_link( self::TAB_API, __( 'API Settings', 'fluentcrm-contact-enrichment' ), $tab ); ?>
				<?php self::render_tab_link( self::TAB_CONTEXT, __( 'Company Context', 'fluentcrm-contact-enrichment' ), $tab ); ?>
				<?php self::render_tab_link( self::TAB_FOCUS, __( 'Focus Areas', 'fluentcrm-contact-enrichment' ), $tab ); ?>
				<?php self::render_tab_link( self::TAB_CONTACT_CONTEXT, __( 'Contact Context', 'fluentcrm-contact-enrichment' ), $tab ); ?>
				<?php self::render_tab_link( self::TAB_CAPACITY, __( 'Capacity Tiers', 'fluentcrm-contact-enrichment' ), $tab ); ?>
				<?php self::render_tab_link( self::TAB_DANGER, __( 'Danger Zone', 'fluentcrm-contact-enrichment' ), $tab ); ?>
			</h2>

			<?php if ( self::TAB_DANGER === $tab ) : ?>
				<?php self::render_danger_tab(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fce_save_settings" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php wp_nonce_field( FCE_NONCE_SETTINGS ); ?>

					<?php
					switch ( $tab ) {
						case self::TAB_API:
							self::render_api_tab();
							break;
						case self::TAB_CONTEXT:
							self::render_context_tab();
							break;
						case self::TAB_FOCUS:
							self::render_focus_tab();
							break;
						case self::TAB_CONTACT_CONTEXT:
							self::render_contact_context_tab();
							break;
						case self::TAB_CAPACITY:
							self::render_capacity_tab();
							break;
					}
					?>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Settings', 'fluentcrm-contact-enrichment' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<style>
			.fce-modules { margin: 1em 0; }
			.fce-module { background: #fff; border: 1px solid #c3c4c7; padding: 1em; margin-bottom: 0.75em; }
			.fce-module-handle { cursor: move; color: #646970; font-size: 1.4em; padding-right: 0.5em; }
			.fce-module-row { display: flex; align-items: flex-start; gap: 0.75em; }
			.fce-module-row > div { flex: 1; }
			.fce-module-meta { display: flex; gap: 1em; align-items: center; margin-top: 0.5em; }
			.fce-module textarea { width: 100%; min-height: 8em; font-family: Menlo, Consolas, monospace; }
			.fce-module input[type=text] { width: 100%; }
			.fce-focus-list { list-style: none; margin: 0; padding: 0; max-width: 32em; }
			.fce-focus-item { background: #fff; border: 1px solid #c3c4c7; padding: 0.5em 0.75em; margin-bottom: 0.25em; display: flex; align-items: center; gap: 0.5em; }
			.fce-focus-handle { cursor: move; color: #646970; }
			.fce-focus-item input[type=text] { flex: 1; border: 0; box-shadow: none; padding: 0.25em 0; }
		</style>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fluentcrm-contact-enrichment' ) );
		}

		check_admin_referer( FCE_NONCE_SETTINGS );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : self::TAB_API;

		switch ( $tab ) {
			case self::TAB_API:
				self::save_api_tab();
				break;
			case self::TAB_CONTEXT:
				self::save_context_tab();
				break;
			case self::TAB_FOCUS:
				self::save_focus_tab();
				break;
			case self::TAB_CONTACT_CONTEXT:
				self::save_contact_context_tab();
				break;
			case self::TAB_CAPACITY:
				self::save_capacity_tab();
				break;
		}

		wp_safe_redirect( self::tab_url( $tab, array( 'fce_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * Danger Zone: bulk resync of all contact org_* values from their
	 * companies. Runs synchronously; redirects back to the Danger Zone
	 * tab with a result notice.
	 *
	 * @return void
	 */
	public static function handle_bulk_resync() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fluentcrm-contact-enrichment' ) );
		}

		check_admin_referer( FCE_NONCE_BULK_RESYNC );

		$confirmation = isset( $_POST['confirmation'] ) ? trim( wp_unslash( $_POST['confirmation'] ) ) : '';
		if ( 'RESYNC' !== $confirmation ) {
			wp_safe_redirect( self::tab_url( self::TAB_DANGER, array( 'fce_msg' => 'resync_unconfirmed' ) ) );
			exit;
		}

		// PHP can hit max_execution_time on installs with thousands of
		// companies. Bump our budget to the WP-Cron default before
		// FluentCRM's contact_custom_data_updated listeners pile up.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		$result = FCE_Contact_Sync::bulk_resync();

		set_transient( 'fce_bulk_resync_result', $result, 60 );

		wp_safe_redirect( self::tab_url( self::TAB_DANGER, array( 'fce_msg' => 'resync_done' ) ) );
		exit;
	}

	public static function handle_test_connection() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fluentcrm-contact-enrichment' ) );
		}

		check_admin_referer( FCE_NONCE_SETTINGS );

		$key   = self::get_api_key();
		$error = FCE_Claude_Client::test_connection( $key );

		$msg = ( null === $error ) ? 'connection_ok' : 'connection_fail';
		set_transient( 'fce_test_result', $error, 60 );

		wp_safe_redirect( self::tab_url( self::TAB_API, array( 'fce_msg' => $msg ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Tab renderers
	// ---------------------------------------------------------------------

	private static function render_api_tab() {
		$has_key   = '' !== self::get_api_key();
		$model     = get_option( FCE_OPT_MODEL, FCE_Claude_Client::DEFAULT_MODEL );
		$max_uses  = (int) get_option( FCE_OPT_MAX_SEARCHES, FCE_Claude_Client::DEFAULT_MAX_USES );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fce_api_key"><?php esc_html_e( 'Anthropic API Key', 'fluentcrm-contact-enrichment' ); ?></label></th>
				<td>
					<input type="password" id="fce_api_key" name="fce_api_key" value=""
						placeholder="<?php echo esc_attr( $has_key ? __( '(saved — leave blank to keep)', 'fluentcrm-contact-enrichment' ) : 'sk-ant-...' ); ?>"
						class="regular-text" autocomplete="off" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to Claude Console privacy settings */
							esc_html__( 'Stored encrypted. Web search must also be enabled at the org level in the %s.', 'fluentcrm-contact-enrichment' ),
							'<a href="https://platform.claude.com/settings/privacy" target="_blank" rel="noopener">Claude Console</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fce_model"><?php esc_html_e( 'Model', 'fluentcrm-contact-enrichment' ); ?></label></th>
				<td>
					<select id="fce_model" name="fce_model">
						<?php foreach ( self::available_models() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $model ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Claude model used for enrichment research.', 'fluentcrm-contact-enrichment' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fce_max_searches"><?php esc_html_e( 'Max searches per enrichment', 'fluentcrm-contact-enrichment' ); ?></label></th>
				<td>
					<input type="number" id="fce_max_searches" name="fce_max_searches" min="1" max="20" value="<?php echo esc_attr( $max_uses ); ?>" />
					<p class="description"><?php esc_html_e( 'Web search is billed at $10 per 1,000 searches. 6–10 is typical for organization research.', 'fluentcrm-contact-enrichment' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( $has_key ) : ?>
			<p>
				<button type="submit" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" name="action" value="fce_test_connection" class="button">
					<?php esc_html_e( 'Test Connection', 'fluentcrm-contact-enrichment' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'Sends a small request with web search enabled to verify both the key and the org-level web-search permission.', 'fluentcrm-contact-enrichment' ); ?></span>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function render_context_tab() {
		$modules = FCE_Context_Modules::all();

		// Always show at least one editable row so admins can add a first
		// module without separately clicking "Add."
		if ( empty( $modules ) ) {
			$modules = array(
				array( 'title' => '', 'content' => '', 'active' => true, 'order' => 0 ),
			);
		}
		?>
		<p>
			<?php esc_html_e( 'Markdown modules injected into every company enrichment prompt, in display order. Use them to ground the research in your organization\'s priorities — your mission, what alignment means to you, partnership models you actually use, geographic focus, etc.', 'fluentcrm-contact-enrichment' ); ?>
		</p>

		<details style="margin: 0 0 1.5em 0; padding: 0.75em 1em; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
			<summary style="cursor: pointer; font-weight: 600;"><?php esc_html_e( 'Show example: a starter Company Context module', 'fluentcrm-contact-enrichment' ); ?></summary>
			<p style="margin-top: 0.75em;"><?php esc_html_e( 'Copy the example below into a new module if you want a starting point. Edit the placeholder text to match your organization, then mark the module Active and save.', 'fluentcrm-contact-enrichment' ); ?></p>
			<p><strong><?php esc_html_e( 'Title:', 'fluentcrm-contact-enrichment' ); ?></strong> <?php esc_html_e( 'Mission and partnership criteria', 'fluentcrm-contact-enrichment' ); ?></p>
			<p><strong><?php esc_html_e( 'Content:', 'fluentcrm-contact-enrichment' ); ?></strong></p>
			<pre style="background: #fff; border: 1px solid #dcdcde; padding: 0.75em; white-space: pre-wrap; font-size: 13px; max-height: 24em; overflow: auto;"><?php
echo esc_html(
"# About our organization

We are [organization name], [one-sentence description of mission and population served]. We work primarily in [geographic scope] and partner with organizations whose values and capacity align with our work.

# What \"alignment\" means to us

Strong alignment looks like:
- [Concrete example of a clearly-aligned partner]
- [Another concrete example]
- Demonstrated commitment to [specific value or practice that matters to us]

Moderate alignment looks like:
- [Example of a partner who fits but with caveats]

Weak or no alignment looks like:
- [Example of a partner whose values diverge from ours]
- Organizations whose primary mission conflicts with [our mission area]

# Partnership models we actually use

When evaluating partnership_models for a company, only return values that match how we actually engage:
- We do accept: [list the partnership types we use, e.g. Donation, Sponsorship, Grant]
- We do NOT pursue: [list types we don't use, e.g. Cause Marketing if we're a small grassroots org]

# Geographic priorities

Our highest-priority geographies are [list]. We can engage organizations operating outside these areas if alignment is strong on other dimensions, but factor geography into the alignment score."
);
?></pre>
		</details>

		<div class="fce-modules" id="fce-modules">
			<?php foreach ( $modules as $i => $module ) : ?>
				<div class="fce-module" data-index="<?php echo (int) $i; ?>">
					<div class="fce-module-row">
						<span class="fce-module-handle dashicons dashicons-menu" aria-hidden="true"></span>
						<div>
							<input type="text" name="fce_modules[<?php echo (int) $i; ?>][title]"
								value="<?php echo esc_attr( $module['title'] ); ?>"
								placeholder="<?php esc_attr_e( 'Module title (e.g. Mission and alignment criteria)', 'fluentcrm-contact-enrichment' ); ?>" />
							<textarea name="fce_modules[<?php echo (int) $i; ?>][content]"
								placeholder="<?php esc_attr_e( 'Markdown content. This is injected directly into the system prompt.', 'fluentcrm-contact-enrichment' ); ?>"><?php
								echo esc_textarea( $module['content'] );
							?></textarea>
							<div class="fce-module-meta">
								<label>
									<input type="checkbox" name="fce_modules[<?php echo (int) $i; ?>][active]" value="1" <?php checked( $module['active'] ); ?> />
									<?php esc_html_e( 'Active', 'fluentcrm-contact-enrichment' ); ?>
								</label>
								<button type="button" class="button button-link-delete fce-remove-module"><?php esc_html_e( 'Remove', 'fluentcrm-contact-enrichment' ); ?></button>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="fce-add-module"><?php esc_html_e( 'Add Module', 'fluentcrm-contact-enrichment' ); ?></button>
		</p>

		<script>
		(function () {
			var container = document.getElementById('fce-modules');
			document.getElementById('fce-add-module').addEventListener('click', function () {
				var i = container.querySelectorAll('.fce-module').length;
				var html = '<div class="fce-module" data-index="' + i + '">' +
					'<div class="fce-module-row">' +
					'<span class="fce-module-handle dashicons dashicons-menu" aria-hidden="true"></span>' +
					'<div>' +
					'<input type="text" name="fce_modules[' + i + '][title]" placeholder="<?php echo esc_js( __( 'Module title', 'fluentcrm-contact-enrichment' ) ); ?>" />' +
					'<textarea name="fce_modules[' + i + '][content]" placeholder="<?php echo esc_js( __( 'Markdown content', 'fluentcrm-contact-enrichment' ) ); ?>"></textarea>' +
					'<div class="fce-module-meta">' +
					'<label><input type="checkbox" name="fce_modules[' + i + '][active]" value="1" checked /> <?php echo esc_js( __( 'Active', 'fluentcrm-contact-enrichment' ) ); ?></label>' +
					'<button type="button" class="button button-link-delete fce-remove-module"><?php echo esc_js( __( 'Remove', 'fluentcrm-contact-enrichment' ) ); ?></button>' +
					'</div></div></div></div>';
				container.insertAdjacentHTML('beforeend', html);
			});
			container.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fce-remove-module')) {
					var module = e.target.closest('.fce-module');
					if (module) { module.parentNode.removeChild(module); }
				}
			});
			if (window.jQuery && jQuery.fn.sortable) {
				jQuery(container).sortable({
					handle: '.fce-module-handle',
					placeholder: 'fce-module',
					forcePlaceholderSize: true
				});
			}
		})();
		</script>
		<?php
	}

	private static function render_danger_tab() {
		$preflight = self::bulk_resync_preflight();
		?>
		<div style="max-width: 720px;">
			<h2 style="color: #b32d2e; margin-top: 1em;"><?php esc_html_e( 'Danger Zone', 'fluentcrm-contact-enrichment' ); ?></h2>
			<p>
				<?php esc_html_e( 'These actions affect data across the FluentCRM database in bulk. They cannot be undone. Use them when contact data has drifted from the canonical company values and you need to repair it.', 'fluentcrm-contact-enrichment' ); ?>
			</p>

			<div style="background: #fff; border: 2px solid #b32d2e; border-radius: 4px; padding: 1.5em; margin-top: 1.5em;">
				<h3 style="margin-top: 0; color: #b32d2e;"><?php esc_html_e( 'Resync all contact org_* values from companies', 'fluentcrm-contact-enrichment' ); ?></h3>

				<p>
					<?php
					printf(
						/* translators: 1: companies-with-cache count, 2: total contacts */
						esc_html__( 'For each of the %1$d companies that have cached enrichment values, this action will read those values and overwrite the matching org_* custom fields on every contact whose primary company is that company (approximately %2$d contacts total). Other contact custom fields are not affected.', 'fluentcrm-contact-enrichment' ),
						(int) $preflight['companies_with_cache'],
						(int) $preflight['estimated_contacts']
					);
					?>
				</p>

				<p>
					<?php esc_html_e( 'Companies without cached enrichment values are skipped. Run an enrichment on those companies first if you want their contacts updated.', 'fluentcrm-contact-enrichment' ); ?>
				</p>

				<?php if ( $preflight['companies_with_cache'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 1.25em;">
						<input type="hidden" name="action" value="fce_bulk_resync" />
						<?php wp_nonce_field( FCE_NONCE_BULK_RESYNC ); ?>

						<p>
							<label for="fce_bulk_resync_confirm">
								<strong><?php esc_html_e( 'Type RESYNC to confirm:', 'fluentcrm-contact-enrichment' ); ?></strong>
							</label><br />
							<input type="text" id="fce_bulk_resync_confirm" name="confirmation"
								required pattern="RESYNC"
								autocomplete="off"
								placeholder="RESYNC"
								style="border: 2px solid #b32d2e; padding: 0.5em; font-family: monospace; width: 12em;" />
						</p>

						<button type="submit" class="button" style="background: #b32d2e; color: #fff; border-color: #8b0000;">
							<?php esc_html_e( 'Run Resync', 'fluentcrm-contact-enrichment' ); ?>
						</button>
					</form>
				<?php else : ?>
					<p><em><?php esc_html_e( 'No companies have cached enrichment values yet — run an enrichment on at least one company before using this tool.', 'fluentcrm-contact-enrichment' ); ?></em></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Cheap pre-flight count for the Danger Zone confirmation. Counts
	 * companies with at least one cached org_type value (a proxy for
	 * "has been enriched") and the contacts attached to those companies.
	 *
	 * @return array{companies_with_cache:int, estimated_contacts:int}
	 */
	private static function bulk_resync_preflight() {
		$companies = 0;
		$contacts  = 0;
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return array( 'companies_with_cache' => 0, 'estimated_contacts' => 0 );
		}

		// Pull just the meta column so we can detect cached org_type without
		// hydrating Eloquent for the whole row.
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, meta FROM {$wpdb->prefix}fc_companies WHERE meta IS NOT NULL" );
		$ids_with_cache = array();
		foreach ( $rows as $r ) {
			$meta = \maybe_unserialize( $r->meta );
			if ( is_array( $meta ) && ! empty( $meta['custom_values']['org_type'] ) ) {
				$ids_with_cache[] = (int) $r->id;
			}
		}
		$companies = count( $ids_with_cache );

		if ( $companies > 0 ) {
			$placeholders = implode( ',', array_fill( 0, $companies, '%d' ) );
			$contacts     = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE company_id IN ($placeholders)",
				$ids_with_cache
			) );
		}

		return array(
			'companies_with_cache' => $companies,
			'estimated_contacts'   => $contacts,
		);
	}

	private static function render_focus_tab() {
		$options = FCE_Field_Registrar::focus_area_options();
		if ( empty( $options ) ) {
			$options = FCE_Field_Registrar::default_focus_areas();
		}
		?>
		<p>
			<?php esc_html_e( 'Options for the Focus Areas multi-select field on contacts. Saving here also updates the field definition; existing values stored on contacts are not affected.', 'fluentcrm-contact-enrichment' ); ?>
		</p>

		<ul class="fce-focus-list" id="fce-focus-list">
			<?php foreach ( $options as $i => $option ) : ?>
				<li class="fce-focus-item">
					<span class="fce-focus-handle dashicons dashicons-menu" aria-hidden="true"></span>
					<input type="text" name="fce_focus_areas[]" value="<?php echo esc_attr( $option ); ?>" />
					<button type="button" class="button-link fce-remove-focus" aria-label="<?php esc_attr_e( 'Remove option', 'fluentcrm-contact-enrichment' ); ?>">&times;</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<p>
			<button type="button" class="button" id="fce-add-focus"><?php esc_html_e( 'Add Option', 'fluentcrm-contact-enrichment' ); ?></button>
		</p>

		<script>
		(function () {
			var list = document.getElementById('fce-focus-list');
			document.getElementById('fce-add-focus').addEventListener('click', function () {
				var html = '<li class="fce-focus-item">' +
					'<span class="fce-focus-handle dashicons dashicons-menu" aria-hidden="true"></span>' +
					'<input type="text" name="fce_focus_areas[]" value="" />' +
					'<button type="button" class="button-link fce-remove-focus">&times;</button>' +
					'</li>';
				list.insertAdjacentHTML('beforeend', html);
				list.lastElementChild.querySelector('input').focus();
			});
			list.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fce-remove-focus')) {
					var item = e.target.closest('.fce-focus-item');
					if (item) { item.parentNode.removeChild(item); }
				}
			});
			if (window.jQuery && jQuery.fn.sortable) {
				jQuery(list).sortable({
					handle: '.fce-focus-handle',
					placeholder: 'fce-focus-item'
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Contact-side context modules — separate from company-side modules
	 * because the framing for individual research (donor prospecting,
	 * cohort prep, sales prospecting, board recruitment) is different
	 * from the framing for company research.
	 */
	private static function render_contact_context_tab() {
		$modules = FCE_Contact_Context_Modules::all();
		if ( empty( $modules ) ) {
			$modules = array(
				array( 'title' => '', 'content' => '', 'active' => true, 'order' => 0 ),
			);
		}
		?>
		<p>
			<?php esc_html_e( 'Markdown modules injected into every contact-research enrichment prompt, in display order. Use them to define what your organization considers relevant for individual research — your mission, what alignment means for individuals, the use case (donor research, cohort prep, sales prospecting, board recruitment), and any practitioner conventions.', 'fluentcrm-contact-enrichment' ); ?>
		</p>
		<p style="font-size: 13px; color: #606266;">
			<?php esc_html_e( 'Contact research operates under Apra-derived professional standards: research is restricted to information bearing on the relationship the requesting organization is trying to build. The modules below tell Claude what "relevant" means for your use case.', 'fluentcrm-contact-enrichment' ); ?>
		</p>

		<details style="margin: 0 0 1.5em 0; padding: 0.75em 1em; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
			<summary style="cursor: pointer; font-weight: 600;"><?php esc_html_e( 'Show examples: starter modules for common use cases', 'fluentcrm-contact-enrichment' ); ?></summary>
			<p style="margin-top: 0.75em;"><?php esc_html_e( 'Pick the example that matches your use case as a starting point. Edit the placeholder text, then mark the module Active and save. You can run multiple modules at once if you have several relevant frames.', 'fluentcrm-contact-enrichment' ); ?></p>

			<p><strong><?php esc_html_e( 'Example: donor prospect research', 'fluentcrm-contact-enrichment' ); ?></strong></p>
			<pre style="background: #fff; border: 1px solid #dcdcde; padding: 0.75em; white-space: pre-wrap; font-size: 13px; max-height: 20em; overflow: auto;"><?php
echo esc_html(
"# Who we are and what we're researching for

We are [organization name], [one-sentence mission statement]. We're researching individuals as part of donor cultivation, with the goal of identifying people whose values, capacity, and life circumstances suggest they might support our mission.

# What relevant means for us

For each contact, we want to know:
- Their professional context (current role, organizational affiliations, public bio)
- Their philanthropic history (known giving, board service, charitable involvement, recognition in capital campaigns)
- Their alignment with our mission, specifically: [list the 2–3 mission dimensions that matter most]
- Their prior relationship to us (alumni, prior gift, board overlap, mission-aligned advocacy)
- Receptivity signals (recent life events, retirement, new role, public statements about giving)

# What we don't want

- Personal life details unrelated to the giving relationship
- Family information beyond what's professionally public
- Social media content beyond professional context
- Speculative wealth estimates from aggregator sites

# Capacity tiers

When you assign a capacity tier, use:
- Major: indicators of giving capacity at our major-gift threshold ([dollar range if you have one])
- Mid: indicators of meaningful but not transformational giving capacity
- Standard: no specific capacity signals, treat as a standard prospect
- Unknown: insufficient public information to estimate capacity"
);
?></pre>

			<p style="margin-top: 1.5em;"><strong><?php esc_html_e( 'Example: cohort program participant prep', 'fluentcrm-contact-enrichment' ); ?></strong></p>
			<pre style="background: #fff; border: 1px solid #dcdcde; padding: 0.75em; white-space: pre-wrap; font-size: 13px; max-height: 20em; overflow: auto;"><?php
echo esc_html(
"# Who we are and what we're researching for

We are [program name], a [duration] cohort-based program for [participant description]. We're researching incoming participants so facilitators have context before the cohort starts. The goal is preparation, not gatekeeping.

# What relevant means for us

For each participant, we want to know:
- Their leadership role and current scope of responsibility
- The kinds of challenges they're publicly working on
- Prior leadership development they've engaged with (if any)
- Their organizational context (size, sector, stage)
- Topics they've written or spoken about

# What we don't want

- Personal life details unrelated to their professional development
- Speculation about how they'll perform in the cohort
- Anything that would feel surveillance-like if the participant read it

# Capacity tiers (we use leadership stages instead of fundraising tiers)

When you assign a capacity tier, use the values configured in our Capacity Tiers tab:
- Senior Leader: 15+ years experience, executive scope
- Mid-Career: 7-15 years, expanding scope
- Emerging: <7 years, currently growing into leadership
- Unknown: insufficient public information"
);
?></pre>

			<p style="margin-top: 1.5em;"><strong><?php esc_html_e( 'Example: B2B sales / partnership stakeholder research', 'fluentcrm-contact-enrichment' ); ?></strong></p>
			<pre style="background: #fff; border: 1px solid #dcdcde; padding: 0.75em; white-space: pre-wrap; font-size: 13px; max-height: 20em; overflow: auto;"><?php
echo esc_html(
"# Who we are and what we're researching for

We are [company name], [what we sell or offer]. We're researching individual stakeholders at companies we're considering as partners or customers. The goal is to understand decision authority, organizational role, and what would make engagement valuable to them.

# What relevant means for us

For each contact, we want to know:
- Their role and decision-making authority within their company
- Their professional history relevant to our offering
- Topics they've written or spoken about that relate to what we sell
- Public statements about their company's strategy that bear on whether our offering would help
- Existing connections to our company or its founders

# What we don't want

- Personal life details
- Compensation or wealth indicators (not relevant for B2B)
- Anything that would feel intrusive if surfaced in an outreach email

# Capacity tiers (we use decision authority instead of fundraising tiers)

Our Capacity Tiers should be set to:
- Decision Maker: confirmed authority over the relevant budget or strategic decision
- Influencer: known to shape decisions but not the final decision-maker
- End User: would use what we sell but doesn't make purchasing decisions
- Unknown"
);
?></pre>
		</details>

		<div class="fce-modules" id="fce-contact-modules">
			<?php foreach ( $modules as $i => $module ) : ?>
				<div class="fce-module" data-index="<?php echo (int) $i; ?>">
					<div class="fce-module-row">
						<span class="fce-module-handle dashicons dashicons-menu" aria-hidden="true"></span>
						<div>
							<input type="text" name="fce_contact_modules[<?php echo (int) $i; ?>][title]"
								value="<?php echo esc_attr( $module['title'] ); ?>"
								placeholder="<?php esc_attr_e( 'Module title (e.g. What we mean by donor capacity)', 'fluentcrm-contact-enrichment' ); ?>" />
							<textarea name="fce_contact_modules[<?php echo (int) $i; ?>][content]"
								placeholder="<?php esc_attr_e( 'Markdown content. Define the use case, alignment criteria, and what relevant means for your research.', 'fluentcrm-contact-enrichment' ); ?>"><?php
								echo esc_textarea( $module['content'] );
							?></textarea>
							<div class="fce-module-meta">
								<label>
									<input type="checkbox" name="fce_contact_modules[<?php echo (int) $i; ?>][active]" value="1" <?php checked( $module['active'] ); ?> />
									<?php esc_html_e( 'Active', 'fluentcrm-contact-enrichment' ); ?>
								</label>
								<button type="button" class="button button-link-delete fce-remove-module"><?php esc_html_e( 'Remove', 'fluentcrm-contact-enrichment' ); ?></button>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="fce-add-contact-module"><?php esc_html_e( 'Add Module', 'fluentcrm-contact-enrichment' ); ?></button>
		</p>

		<script>
		(function () {
			var container = document.getElementById('fce-contact-modules');
			document.getElementById('fce-add-contact-module').addEventListener('click', function () {
				var i = container.querySelectorAll('.fce-module').length;
				var html = '<div class="fce-module" data-index="' + i + '">' +
					'<div class="fce-module-row">' +
					'<span class="fce-module-handle dashicons dashicons-menu" aria-hidden="true"></span>' +
					'<div>' +
					'<input type="text" name="fce_contact_modules[' + i + '][title]" placeholder="<?php echo esc_js( __( 'Module title', 'fluentcrm-contact-enrichment' ) ); ?>" />' +
					'<textarea name="fce_contact_modules[' + i + '][content]" placeholder="<?php echo esc_js( __( 'Markdown content', 'fluentcrm-contact-enrichment' ) ); ?>"></textarea>' +
					'<div class="fce-module-meta">' +
					'<label><input type="checkbox" name="fce_contact_modules[' + i + '][active]" value="1" checked /> <?php echo esc_js( __( 'Active', 'fluentcrm-contact-enrichment' ) ); ?></label>' +
					'<button type="button" class="button button-link-delete fce-remove-module"><?php echo esc_js( __( 'Remove', 'fluentcrm-contact-enrichment' ) ); ?></button>' +
					'</div></div></div></div>';
				container.insertAdjacentHTML('beforeend', html);
			});
			container.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fce-remove-module')) {
					var module = e.target.closest('.fce-module');
					if (module) { module.parentNode.removeChild(module); }
				}
			});
			if (window.jQuery && jQuery.fn.sortable) {
				jQuery(container).sortable({
					handle: '.fce-module-handle',
					placeholder: 'fce-module',
					forcePlaceholderSize: true
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Capacity tier options — admin-configurable values for the
	 * `individual_capacity_tier` field. Defaults are donor-flavored
	 * (Major / Mid / Standard / Unknown) but admins running other
	 * use cases can rewrite (e.g. cohort programs: Senior Leader /
	 * Mid-Career / Emerging / Unknown; B2B: Decision Maker /
	 * Influencer / End User / Unknown).
	 */
	private static function render_capacity_tab() {
		$options = FCE_Field_Registrar::capacity_tier_options();
		if ( empty( $options ) ) {
			$options = FCE_Field_Registrar::default_capacity_tiers();
		}
		?>
		<p>
			<?php esc_html_e( 'Values for the Capacity Tier field on contacts. The defaults are donor-flavored; rewrite them to fit your use case (e.g. cohort programs might use leadership tiers, B2B sales might use decision authority). Saving here updates the field definition; existing values stored on contacts are not affected.', 'fluentcrm-contact-enrichment' ); ?>
		</p>
		<p style="font-size: 13px; color: #606266;">
			<?php esc_html_e( 'The system prompt reads these values at enrichment time and instructs Claude to pick from them. Order matters: Claude treats the first value as the highest tier and the last as the lowest. Include "Unknown" as the final value so Claude has a fallback when capacity cannot be reasonably determined.', 'fluentcrm-contact-enrichment' ); ?>
		</p>

		<ul class="fce-focus-list" id="fce-capacity-list">
			<?php foreach ( $options as $i => $option ) : ?>
				<li class="fce-focus-item">
					<span class="fce-focus-handle dashicons dashicons-menu" aria-hidden="true"></span>
					<input type="text" name="fce_capacity_tiers[]" value="<?php echo esc_attr( $option ); ?>" />
					<button type="button" class="button-link fce-remove-focus" aria-label="<?php esc_attr_e( 'Remove option', 'fluentcrm-contact-enrichment' ); ?>">&times;</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<p>
			<button type="button" class="button" id="fce-add-capacity"><?php esc_html_e( 'Add Tier', 'fluentcrm-contact-enrichment' ); ?></button>
		</p>

		<script>
		(function () {
			var list = document.getElementById('fce-capacity-list');
			document.getElementById('fce-add-capacity').addEventListener('click', function () {
				var html = '<li class="fce-focus-item">' +
					'<span class="fce-focus-handle dashicons dashicons-menu" aria-hidden="true"></span>' +
					'<input type="text" name="fce_capacity_tiers[]" value="" />' +
					'<button type="button" class="button-link fce-remove-focus">&times;</button>' +
					'</li>';
				list.insertAdjacentHTML('beforeend', html);
				list.lastElementChild.querySelector('input').focus();
			});
			list.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fce-remove-focus')) {
					var item = e.target.closest('.fce-focus-item');
					if (item) { item.parentNode.removeChild(item); }
				}
			});
			if (window.jQuery && jQuery.fn.sortable) {
				jQuery(list).sortable({
					handle: '.fce-focus-handle',
					placeholder: 'fce-focus-item'
				});
			}
		})();
		</script>
		<?php
	}

	// ---------------------------------------------------------------------
	// Tab savers
	// ---------------------------------------------------------------------

	private static function save_api_tab() {
		// API key: only update if non-empty (so saving the form without
		// retyping doesn't blank the stored key).
		$posted_key = isset( $_POST['fce_api_key'] ) ? trim( wp_unslash( $_POST['fce_api_key'] ) ) : '';
		if ( '' !== $posted_key ) {
			self::set_api_key( $posted_key );
		}

		$model = isset( $_POST['fce_model'] ) ? sanitize_text_field( wp_unslash( $_POST['fce_model'] ) ) : FCE_Claude_Client::DEFAULT_MODEL;
		if ( array_key_exists( $model, self::available_models() ) ) {
			update_option( FCE_OPT_MODEL, $model, false );
		}

		$max_uses = isset( $_POST['fce_max_searches'] ) ? (int) $_POST['fce_max_searches'] : FCE_Claude_Client::DEFAULT_MAX_USES;
		if ( $max_uses < 1 ) {
			$max_uses = 1;
		} elseif ( $max_uses > 20 ) {
			$max_uses = 20;
		}
		update_option( FCE_OPT_MAX_SEARCHES, $max_uses, false );
	}

	private static function save_context_tab() {
		$modules = isset( $_POST['fce_modules'] ) && is_array( $_POST['fce_modules'] )
			? $_POST['fce_modules']
			: array();
		FCE_Context_Modules::save( $modules );
	}

	private static function save_focus_tab() {
		$posted = isset( $_POST['fce_focus_areas'] ) && is_array( $_POST['fce_focus_areas'] )
			? $_POST['fce_focus_areas']
			: array();

		$cleaned = array();
		$seen    = array();
		foreach ( $posted as $option ) {
			$option = trim( sanitize_text_field( wp_unslash( $option ) ) );
			if ( '' === $option || isset( $seen[ $option ] ) ) {
				continue;
			}
			$seen[ $option ] = true;
			$cleaned[]       = $option;
		}

		update_option( FCE_OPT_FOCUS_AREAS, $cleaned, false );

		// Sync the field definition's options array so the FluentCRM admin
		// UI reflects the change immediately.
		FCE_Field_Registrar::sync_focus_area_options();
	}

	private static function save_contact_context_tab() {
		$modules = isset( $_POST['fce_contact_modules'] ) && is_array( $_POST['fce_contact_modules'] )
			? $_POST['fce_contact_modules']
			: array();
		FCE_Contact_Context_Modules::save( $modules );
	}

	private static function save_capacity_tab() {
		$posted = isset( $_POST['fce_capacity_tiers'] ) && is_array( $_POST['fce_capacity_tiers'] )
			? $_POST['fce_capacity_tiers']
			: array();

		$cleaned = array();
		$seen    = array();
		foreach ( $posted as $option ) {
			$option = trim( sanitize_text_field( wp_unslash( $option ) ) );
			if ( '' === $option || isset( $seen[ $option ] ) ) {
				continue;
			}
			$seen[ $option ] = true;
			$cleaned[]       = $option;
		}

		// Always preserve a fallback. Empty list would break enrichment
		// (Claude has nothing to pick from); fall back to defaults.
		if ( empty( $cleaned ) ) {
			$cleaned = FCE_Field_Registrar::default_capacity_tiers();
		}

		update_option( FCE_OPT_CAPACITY_TIERS, $cleaned, false );
		FCE_Field_Registrar::sync_capacity_tier_options();
	}

	// ---------------------------------------------------------------------
	// API key encryption
	// ---------------------------------------------------------------------

	/**
	 * Returns the decrypted API key, or empty string if none stored.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$stored = get_option( FCE_OPT_API_KEY, '' );
		if ( '' === $stored ) {
			return '';
		}
		return self::decrypt( $stored );
	}

	/**
	 * Encrypts and stores the API key.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function set_api_key( $key ) {
		if ( '' === $key ) {
			delete_option( FCE_OPT_API_KEY );
			return;
		}
		update_option( FCE_OPT_API_KEY, self::encrypt( $key ), false );
	}

	/**
	 * AES-256-CBC encrypt with a derived key from WP salts. Not perfect — a
	 * server compromise that reads wp-config.php can decrypt — but ensures
	 * the key never sits in the database in plaintext.
	 *
	 * @param string $plain
	 * @return string base64-encoded ciphertext (with prepended IV)
	 */
	private static function encrypt( $plain ) {
		$key = self::derive_key();
		$iv  = openssl_random_pseudo_bytes( 16 );
		$ct  = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return '';
		}
		return 'fce1:' . base64_encode( $iv . $ct );
	}

	/**
	 * @param string $stored
	 * @return string plaintext, or empty string on failure
	 */
	private static function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'fce1:' ) ) {
			return '';
		}
		$payload = base64_decode( substr( $stored, 5 ), true );
		if ( false === $payload || strlen( $payload ) <= 16 ) {
			return '';
		}
		$iv  = substr( $payload, 0, 16 );
		$ct  = substr( $payload, 16 );
		$key = self::derive_key();
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $pt ) ? '' : $pt;
	}

	/**
	 * Derive a 256-bit symmetric key from WordPress's auth salts. Stable
	 * across requests as long as wp-config.php doesn't change.
	 *
	 * @return string 32-byte raw key
	 */
	private static function derive_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' )
			. 'fluentcrm-contact-enrichment';
		return hash( 'sha256', $material, true );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function available_models() {
		return array(
			'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 (recommended)', 'fluentcrm-contact-enrichment' ),
			'claude-opus-4-7'   => __( 'Claude Opus 4.7 (highest quality, higher cost)', 'fluentcrm-contact-enrichment' ),
			'claude-haiku-4-5-20251001' => __( 'Claude Haiku 4.5 (fastest, lowest cost)', 'fluentcrm-contact-enrichment' ),
		);
	}

	private static function tab_url( $tab, $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => FCE_MENU_SLUG,
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'options-general.php' )
		);
	}

	private static function render_tab_link( $tab, $label, $current ) {
		$class = ( $tab === $current ) ? 'nav-tab nav-tab-active' : 'nav-tab';
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( self::tab_url( $tab ) ),
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * Getting Started panel — visible until enough setup is complete to
	 * actually do an enrichment. Auto-hides when:
	 *   - API key is set, AND
	 *   - At least one company context module OR at least one contact
	 *     context module is active (i.e. at least one enrichment surface
	 *     is configured).
	 *
	 * Steps shown as ✓ (done) / ✗ (todo). Each unsatisfied step links to
	 * the tab that resolves it, so the panel is also a navigation aid
	 * during initial setup. The panel re-appears if anything regresses
	 * (admin clears their API key, removes all context modules, etc.).
	 *
	 * @return void
	 */
	private static function render_getting_started() {
		$state = self::getting_started_state();
		if ( $state['hidden'] ) {
			return;
		}

		?>
		<div class="fce-getting-started" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 1em 1.5em; margin: 1em 0;">
			<h2 style="margin-top: 0; font-size: 16px;">
				<?php esc_html_e( 'Getting Started', 'fluentcrm-contact-enrichment' ); ?>
			</h2>
			<p style="margin-top: 0;">
				<?php esc_html_e( 'Complete these steps to start enriching companies and contacts. This panel will hide itself once at least one enrichment surface is fully configured.', 'fluentcrm-contact-enrichment' ); ?>
			</p>

			<ol style="margin: 0.75em 0 0.5em 1.5em; padding: 0;">
				<?php foreach ( $state['steps'] as $step ) : ?>
					<li style="margin-bottom: 0.5em; line-height: 1.6;">
						<span style="<?php echo esc_attr( $step['done'] ? 'color: #00a32a; font-weight: bold;' : 'color: #b32d2e; font-weight: bold;' ); ?>"><?php echo $step['done'] ? '✓' : '✗'; ?></span>
						<strong><?php echo esc_html( $step['label'] ); ?></strong>
						<?php if ( ! $step['done'] ) : ?>
							— <a href="<?php echo esc_url( $step['link'] ); ?>"><?php echo esc_html( $step['action'] ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $step['hint'] ) ) : ?>
							<br /><span style="color: #646970; font-size: 13px;"><?php echo esc_html( $step['hint'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

		</div>
		<?php
	}

	/**
	 * Compute the Getting Started panel's state. Returns an array with:
	 *   - hidden: bool — whether to suppress rendering entirely
	 *   - steps: list of {label, done, link, action, hint}
	 *
	 * The panel hides once the minimum bar is met: API key set AND at
	 * least one enrichment surface (company OR contact context) is
	 * active. Users who want both surfaces can still see partial-
	 * readiness in the relevant tabs; we don't nag once at least one
	 * surface is live.
	 *
	 * @return array
	 */
	private static function getting_started_state() {
		$has_api_key   = '' !== self::get_api_key();
		$company_mods  = class_exists( 'FCE_Context_Modules' )
			? count( FCE_Context_Modules::active() )
			: 0;
		$contact_mods  = class_exists( 'FCE_Contact_Context_Modules' )
			? count( FCE_Contact_Context_Modules::active() )
			: 0;

		$hidden = $has_api_key && ( ( $company_mods > 0 ) || ( $contact_mods > 0 ) );

		$steps = array(
			array(
				'label'  => __( 'Add your Anthropic API key', 'fluentcrm-contact-enrichment' ),
				'done'   => $has_api_key,
				'link'   => self::tab_url( self::TAB_API ),
				'action' => __( 'Open API Settings', 'fluentcrm-contact-enrichment' ),
				'hint'   => $has_api_key
					? __( 'Tip: click Test Connection on the API Settings tab to confirm the key works and that web search is enabled at your Anthropic org.', 'fluentcrm-contact-enrichment' )
					: __( 'Get a key at console.anthropic.com. Web search must be enabled at the org level — see the API Settings tab for the link.', 'fluentcrm-contact-enrichment' ),
			),
			array(
				'label'  => __( 'Configure Company Context (for enriching companies)', 'fluentcrm-contact-enrichment' ),
				'done'   => $company_mods > 0,
				'link'   => self::tab_url( self::TAB_CONTEXT ),
				'action' => __( 'Open Company Context', 'fluentcrm-contact-enrichment' ),
				'hint'   => $company_mods > 0
					? sprintf(
						/* translators: %d: count of active modules */
						_n( '%d active module.', '%d active modules.', $company_mods, 'fluentcrm-contact-enrichment' ),
						$company_mods
					)
					: __( 'Markdown describing what your organization considers important when researching companies — your mission, what alignment means to you, partnership models you actually use. The tab includes a starter example.', 'fluentcrm-contact-enrichment' ),
			),
			array(
				'label'  => __( 'Configure Contact Context (for enriching individual contacts)', 'fluentcrm-contact-enrichment' ),
				'done'   => $contact_mods > 0,
				'link'   => self::tab_url( self::TAB_CONTACT_CONTEXT ),
				'action' => __( 'Open Contact Context', 'fluentcrm-contact-enrichment' ),
				'hint'   => $contact_mods > 0
					? sprintf(
						/* translators: %d: count of active modules */
						_n( '%d active module.', '%d active modules.', $contact_mods, 'fluentcrm-contact-enrichment' ),
						$contact_mods
					)
					: __( 'Optional if you only enrich companies. For individual research (donor prospecting, cohort participant prep, sales prospecting, board recruitment), this tells Claude what your use case considers relevant.', 'fluentcrm-contact-enrichment' ),
			),
		);

		return array(
			'hidden' => $hidden,
			'steps'  => $steps,
		);
	}

	private static function render_notices() {
		$msg = isset( $_GET['fce_msg'] ) ? sanitize_key( wp_unslash( $_GET['fce_msg'] ) ) : '';
		switch ( $msg ) {
			case 'saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'fluentcrm-contact-enrichment' ) . '</p></div>';
				break;
			case 'connection_ok':
				$company_active = class_exists( 'FCE_Context_Modules' ) ? count( FCE_Context_Modules::active() ) : 0;
				$contact_active = class_exists( 'FCE_Contact_Context_Modules' ) ? count( FCE_Contact_Context_Modules::active() ) : 0;
				if ( 0 === $company_active && 0 === $contact_active ) {
					$msg = sprintf(
						/* translators: 1: link to Company Context tab, 2: link to Contact Context tab */
						__( 'Connection successful — API key valid and web search is enabled. Next: configure at least one %1$s (for company enrichment) or %2$s (for individual contact research) before you can enrich anything.', 'fluentcrm-contact-enrichment' ),
						sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( self::TAB_CONTEXT ) ), esc_html__( 'Company Context module', 'fluentcrm-contact-enrichment' ) ),
						sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( self::TAB_CONTACT_CONTEXT ) ), esc_html__( 'Contact Context module', 'fluentcrm-contact-enrichment' ) )
					);
					echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connection successful — API key valid and web search is enabled. You\'re ready to enrich.', 'fluentcrm-contact-enrichment' ) . '</p></div>';
				}
				break;
			case 'connection_fail':
				$error = get_transient( 'fce_test_result' );
				delete_transient( 'fce_test_result' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
					sprintf(
						/* translators: %s: error message from the API */
						__( 'Connection failed: %s', 'fluentcrm-contact-enrichment' ),
						$error ? $error : __( 'unknown error', 'fluentcrm-contact-enrichment' )
					)
				) . '</p></div>';
				break;
			case 'resync_unconfirmed':
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Resync not run: the confirmation must be exactly RESYNC (uppercase, no extra spaces).', 'fluentcrm-contact-enrichment' ) . '</p></div>';
				break;
			case 'resync_done':
				$result = get_transient( 'fce_bulk_resync_result' );
				delete_transient( 'fce_bulk_resync_result' );
				if ( is_array( $result ) ) {
					$message = sprintf(
						/* translators: 1: companies processed, 2: contacts updated, 3: companies skipped */
						esc_html__( 'Resync complete. %1$d companies processed, %2$d contacts updated, %3$d companies skipped (no cached enrichment values).', 'fluentcrm-contact-enrichment' ),
						(int) $result['companies_processed'],
						(int) $result['contacts_updated'],
						(int) $result['companies_skipped']
					);
				} else {
					$message = esc_html__( 'Resync complete.', 'fluentcrm-contact-enrichment' );
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
		}
	}
}
