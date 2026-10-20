<?php
/**
 * Connections page panel: Google Search Console
 *
 * Included by connections.php, which provides $oauth_handler, $credential_manager
 * and the page-level state variables.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Form_Ui;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;

$has_oauth_credentials = $oauth_handler->has_oauth_credentials();
$can_connect_google    = $oauth_handler->can_connect_google();
$is_connected          = $credential_manager->has_credentials( 'gsc' );
$current_site_url      = get_option( 'specflux_mac_gsc_site_url' );
$panel_state           = Form_Ui::connection_state( $is_connected, $is_connected ? Connection_Tester::get_last_result( 'gsc' ) : null );
?>
	<section class="aui-card smac-connection-card" data-platform="gsc">
		<div class="aui-card-h">
			<span class="aui-tile" aria-hidden="true"><?php echo Ui_Kit::icon( 'magnifying-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
			<div>
				<h2 class="aui-card-t"><?php esc_html_e( 'Google Search Console', 'specflux-marketing-analytics-chat' ); ?></h2>
				<p class="aui-card-d"><?php esc_html_e( 'Connect to Google Search Console to access search performance data, indexing status, and query analytics.', 'specflux-marketing-analytics-chat' ); ?></p>
			</div>
			<?php echo Form_Ui::status_badge( $panel_state['state'], $panel_state['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::status_badge(). ?>
		</div>

		<?php if ( ! $is_connected ) : ?>
			<div class="aui-card-b smac-fields">
				<?php if ( $can_connect_google ) : ?>
					<div>
						<h3 class="smac-subhead"><?php esc_html_e( 'Connect your Google Search Console account', 'specflux-marketing-analytics-chat' ); ?></h3>
						<p class="smac-copy">
							<?php esc_html_e( 'Sign in with Google and grant read-only access to your Google Search Console data.', 'specflux-marketing-analytics-chat' ); ?>
							<?php if ( ! $has_oauth_credentials ) : ?>
								<?php esc_html_e( 'No Google Cloud project or API keys are needed.', 'specflux-marketing-analytics-chat' ); ?>
							<?php endif; ?>
						</p>
					</div>
					<div class="smac-actions">
						<button type="button" class="aui-btn aui-btn--primary aui-btn--lg smac-google-connect" data-service="gsc">
							<?php echo Ui_Kit::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
							<?php esc_html_e( 'Connect with Google', 'specflux-marketing-analytics-chat' ); ?>
						</button>
						<span class="smac-google-connect-status aui-help" role="status" style="display: none;"></span>
					</div>
				<?php else : ?>
					<div class="aui-notice aui-notice--warning" role="status">
						<?php echo Ui_Kit::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
						<div><?php esc_html_e( 'Hosted Google sign-in is disabled on this site, so you need to configure your own Google OAuth client before connecting.', 'specflux-marketing-analytics-chat' ); ?></div>
					</div>
				<?php endif; ?>

				<details class="smac-details smac-advanced-oauth">
					<summary><?php esc_html_e( 'Advanced: use your own Google Cloud OAuth client', 'specflux-marketing-analytics-chat' ); ?></summary>
					<p class="aui-help">
						<?php if ( $has_oauth_credentials ) : ?>
							<?php esc_html_e( 'Your own OAuth client is configured and will be used for this connection.', 'specflux-marketing-analytics-chat' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'By default, sign-in goes through the Specflux Google OAuth client. If your organisation requires its own Google Cloud project, configure a client ID and secret and it will be used instead.', 'specflux-marketing-analytics-chat' ); ?>
						<?php endif; ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-settings&tab=google-api' ) ); ?>"><?php esc_html_e( 'Google API settings', 'specflux-marketing-analytics-chat' ); ?></a>
					</p>
				</details>
			</div>

		<?php else : ?>
			<div class="aui-card-b smac-fields">
				<?php if ( '' !== $panel_state['message'] ) : ?>
					<?php echo Form_Ui::notice( 'error', $panel_state['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
				<?php else : ?>
					<?php echo Form_Ui::notice( 'success', __( 'Connected! Your Google Search Console account is connected and ready to use.', 'specflux-marketing-analytics-chat' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
				<?php endif; ?>

				<div>
					<h3 class="smac-subhead"><?php esc_html_e( 'Select Search Console Property', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p class="smac-copy"><?php esc_html_e( 'Choose which Search Console property to query for data:', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="gsc_site_selector"><?php esc_html_e( 'Site URL', 'specflux-marketing-analytics-chat' ); ?></label>

					<div id="gsc-site-loading" class="aui-help" role="status" style="display: none;">
						<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>
						<?php esc_html_e( 'Loading sites...', 'specflux-marketing-analytics-chat' ); ?>
					</div>

					<div id="gsc-site-error" class="aui-notice aui-notice--error" role="alert" style="display: none;">
						<?php echo Ui_Kit::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
						<p></p>
					</div>

					<select id="gsc_site_selector" name="gsc_site_url" class="aui-select" style="display: none;">
						<option value=""><?php esc_html_e( 'Select a site...', 'specflux-marketing-analytics-chat' ); ?></option>
					</select>

					<?php if ( $current_site_url ) : ?>
						<p class="aui-help">
							<?php
							printf(
								/* translators: %s: site URL */
								esc_html__( 'Currently selected site: %s', 'specflux-marketing-analytics-chat' ),
								'<strong>' . esc_html( $current_site_url ) . '</strong>'
							);
							?>
						</p>
					<?php else : ?>
						<p class="aui-help"><?php esc_html_e( 'No site selected yet. Please select a site from the list above.', 'specflux-marketing-analytics-chat' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="smac-actions">
					<?php if ( $current_site_url ) : ?>
						<button type="button" id="load-gsc-sites" class="aui-btn aui-btn--secondary">
							<?php esc_html_e( 'Change Site', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					<?php else : ?>
						<button type="button" id="load-gsc-sites" class="aui-btn aui-btn--secondary" data-auto="1">
							<?php esc_html_e( 'Load Available Sites', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" id="save-gsc-site" class="aui-btn aui-btn--primary" style="display: none;">
						<?php esc_html_e( 'Save Selected Site', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				</div>
			</div>

			<form method="post" action="" class="smac-disconnect-form">
				<?php wp_nonce_field( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' ); ?>
				<input type="hidden" name="service" value="gsc" />

				<div class="aui-card-f">
					<button type="button" class="aui-btn aui-btn--secondary test-connection" data-platform="gsc">
						<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
					</button>
					<button type="button" class="aui-btn aui-btn--danger-outline aui-end" data-aui-open="smac-disconnect-gsc">
						<?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				</div>

				<div class="aui-backdrop" id="smac-disconnect-gsc" hidden>
					<div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="smac-disconnect-gsc-title">
						<h2 id="smac-disconnect-gsc-title"><?php esc_html_e( 'Disconnect Google Search Console?', 'specflux-marketing-analytics-chat' ); ?></h2>
						<p><?php esc_html_e( 'Are you sure you want to disconnect from Google Search Console?', 'specflux-marketing-analytics-chat' ); ?></p>
						<div class="aui-dialog-f">
							<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Keep connected', 'specflux-marketing-analytics-chat' ); ?></button>
							<button type="submit" name="disconnect_oauth" value="1" class="aui-btn aui-btn--danger" data-aui-close><?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?></button>
						</div>
					</div>
				</div>
			</form>
		<?php endif; ?>
	</section>
