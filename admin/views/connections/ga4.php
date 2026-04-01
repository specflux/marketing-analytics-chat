<?php
/**
 * Google Analytics 4 Connection View
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

$oauth_handler      = new OAuth_Handler();
$credential_manager = new Credential_Manager();

// Check if OAuth credentials are configured.
$has_oauth_creds = $oauth_handler->has_oauth_credentials();
$client_id       = $oauth_handler->get_client_id();

// Check if user is authenticated.
$ga4_credentials  = $credential_manager->get_credentials( 'ga4' );
$is_authenticated = ! empty( $ga4_credentials ) && isset( $ga4_credentials['access_token'] );

// Get saved property ID.
$saved_property_id = '';
if ( $is_authenticated && isset( $ga4_credentials['property_id'] ) ) {
	$saved_property_id = $ga4_credentials['property_id'];
}

// Get connection status.
$settings      = get_option( 'specflux_mac_settings', array() );
$platforms     = isset( $settings['platforms'] ) ? $settings['platforms'] : array();
$ga4_connected = isset( $platforms['ga4']['connected'] ) && $platforms['ga4']['connected'];
?>

<div class="connection-panel">
	<h3>
		<?php esc_html_e( 'Google Analytics 4 Configuration', 'specflux-marketing-analytics-chat' ); ?>
		<?php if ( $ga4_connected ) : ?>
			<span class="status-badge heading-connected">
				<span class="dashicons dashicons-yes-alt" ></span>
				<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
			</span>
		<?php else : ?>
			<span class="status-badge heading-disconnected">
				<span class="dashicons dashicons-warning" ></span>
				<?php esc_html_e( 'Not Connected', 'specflux-marketing-analytics-chat' ); ?>
			</span>
		<?php endif; ?>
	</h3>
	<p><?php esc_html_e( 'Connect to Google Analytics 4 to access traffic metrics, user behavior, and conversion data.', 'specflux-marketing-analytics-chat' ); ?></p>

	<?php if ( ! $has_oauth_creds ) : ?>
		<!-- Step 1: Configure OAuth Credentials -->
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Step 1: Configure Google OAuth Credentials', 'specflux-marketing-analytics-chat' ); ?></strong></p>
			<p><?php esc_html_e( 'Before you can connect to Google Analytics 4, you need to set up OAuth credentials from the Google Cloud Console.', 'specflux-marketing-analytics-chat' ); ?></p>
			<p style="color: #646970; font-style: italic;">
				<span class="dashicons dashicons-clock" style="font-size: 16px; margin-top: 2px;"></span>
				<?php esc_html_e( 'Estimated time: ~5 minutes', 'specflux-marketing-analytics-chat' ); ?>
			</p>
		</div>

		<?php
		$ga4_video_url = apply_filters( 'specflux_mac_setup_video_url', '', 'ga4' );
		if ( ! empty( $ga4_video_url ) ) :
			?>
		<details style="margin: 15px 0; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0 15px;">
			<summary style="padding: 12px 0; cursor: pointer; font-weight: 600;">
				<span class="dashicons dashicons-video-alt3" style="font-size: 16px; margin-top: 2px; color: #2271b1;"></span>
				<?php esc_html_e( 'Watch video walkthrough', 'specflux-marketing-analytics-chat' ); ?>
			</summary>
			<div style="padding: 0 0 15px 0;">
				<iframe width="100%" height="315" src="<?php echo esc_url( $ga4_video_url ); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy" style="max-width: 560px;"></iframe>
			</div>
		</details>
		<?php endif; ?>

		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Required Google APIs', 'specflux-marketing-analytics-chat' ); ?></strong></p>
			<p><?php esc_html_e( 'Make sure you have enabled these APIs in your Google Cloud project:', 'specflux-marketing-analytics-chat' ); ?></p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><strong><?php esc_html_e( 'Google Analytics Data API', 'specflux-marketing-analytics-chat' ); ?></strong> - <?php esc_html_e( 'For reading analytics data', 'specflux-marketing-analytics-chat' ); ?></li>
				<li><strong><?php esc_html_e( 'Google Analytics Admin API', 'specflux-marketing-analytics-chat' ); ?></strong> - <?php esc_html_e( 'For listing your GA4 properties', 'specflux-marketing-analytics-chat' ); ?></li>
			</ul>
			<p>
				<a href="<?php echo esc_url( 'https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com' ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'Enable Analytics Data API', 'specflux-marketing-analytics-chat' ); ?>
				</a>
				<a href="<?php echo esc_url( 'https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com' ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'Enable Analytics Admin API', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: link to setup guide */
					esc_html__( 'Need help? See the %s for detailed instructions.', 'specflux-marketing-analytics-chat' ),
					'<a href="' . esc_url( plugin_dir_url( dirname( __DIR__ ) ) . '../docs/GOOGLE_OAUTH_SETUP.md' ) . '" target="_blank">' . esc_html__( 'Google OAuth Setup Guide', 'specflux-marketing-analytics-chat' ) . '</a>'
				);
				?>
			</p>
		</div>

		<p style="color: #646970; font-size: 13px;">
			<span class="dashicons dashicons-info" style="font-size: 16px; margin-top: 2px;"></span>
			<?php esc_html_e( 'Already have your Client ID and Client Secret? Paste them directly below.', 'specflux-marketing-analytics-chat' ); ?>
		</p>

		<form method="post" action="" id="ga4-oauth-config-form">
			<?php wp_nonce_field( 'specflux_mac_oauth_config', 'oauth_config_nonce' ); ?>
			<input type="hidden" name="save_oauth_config" value="1" />

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="google_client_id"><?php esc_html_e( 'OAuth Client ID', 'specflux-marketing-analytics-chat' ); ?></label>
					</th>
					<td>
						<input type="text" id="google_client_id" name="google_client_id" class="large-text" value="" />
						<span id="google_client_id_status" style="display: none; margin-left: 5px;"></span>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to Google Cloud Console */
								esc_html__( 'Get your OAuth credentials from the %s', 'specflux-marketing-analytics-chat' ),
								'<a href="https://console.cloud.google.com/apis/credentials" target="_blank">' . esc_html__( 'Google Cloud Console', 'specflux-marketing-analytics-chat' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="google_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'specflux-marketing-analytics-chat' ); ?></label>
					</th>
					<td>
						<input type="password" id="google_client_secret" name="google_client_secret" class="large-text" value="" />
						<span id="google_client_secret_status" style="display: none; margin-left: 5px;"></span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Redirect URI', 'specflux-marketing-analytics-chat' ); ?>
					</th>
					<td>
						<code><?php echo esc_html( $oauth_handler->get_redirect_uri() ); ?></code>
						<p class="description"><?php esc_html_e( 'Add this URL as an authorized redirect URI in your Google Cloud Console OAuth configuration.', 'specflux-marketing-analytics-chat' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="save_oauth_config" class="button button-primary" value="<?php esc_attr_e( 'Save OAuth Credentials', 'specflux-marketing-analytics-chat' ); ?>" />
			</p>
		</form>

	<?php elseif ( ! $is_authenticated ) : ?>
		<!-- Step 2: Authenticate with Google -->
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Step 2: Connect to Google Analytics', 'specflux-marketing-analytics-chat' ); ?></strong></p>
			<p><?php esc_html_e( 'Click the button below to authorize access to your Google Analytics 4 properties.', 'specflux-marketing-analytics-chat' ); ?></p>
		</div>

		<!-- Test Connection -->
		<div style="margin: 15px 0;">
			<button type="button" class="button button-secondary test-connection" data-platform="ga4">
				<span class="dashicons dashicons-yes-alt" style="font-size: 16px; margin-top: 3px;"></span>
				<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
			</button>
			<span id="ga4-test-result" style="margin-left: 10px; display: none;"></span>
		</div>

		<div class="notice notice-warning" style="border-left-color: #00a0d2;">
			<p><strong><?php esc_html_e( 'Before you connect:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
			<p><?php esc_html_e( 'Ensure these APIs are enabled in your Google Cloud project, or you will see errors when selecting properties:', 'specflux-marketing-analytics-chat' ); ?></p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><strong><?php esc_html_e( 'Google Analytics Data API', 'specflux-marketing-analytics-chat' ); ?></strong></li>
				<li><strong><?php esc_html_e( 'Google Analytics Admin API', 'specflux-marketing-analytics-chat' ); ?></strong></li>
			</ul>
			<p>
				<a href="<?php echo esc_url( 'https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com' ); ?>" target="_blank" class="button button-secondary button-small">
					<?php esc_html_e( 'Enable Data API', 'specflux-marketing-analytics-chat' ); ?>
				</a>
				<a href="<?php echo esc_url( 'https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com' ); ?>" target="_blank" class="button button-secondary button-small">
					<?php esc_html_e( 'Enable Admin API', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</p>
		</div>

		<p>
			<strong><?php esc_html_e( 'OAuth Client ID:', 'specflux-marketing-analytics-chat' ); ?></strong>
			<?php echo esc_html( substr( $client_id, 0, 20 ) . '...' ); ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=ga4&reset_oauth=1' ), 'reset_oauth' ) ); ?>" style="margin-left: 10px;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to reset OAuth credentials? This will disconnect all Google services.', 'specflux-marketing-analytics-chat' ) ); ?>');">
				<?php esc_html_e( 'Reset OAuth Credentials', 'specflux-marketing-analytics-chat' ); ?>
			</a>
		</p>

		<p class="submit">
			<a href="<?php echo esc_url( $oauth_handler->get_auth_url( 'ga4' ) ); ?>" class="button button-primary button-large">
				<span class="dashicons dashicons-google" style="margin-top: 3px;"></span>
				<?php esc_html_e( 'Connect to Google Analytics 4', 'specflux-marketing-analytics-chat' ); ?>
			</a>
		</p>

	<?php else : ?>
		<!-- Step 3: Select Property -->
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Step 3: Select Your GA4 Property', 'specflux-marketing-analytics-chat' ); ?></strong></p>
			<p><?php esc_html_e( 'You are authenticated with Google. Select the GA4 property you want to use.', 'specflux-marketing-analytics-chat' ); ?></p>
		</div>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ga4_property"><?php esc_html_e( 'GA4 Property', 'specflux-marketing-analytics-chat' ); ?></label>
				</th>
				<td>
					<select id="ga4_property" name="ga4_property" class="regular-text">
						<option value=""><?php esc_html_e( 'Loading properties...', 'specflux-marketing-analytics-chat' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Select the Google Analytics 4 property you want to connect to.', 'specflux-marketing-analytics-chat' ); ?></p>
					<div id="ga4-property-error" class="description error" style="margin-top: 5px; display: none;"></div>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" id="save-ga4-property" class="button button-primary">
				<?php esc_html_e( 'Save Property Selection', 'specflux-marketing-analytics-chat' ); ?>
			</button>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=ga4&disconnect=1' ), 'disconnect_ga4' ) ); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Google Analytics 4?', 'specflux-marketing-analytics-chat' ) ); ?>');">
				<?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
