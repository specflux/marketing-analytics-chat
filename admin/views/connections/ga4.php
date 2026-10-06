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
	$has_oauth_credentials = $oauth_handler->has_oauth_credentials();
	$can_connect_google    = $oauth_handler->can_connect_google();
	$is_connected          = $credential_manager->has_credentials( 'ga4' );
	$current_property_id   = get_option( 'specflux_mac_ga4_property_id' );
?>
	<div class="connection-panel">
		<h3>
			<?php esc_html_e( 'Google Analytics 4 Configuration', 'specflux-marketing-analytics-chat' ); ?>
			<?php if ( $is_connected ) : ?>
				<span class="status-badge heading-connected">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
				</span>
			<?php else : ?>
				<span class="status-badge heading-disconnected">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Not Connected', 'specflux-marketing-analytics-chat' ); ?>
				</span>
			<?php endif; ?>
		</h3>
		<p><?php esc_html_e( 'Connect to Google Analytics 4 to access traffic metrics, user behavior, and conversion data.', 'specflux-marketing-analytics-chat' ); ?></p>

		<?php if ( ! $is_connected ) : ?>
			<?php if ( $can_connect_google ) : ?>
				<h4><?php esc_html_e( 'Connect your Google Analytics account', 'specflux-marketing-analytics-chat' ); ?></h4>
				<p>
					<?php esc_html_e( 'Sign in with Google and grant read-only access to your Google Analytics data.', 'specflux-marketing-analytics-chat' ); ?>
					<?php if ( ! $has_oauth_credentials ) : ?>
						<?php esc_html_e( 'No Google Cloud project or API keys are needed.', 'specflux-marketing-analytics-chat' ); ?>
					<?php endif; ?>
				</p>
				<p class="submit">
					<button type="button" class="button button-primary button-large smac-google-connect" data-service="ga4">
						<span class="dashicons dashicons-google" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Connect with Google', 'specflux-marketing-analytics-chat' ); ?>
					</button>
					<span class="smac-google-connect-status description" style="display: none; margin-left: 8px;"></span>
				</p>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Hosted Google sign-in is disabled on this site, so you need to configure your own Google OAuth client before connecting.', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>
			<?php endif; ?>

			<details class="smac-advanced-oauth">
				<summary><?php esc_html_e( 'Advanced: use your own Google Cloud OAuth client', 'specflux-marketing-analytics-chat' ); ?></summary>
				<p class="description">
					<?php if ( $has_oauth_credentials ) : ?>
						<?php esc_html_e( 'Your own OAuth client is configured and will be used for this connection.', 'specflux-marketing-analytics-chat' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'By default, sign-in goes through the Specflux Google OAuth client. If your organisation requires its own Google Cloud project, configure a client ID and secret and it will be used instead.', 'specflux-marketing-analytics-chat' ); ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-settings&tab=google-api' ) ); ?>"><?php esc_html_e( 'Google API settings', 'specflux-marketing-analytics-chat' ); ?></a>
				</p>
			</details>

		<?php else : ?>
			<!-- Connected State -->
			<div class="notice notice-success">
				<p><strong><?php esc_html_e( 'Connected!', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Your Google Analytics 4 account is connected and ready to use.', 'specflux-marketing-analytics-chat' ); ?></p>
			</div>

			<!-- Property Selection -->
			<h4><?php esc_html_e( 'Select GA4 Property', 'specflux-marketing-analytics-chat' ); ?></h4>
			<p><?php esc_html_e( 'Choose which Google Analytics 4 property to query for data:', 'specflux-marketing-analytics-chat' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="ga4_property_selector"><?php esc_html_e( 'GA4 Property', 'specflux-marketing-analytics-chat' ); ?></label>
					</th>
					<td>
						<div id="ga4-property-loading" style="display: none;">
							<span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
							<?php esc_html_e( 'Loading properties...', 'specflux-marketing-analytics-chat' ); ?>
						</div>

						<div id="ga4-property-error" class="notice notice-error inline" style="display: none; margin: 10px 0; padding: 10px;">
							<p></p>
						</div>

						<select id="ga4_property_selector" name="ga4_property_id" class="regular-text" style="display: none;">
							<option value=""><?php esc_html_e( 'Select a property...', 'specflux-marketing-analytics-chat' ); ?></option>
						</select>

						<?php if ( $current_property_id ) : ?>
							<p class="description success">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php
								printf(
									/* translators: %s: property ID */
									esc_html__( 'Currently selected property: %s', 'specflux-marketing-analytics-chat' ),
									'<strong>' . esc_html( $current_property_id ) . '</strong>'
								);
								?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No property selected yet. Please select a property from the list above.', 'specflux-marketing-analytics-chat' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<?php if ( $current_property_id ) : ?>
					<button type="button" id="load-ga4-properties" class="button button-secondary">
						<?php esc_html_e( 'Change Property', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				<?php else : ?>
					<button type="button" id="load-ga4-properties" class="button button-secondary" data-auto="1">
						<?php esc_html_e( 'Load Available Properties', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" id="save-ga4-property" class="button button-primary" style="display: none;">
					<?php esc_html_e( 'Save Selected Property', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</p>

			<hr style="margin: 30px 0;" />

			<form method="post" action="" style="margin-top: 20px;">
				<?php wp_nonce_field( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' ); ?>
				<input type="hidden" name="service" value="ga4" />

				<p class="submit">
					<button type="button" class="button button-secondary test-connection" data-platform="ga4">
						<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
					</button>
					<input type="submit" name="disconnect_oauth" class="button button-secondary" value="<?php esc_attr_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Google Analytics?', 'specflux-marketing-analytics-chat' ) ); ?>');" />
				</p>
			</form>
		<?php endif; ?>
	</div>
