<?php
/**
 * Connections page panel: Google Analytics 4
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
$is_connected          = $credential_manager->has_credentials( 'ga4' );
$current_property_id   = get_option( 'specflux_mac_ga4_property_id' );
$panel_state           = Form_Ui::connection_state( $is_connected, $is_connected ? Connection_Tester::get_last_result( 'ga4' ) : null );
?>
	<section class="aui-card smac-connection-card" data-platform="ga4">
		<div class="aui-card-h">
			<span class="aui-tile" aria-hidden="true"><?php echo Ui_Kit::icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
			<div>
				<h2 class="aui-card-t"><?php esc_html_e( 'Google Analytics 4', 'specflux-marketing-analytics-chat' ); ?></h2>
				<p class="aui-card-d"><?php esc_html_e( 'Connect to Google Analytics 4 to access traffic metrics, user behavior, and conversion data.', 'specflux-marketing-analytics-chat' ); ?></p>
			</div>
			<?php echo Form_Ui::status_badge( $panel_state['state'], $panel_state['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::status_badge(). ?>
		</div>

		<?php if ( ! $is_connected ) : ?>
			<div class="aui-card-b smac-fields">
				<?php if ( $can_connect_google ) : ?>
					<div>
						<h3 class="smac-subhead"><?php esc_html_e( 'Connect your Google Analytics account', 'specflux-marketing-analytics-chat' ); ?></h3>
						<p class="smac-copy">
							<?php esc_html_e( 'Sign in with Google and grant read-only access to your Google Analytics data.', 'specflux-marketing-analytics-chat' ); ?>
							<?php if ( ! $has_oauth_credentials ) : ?>
								<?php esc_html_e( 'No Google Cloud project or API keys are needed.', 'specflux-marketing-analytics-chat' ); ?>
							<?php endif; ?>
						</p>
					</div>
					<div class="smac-actions">
						<button type="button" class="aui-btn aui-btn--primary aui-btn--lg smac-google-connect" data-service="ga4">
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
					<?php echo Form_Ui::notice( 'success', __( 'Connected! Your Google Analytics 4 account is connected and ready to use.', 'specflux-marketing-analytics-chat' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
				<?php endif; ?>

				<div>
					<h3 class="smac-subhead"><?php esc_html_e( 'Select GA4 Property', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p class="smac-copy"><?php esc_html_e( 'Choose which Google Analytics 4 property to query for data:', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="ga4_property_selector"><?php esc_html_e( 'GA4 Property', 'specflux-marketing-analytics-chat' ); ?></label>

					<div id="ga4-property-loading" class="aui-help" role="status" style="display: none;">
						<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>
						<?php esc_html_e( 'Loading properties...', 'specflux-marketing-analytics-chat' ); ?>
					</div>

					<div id="ga4-property-error" class="aui-notice aui-notice--error" role="alert" style="display: none;">
						<?php echo Ui_Kit::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
						<p></p>
					</div>

					<select id="ga4_property_selector" name="ga4_property_id" class="aui-select" style="display: none;">
						<option value=""><?php esc_html_e( 'Select a property...', 'specflux-marketing-analytics-chat' ); ?></option>
					</select>

					<?php if ( $current_property_id ) : ?>
						<p class="aui-help">
							<?php
							printf(
								/* translators: %s: property ID */
								esc_html__( 'Currently selected property: %s', 'specflux-marketing-analytics-chat' ),
								'<strong>' . esc_html( $current_property_id ) . '</strong>'
							);
							?>
						</p>
					<?php else : ?>
						<p class="aui-help"><?php esc_html_e( 'No property selected yet. Please select a property from the list above.', 'specflux-marketing-analytics-chat' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="smac-actions">
					<?php if ( $current_property_id ) : ?>
						<button type="button" id="load-ga4-properties" class="aui-btn aui-btn--secondary">
							<?php esc_html_e( 'Change Property', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					<?php else : ?>
						<button type="button" id="load-ga4-properties" class="aui-btn aui-btn--secondary" data-auto="1">
							<?php esc_html_e( 'Load Available Properties', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" id="save-ga4-property" class="aui-btn aui-btn--primary" style="display: none;">
						<?php esc_html_e( 'Save Selected Property', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				</div>
			</div>

			<form method="post" action="" class="smac-disconnect-form">
				<?php wp_nonce_field( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' ); ?>
				<input type="hidden" name="service" value="ga4" />

				<div class="aui-card-f">
					<button type="button" class="aui-btn aui-btn--secondary test-connection" data-platform="ga4">
						<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
					</button>
					<button type="button" class="aui-btn aui-btn--danger-outline aui-end" data-aui-open="smac-disconnect-ga4">
						<?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				</div>

				<div class="aui-backdrop" id="smac-disconnect-ga4" hidden>
					<div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="smac-disconnect-ga4-title">
						<h2 id="smac-disconnect-ga4-title"><?php esc_html_e( 'Disconnect Google Analytics 4?', 'specflux-marketing-analytics-chat' ); ?></h2>
						<p><?php esc_html_e( 'Are you sure you want to disconnect from Google Analytics?', 'specflux-marketing-analytics-chat' ); ?></p>
						<div class="aui-dialog-f">
							<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Keep connected', 'specflux-marketing-analytics-chat' ); ?></button>
							<button type="submit" name="disconnect_oauth" value="1" class="aui-btn aui-btn--danger" data-aui-close><?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?></button>
						</div>
					</div>
				</div>
			</form>
		<?php endif; ?>
	</section>
