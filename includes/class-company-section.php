<?php
/**
 * Company profile section — adds the "Enrich" button to FluentCRM's company
 * profile via the Extender API and wires the admin-ajax trigger.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Company_Section {

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_section' ) );
		add_action( 'wp_ajax_' . FCE_AJAX_TRIGGER, array( __CLASS__, 'ajax_trigger' ) );
		add_action( 'admin_footer', array( __CLASS__, 'enqueue_click_handler' ) );
	}

	/**
	 * Register the Enrichment section with FluentCRM's company profile.
	 *
	 * Wraps the call in try/catch because FluentCrmApi('extender') returns
	 * an FCApi proxy that silently swallows errors via __call. If FluentCRM
	 * is missing or its API surface changes, we'd rather fail open and let
	 * the rest of the plugin work than fatal at boot.
	 *
	 * @return void
	 */
	public static function register_section() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		try {
			$extender = FluentCrmApi( 'extender' );
			if ( $extender && method_exists( $extender, '__call' ) ) {
				$extender->addCompanyProfileSection(
					FCE_SECTION_KEY,
					__( 'Enrichment', 'fluentcrm-contact-enrichment' ),
					array( __CLASS__, 'render_section' )
				);
			}
		} catch ( \Throwable $e ) {
			// Silently noop: see FluentCRM Extender notes in
			// docs/fluentcrm-enrichment-research.md.
		}
	}

	/**
	 * Render callback for the company profile section. Returns the
	 * shape FluentCRM's renderer expects.
	 *
	 * @param mixed  $content
	 * @param object $company FluentCrm\App\Models\Company instance
	 * @return array { heading: string, content_html: string }
	 */
	public static function render_section( $content, $company ) {
		$status     = self::status_for( $company );
		$date       = self::custom_value( $company, FCE_FIELD_DATE );
		$confidence = self::custom_value( $company, FCE_FIELD_CONFIDENCE );
		$last_note  = self::most_recent_enrichment_note( (int) $company->id );

		$is_running = in_array( $status, array( 'Pending', 'Processing' ), true );

		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce    = wp_create_nonce( FCE_NONCE_TRIGGER );

		ob_start();
		?>
		<div class="fce-section" style="padding: 1em 0;">
			<dl style="display: grid; grid-template-columns: max-content 1fr; gap: 0.4em 1em; margin: 0 0 1em 0;">
				<dt><strong><?php esc_html_e( 'Status', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
				<dd id="fce-status-value"><?php echo esc_html( $status ); ?></dd>

				<?php if ( '' !== $date ) : ?>
					<dt><strong><?php esc_html_e( 'Last enriched', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
					<dd><?php echo esc_html( $date ); ?></dd>
				<?php endif; ?>

				<?php if ( '' !== $confidence ) : ?>
					<dt><strong><?php esc_html_e( 'Confidence', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
					<dd><?php echo esc_html( $confidence ); ?></dd>
				<?php endif; ?>
			</dl>

			<button type="button"
				id="fce-enrich-button"
				data-company-id="<?php echo (int) $company->id; ?>"
				data-ajax-url="<?php echo $ajax_url; ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				class="el-button el-button--primary"
				<?php disabled( $is_running ); ?>>
				<?php
				if ( $is_running ) {
					esc_html_e( 'Queued — refresh to see status', 'fluentcrm-contact-enrichment' );
				} elseif ( 'Complete' === $status ) {
					esc_html_e( 'Re-enrich This Company', 'fluentcrm-contact-enrichment' );
				} else {
					esc_html_e( 'Enrich This Company', 'fluentcrm-contact-enrichment' );
				}
				?>
			</button>

			<?php if ( $last_note ) : ?>
				<p style="margin-top: 1em;">
					<small>
						<?php esc_html_e( 'Most recent enrichment note:', 'fluentcrm-contact-enrichment' ); ?>
						<em><?php echo esc_html( $last_note->title ); ?></em>
						(<?php echo esc_html( $last_note->created_at ); ?>)
					</small>
				</p>
			<?php endif; ?>

			<p style="margin-top: 1em;">
				<small>
					<?php
					printf(
						/* translators: %s: link to settings page */
						esc_html__( 'Enrichment uses the Anthropic API with web search. %s', 'fluentcrm-contact-enrichment' ),
						sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'options-general.php?page=' . FCE_MENU_SLUG ) ),
							esc_html__( 'Configure context modules and focus areas →', 'fluentcrm-contact-enrichment' )
						)
					);
					?>
				</small>
			</p>
		</div>

		<?php
		// Note: any inline <script> here would be silently dropped because
		// FluentCRM's Vue admin renders this HTML through `innerHTML`,
		// which does not execute embedded scripts. The click handler is
		// enqueued separately via admin_footer (enqueue_click_handler())
		// and uses event delegation against the body so it works no
		// matter when the Vue tree mounts the button.
		$html = ob_get_clean();

		return array(
			'heading'      => __( 'Enrichment', 'fluentcrm-contact-enrichment' ),
			'content_html' => $html,
		);
	}

	/**
	 * Print the click handler in the admin footer. The handler uses event
	 * delegation against `document` so it works whether the Vue admin
	 * mounts the button before or after this script loads. Reads
	 * company_id, nonce, and ajax URL from the button's data attributes
	 * (set in render_section()), so we don't need to inline any
	 * page-state into the script itself.
	 *
	 * @return void
	 */
	public static function enqueue_click_handler() {
		// Only emit on FluentCRM admin pages — keeps this off every other
		// admin screen.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		// Top-level FluentCRM page id is "toplevel_page_fluentcrm-admin";
		// any sub-page registered under it is "*_page_fluentcrm-admin..." too.
		// Match the "fluentcrm-admin" suffix to catch all of them.
		if ( false === strpos( (string) $screen->id, 'fluentcrm-admin' ) ) {
			return;
		}

		?>
		<script>
		(function () {
			if (window.__fceEnrichBound) { return; }
			window.__fceEnrichBound = true;

			document.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('#fce-enrich-button');
				if (!btn) { return; }
				e.preventDefault();
				if (btn.disabled) { return; }

				var ajaxUrl = btn.getAttribute('data-ajax-url');
				var nonce = btn.getAttribute('data-nonce');
				var companyId = btn.getAttribute('data-company-id');
				if (!ajaxUrl || !nonce || !companyId) { return; }

				btn.disabled = true;
				var originalText = btn.textContent;
				btn.textContent = '<?php echo esc_js( __( 'Queueing…', 'fluentcrm-contact-enrichment' ) ); ?>';

				var formData = new FormData();
				formData.append('action', '<?php echo esc_js( FCE_AJAX_TRIGGER ); ?>');
				formData.append('company_id', companyId);
				formData.append('_wpnonce', nonce);

				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.success) {
						btn.textContent = '<?php echo esc_js( __( 'Queued — refresh to see status', 'fluentcrm-contact-enrichment' ) ); ?>';
						var statusEl = document.getElementById('fce-status-value');
						if (statusEl) { statusEl.textContent = 'Pending'; }
					} else {
						btn.disabled = false;
						btn.textContent = originalText;
						alert('<?php echo esc_js( __( 'Could not queue enrichment:', 'fluentcrm-contact-enrichment' ) ); ?> ' +
							((data && data.data && data.data.message) || 'unknown error'));
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = originalText;
					alert('<?php echo esc_js( __( 'Network error queueing enrichment.', 'fluentcrm-contact-enrichment' ) ); ?>');
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Admin-AJAX handler. Sets enrichment_status to Pending and schedules
	 * a single cron event for the company. WP-Cron de-dupes
	 * (hook + args + time bucket) so simultaneous double-clicks collapse
	 * into one event.
	 *
	 * @return void  always exits via wp_send_json_*
	 */
	public static function ajax_trigger() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fluentcrm-contact-enrichment' ) ), 403 );
		}

		check_ajax_referer( FCE_NONCE_TRIGGER );

		$company_id = isset( $_POST['company_id'] ) ? (int) $_POST['company_id'] : 0;
		if ( $company_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing company id.', 'fluentcrm-contact-enrichment' ) ), 400 );
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			wp_send_json_error( array( 'message' => __( 'FluentCRM is not loaded.', 'fluentcrm-contact-enrichment' ) ), 500 );
		}

		$company = \FluentCrm\App\Models\Company::find( $company_id );
		if ( ! $company ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'fluentcrm-contact-enrichment' ) ), 404 );
		}

		// Flip status to Pending immediately so the UI reflects state on
		// the next view of the section.
		\FluentCrmApi( 'companies' )->createOrUpdate( array(
			'id'            => $company_id,
			'name'          => (string) $company->name,
			'custom_values' => array( FCE_FIELD_STATUS => 'Pending' ),
		) );

		// Schedule the cron job. Small offset so the response gets back to
		// the browser before the job runs (otherwise Local-style sites that
		// poll WP-Cron via the next page request can fire it inline and
		// stall the response).
		$scheduled = wp_schedule_single_event(
			time() + 5,
			FCE_CRON_HOOK,
			array( $company_id )
		);

		if ( false === $scheduled ) {
			wp_send_json_error( array(
				'message' => __( 'Could not schedule enrichment job. WP-Cron may be disabled.', 'fluentcrm-contact-enrichment' ),
			), 500 );
		}

		wp_send_json_success( array(
			'message'    => __( 'Enrichment queued.', 'fluentcrm-contact-enrichment' ),
			'company_id' => $company_id,
		) );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Read a custom_values key from a hydrated Company model. Defaults to
	 * empty string when the key is absent.
	 *
	 * @param object $company
	 * @param string $slug
	 * @return string
	 */
	private static function custom_value( $company, $slug ) {
		$cv = isset( $company->meta['custom_values'] ) && is_array( $company->meta['custom_values'] )
			? $company->meta['custom_values']
			: array();
		return isset( $cv[ $slug ] ) ? (string) $cv[ $slug ] : '';
	}

	/**
	 * @param object $company
	 * @return string  current status, defaulting to "Not Enriched"
	 */
	private static function status_for( $company ) {
		$status = self::custom_value( $company, FCE_FIELD_STATUS );
		return '' !== $status ? $status : 'Not Enriched';
	}

	/**
	 * Find the most recent enrichment note for a company. Matches by title
	 * prefix so manually-added notes don't get mistaken for enrichment
	 * runs.
	 *
	 * @param int $company_id
	 * @return object|null
	 */
	private static function most_recent_enrichment_note( $company_id ) {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CompanyNote' ) ) {
			return null;
		}
		return \FluentCrm\App\Models\CompanyNote::where( 'subscriber_id', $company_id )
			->where( function ( $q ) {
				$q->where( 'title', 'LIKE', 'Enrichment Research — %' )
					->orWhere( 'title', 'LIKE', 'Enrichment Failed — %' );
			} )
			->orderBy( 'id', 'desc' )
			->first();
	}
}
