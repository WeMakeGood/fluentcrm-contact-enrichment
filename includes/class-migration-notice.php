<?php
/**
 * One-time admin notice surfacing the pre-v1.0.0 plugin-managed Anthropic
 * API key so admins can paste it into FluentCRM → Settings → AI Configuration before the
 * old option is deleted.
 *
 * Lifecycle:
 *   - Notice appears on every admin page load while:
 *       (a) FCE_OPT_API_KEY is still populated, AND
 *       (b) the admin hasn't dismissed it, AND
 *       (c) we're inside the 30-day grace window since v1.0.0 activation.
 *   - Dismissal sets a user-meta flag; subsequent loads skip the render.
 *   - When the grace window ends (or admin clicks "I've migrated, delete
 *     the legacy key"), FCE_OPT_API_KEY is deleted.
 *
 * @package Fluentcrm_Contact_Enrichment
 */

defined( 'ABSPATH' ) || exit;

class FCE_Migration_Notice {

	const DISMISS_USER_META       = 'fce_dismissed_legacy_key_notice';
	const GRACE_PERIOD_OPTION     = 'fce_legacy_key_grace_started_at';
	const GRACE_PERIOD_DAYS       = 30;
	const DISMISS_ACTION          = 'fce_dismiss_legacy_key';
	const FORGET_ACTION           = 'fce_forget_legacy_key';
	const NONCE                   = 'fce_migration_notice';

	public static function register_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::FORGET_ACTION,  array( __CLASS__, 'handle_forget' ) );
		add_action( 'admin_init',  array( __CLASS__, 'maybe_expire' ) );
	}

	/**
	 * Render the notice if all conditions are met.
	 */
	public static function render() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			return;
		}
		if ( ! self::needs_notice() ) {
			return;
		}

		$legacy_key = FCE_Admin_Settings::get_api_key();
		if ( '' === $legacy_key ) {
			return;
		}

		$ai_settings_url = admin_url( 'admin.php?page=fluentcrm-admin#/settings/ai_settings' );
		$dismiss_url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::NONCE
		);
		$forget_url      = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::FORGET_ACTION ),
			self::NONCE
		);

		?>
		<div class="notice notice-info" style="position: relative;">
			<h3 style="margin-top: 0.6em;"><?php esc_html_e( 'Migrate your enrichment API key to FluentCRM', 'fluentcrm-contact-enrichment' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: link to FluentCRM AI settings */
					esc_html__( 'FluentCRM Contact Enrichment now reads its API key from FluentCRM\'s AI configuration. Copy your existing Anthropic key into %s — set the provider to Claude and enable AI — then dismiss this notice.', 'fluentcrm-contact-enrichment' ),
					'<a href="' . esc_url( $ai_settings_url ) . '"><strong>' . esc_html__( 'FluentCRM → Settings → AI Configuration', 'fluentcrm-contact-enrichment' ) . '</strong></a>'
				);
				?>
			</p>
			<p>
				<details>
					<summary style="cursor: pointer;"><?php esc_html_e( 'Show legacy key', 'fluentcrm-contact-enrichment' ); ?></summary>
					<p style="margin-top: 0.75em;">
						<code style="user-select: all; padding: 0.25em 0.5em; background: #fff; border: 1px solid #c3c4c7; display: inline-block; word-break: break-all; max-width: 100%;"><?php echo esc_html( $legacy_key ); ?></code>
					</p>
					<p class="description">
						<?php esc_html_e( 'Click to select, copy, paste into FluentCRM\'s AI settings.', 'fluentcrm-contact-enrichment' ); ?>
					</p>
				</details>
			</p>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button">
					<?php esc_html_e( 'I\'ve migrated — dismiss this notice', 'fluentcrm-contact-enrichment' ); ?>
				</a>
				<a href="<?php echo esc_url( $forget_url ); ?>" class="button button-link-delete" style="margin-left: 0.5em;" onclick="return confirm('<?php echo esc_js( __( 'Delete the encrypted legacy key from this install? This cannot be undone. Make sure you have copied it into FluentCRM\'s AI settings first.', 'fluentcrm-contact-enrichment' ) ); ?>');">
					<?php esc_html_e( 'Delete legacy key permanently', 'fluentcrm-contact-enrichment' ); ?>
				</a>
			</p>
			<p class="description">
				<?php
				$expires_at = self::grace_period_expires_at();
				if ( $expires_at ) {
					printf(
						/* translators: %s: date string */
						esc_html__( 'The legacy key will be deleted automatically on %s.', 'fluentcrm-contact-enrichment' ),
						esc_html( wp_date( get_option( 'date_format' ), $expires_at ) )
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss the notice for the current user. The legacy key remains stored
	 * (so other admins still see the notice) until either everyone dismisses
	 * OR the grace period ends OR someone clicks "Delete legacy key."
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fluentcrm-contact-enrichment' ) );
		}
		check_admin_referer( self::NONCE );

		update_user_meta( get_current_user_id(), self::DISMISS_USER_META, time() );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/**
	 * Delete the legacy key option immediately.
	 */
	public static function handle_forget() {
		if ( ! current_user_can( FCE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fluentcrm-contact-enrichment' ) );
		}
		check_admin_referer( self::NONCE );

		delete_option( FCE_OPT_API_KEY );
		delete_option( self::GRACE_PERIOD_OPTION );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/**
	 * Auto-delete the legacy key once the grace window has elapsed. Runs on
	 * every admin_init, so it self-cleans without needing a cron task.
	 */
	public static function maybe_expire() {
		$expires_at = self::grace_period_expires_at();
		if ( null === $expires_at ) {
			return;
		}
		if ( time() < $expires_at ) {
			return;
		}
		delete_option( FCE_OPT_API_KEY );
		delete_option( self::GRACE_PERIOD_OPTION );
	}

	/**
	 * Returns true if we should attempt to render the notice for the current
	 * request. Bails early on the cheap checks so the heavier decrypt only
	 * runs when needed.
	 *
	 * @return bool
	 */
	private static function needs_notice() {
		$stored = get_option( FCE_OPT_API_KEY, '' );
		if ( '' === $stored ) {
			return false;
		}

		// Track grace-period start the first time we see the notice is needed.
		// Lets us delete the option automatically after GRACE_PERIOD_DAYS even
		// if no admin ever visits the screen.
		if ( '' === (string) get_option( self::GRACE_PERIOD_OPTION, '' ) ) {
			update_option( self::GRACE_PERIOD_OPTION, time(), false );
		}

		$dismissed = (int) get_user_meta( get_current_user_id(), self::DISMISS_USER_META, true );
		if ( $dismissed > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Unix timestamp when the grace period ends, or null if not yet started.
	 *
	 * @return int|null
	 */
	private static function grace_period_expires_at() {
		$started = (int) get_option( self::GRACE_PERIOD_OPTION, 0 );
		if ( $started <= 0 ) {
			return null;
		}
		return $started + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );
	}
}
