<?php
/**
 * Contact profile section — adds the individual-research "Enrich" button
 * to FluentCRM's contact profile via the Extender API and wires the
 * admin-ajax trigger.
 *
 * Mirrors FCE_Company_Section but for the contact-side individual-research
 * pipeline (v0.7.0+). Distinct from the org_* fields the contact carries:
 * those are mirrored from the company; these are intrinsic to the person.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Contact_Section {

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_section' ) );
		add_action( 'wp_ajax_' . FCE_AJAX_CONTACT, array( __CLASS__, 'ajax_trigger' ) );
		add_action( 'admin_footer', array( __CLASS__, 'enqueue_click_handler' ) );
	}

	/**
	 * Register the Individual Enrichment section on FluentCRM's contact
	 * profile. Same pattern as FCE_Company_Section::register_section —
	 * wrapped in try/catch because the FCApi proxy silently swallows
	 * exceptions.
	 */
	public static function register_section() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		try {
			$extender = FluentCrmApi( 'extender' );
			if ( $extender && method_exists( $extender, '__call' ) ) {
				$extender->addProfileSection(
					FCE_CONTACT_SECTION,
					__( 'Individual Enrichment', 'fluentcrm-contact-enrichment' ),
					array( __CLASS__, 'render_section' )
				);
			}
		} catch ( \Throwable $e ) {
			// Silently noop.
		}
	}

	/**
	 * Render callback for the contact profile section.
	 *
	 * @param mixed  $content
	 * @param object $subscriber FluentCrm\App\Models\Subscriber
	 * @return array { heading: string, content_html: string }
	 */
	public static function render_section( $content, $subscriber ) {
		$cf = $subscriber->custom_fields();

		$status     = isset( $cf[ FCE_IND_STATUS ] ) && '' !== $cf[ FCE_IND_STATUS ]
			? (string) $cf[ FCE_IND_STATUS ]
			: 'Not Enriched';
		$date       = isset( $cf[ FCE_IND_DATE ] ) ? (string) $cf[ FCE_IND_DATE ] : '';
		$confidence = isset( $cf[ FCE_IND_CONFIDENCE ] ) ? (string) $cf[ FCE_IND_CONFIDENCE ] : '';
		$consent    = isset( $cf[ FCE_IND_CONSENT ] ) && '' !== $cf[ FCE_IND_CONSENT ]
			? (string) $cf[ FCE_IND_CONSENT ]
			: 'Allowed';

		$last_note  = self::most_recent_research_note( (int) $subscriber->id );
		$is_running = in_array( $status, array( 'Pending', 'Processing' ), true );
		$is_blocked = ( 'Restricted' === $consent );

		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce    = wp_create_nonce( FCE_NONCE_CONTACT );

		ob_start();
		?>
		<div class="fce-section" style="padding: 1em 0;">
			<dl class="fce-grid" style="display: grid; grid-template-columns: max-content 1fr; gap: 0.4em 1em; margin: 0 0 1em 0;">
				<dt><strong><?php esc_html_e( 'Status', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
				<dd id="fce-contact-status-value"><?php echo esc_html( $status ); ?></dd>

				<?php if ( '' !== $date ) : ?>
					<dt><strong><?php esc_html_e( 'Last enriched', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
					<dd><?php echo esc_html( $date ); ?></dd>
				<?php endif; ?>

				<?php if ( '' !== $confidence ) : ?>
					<dt><strong><?php esc_html_e( 'Confidence', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
					<dd><?php echo esc_html( $confidence ); ?></dd>
				<?php endif; ?>

				<dt><strong><?php esc_html_e( 'Research Consent', 'fluentcrm-contact-enrichment' ); ?></strong></dt>
				<dd><?php echo esc_html( $consent ); ?></dd>
			</dl>

			<?php if ( $is_blocked ) : ?>
				<p style="background: #fef6e4; border-left: 4px solid #f0b849; padding: 0.75em 1em; margin: 0 0 1em 0;">
					<?php esc_html_e( 'Research is blocked for this contact (Research Consent is set to Restricted). To enable enrichment, change the Research Consent field in the Custom Profile Data sidebar to Allowed.', 'fluentcrm-contact-enrichment' ); ?>
				</p>
			<?php else : ?>
				<button type="button"
					id="fce-contact-enrich-button"
					data-contact-id="<?php echo (int) $subscriber->id; ?>"
					data-ajax-url="<?php echo $ajax_url; ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					class="el-button el-button--primary"
					<?php disabled( $is_running ); ?>>
					<?php
					if ( $is_running ) {
						esc_html_e( 'Queued — refresh to see status', 'fluentcrm-contact-enrichment' );
					} elseif ( 'Complete' === $status ) {
						esc_html_e( 'Re-enrich This Contact', 'fluentcrm-contact-enrichment' );
					} else {
						esc_html_e( 'Enrich This Contact', 'fluentcrm-contact-enrichment' );
					}
					?>
				</button>
			<?php endif; ?>

			<?php if ( $last_note ) : ?>
				<p style="margin-top: 1em;">
					<small>
						<?php esc_html_e( 'Most recent research note:', 'fluentcrm-contact-enrichment' ); ?>
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
						esc_html__( 'Contact research uses the Anthropic API with web search, grounded in Apra-derived professional standards. %s', 'fluentcrm-contact-enrichment' ),
						sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'options-general.php?page=' . FCE_MENU_SLUG . '&tab=contact_context' ) ),
							esc_html__( 'Configure contact context modules and capacity tiers →', 'fluentcrm-contact-enrichment' )
						)
					);
					?>
				</small>
			</p>
		</div>
		<?php
		// Note: any inline <script> here would be silently dropped because
		// FluentCRM's Vue admin renders this HTML through `innerHTML`,
		// which doesn't execute embedded scripts. The click handler is
		// enqueued separately via admin_footer.
		$html = ob_get_clean();

		return array(
			'heading'      => __( 'Individual Enrichment', 'fluentcrm-contact-enrichment' ),
			'content_html' => $html,
		);
	}

	/**
	 * Print the click handler in the admin footer. Same approach as
	 * FCE_Company_Section: event delegation against document, gated to
	 * FluentCRM admin screens, no script-state inlining.
	 */
	public static function enqueue_click_handler() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( false === strpos( (string) $screen->id, 'fluentcrm-admin' ) ) {
			return;
		}

		?>
		<script>
		(function () {
			if (window.__fceContactEnrichBound) { return; }
			window.__fceContactEnrichBound = true;

			document.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('#fce-contact-enrich-button');
				if (!btn) { return; }
				e.preventDefault();
				if (btn.disabled) { return; }

				var ajaxUrl = btn.getAttribute('data-ajax-url');
				var nonce = btn.getAttribute('data-nonce');
				var contactId = btn.getAttribute('data-contact-id');
				if (!ajaxUrl || !nonce || !contactId) { return; }

				btn.disabled = true;
				var originalText = btn.textContent;
				btn.textContent = '<?php echo esc_js( __( 'Queueing…', 'fluentcrm-contact-enrichment' ) ); ?>';

				var formData = new FormData();
				formData.append('action', '<?php echo esc_js( FCE_AJAX_CONTACT ); ?>');
				formData.append('contact_id', contactId);
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
						var statusEl = document.getElementById('fce-contact-status-value');
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
	 * Admin-AJAX handler. Cap-checks, nonce-checks, validates the
	 * contact, flips their status to Pending, and schedules the cron
	 * event. The consent check happens inside the cron job itself so a
	 * race (admin sets Restricted between click and cron-fire) still
	 * gets respected.
	 *
	 * @return void  always exits via wp_send_json_*
	 */
	public static function ajax_trigger() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fluentcrm-contact-enrichment' ) ), 403 );
		}

		check_ajax_referer( FCE_NONCE_CONTACT );

		$contact_id = isset( $_POST['contact_id'] ) ? (int) $_POST['contact_id'] : 0;
		if ( $contact_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing contact id.', 'fluentcrm-contact-enrichment' ) ), 400 );
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
			wp_send_json_error( array( 'message' => __( 'FluentCRM is not loaded.', 'fluentcrm-contact-enrichment' ) ), 500 );
		}

		$contact = \FluentCrm\App\Models\Subscriber::find( $contact_id );
		if ( ! $contact ) {
			wp_send_json_error( array( 'message' => __( 'Contact not found.', 'fluentcrm-contact-enrichment' ) ), 404 );
		}

		$contact->syncCustomFieldValues( array( FCE_IND_STATUS => 'Pending' ), false );

		$scheduled = wp_schedule_single_event(
			time() + 5,
			FCE_CRON_CONTACT,
			array( $contact_id )
		);

		if ( false === $scheduled ) {
			wp_send_json_error( array(
				'message' => __( 'Could not schedule enrichment job. WP-Cron may be disabled.', 'fluentcrm-contact-enrichment' ),
			), 500 );
		}

		wp_send_json_success( array(
			'message'    => __( 'Contact enrichment queued.', 'fluentcrm-contact-enrichment' ),
			'contact_id' => $contact_id,
		) );
	}

	/**
	 * Find the most recent research note (or failure note) for a contact,
	 * matching by title prefix so manually-added admin notes don't get
	 * mistaken for enrichment output.
	 *
	 * @param int $contact_id
	 * @return object|null
	 */
	private static function most_recent_research_note( $contact_id ) {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\SubscriberNote' ) ) {
			return null;
		}
		return \FluentCrm\App\Models\SubscriberNote::where( 'subscriber_id', $contact_id )
			->where( function ( $q ) {
				$q->where( 'title', 'LIKE', 'Contact Research — %' )
					->orWhere( 'title', 'LIKE', 'Contact Enrichment Failed — %' );
			} )
			->orderBy( 'id', 'desc' )
			->first();
	}
}
