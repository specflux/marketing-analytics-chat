<?php
/**
 * Connections Page Template
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Credentials\Encryption;
use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// This template is reached through Admin::render_connections_page(), which
// already gates on the plugin capability. The check is repeated here so that the
// authorisation guard sits directly above the request handling it protects.
if ( ! current_user_can( Permission_Manager::get_capability() ) ) {
	wp_die( esc_html__( 'You do not have permission to manage analytics connections.', 'specflux-marketing-analytics-chat' ) );
}

// phpcs:ignore WordPress.Security.NonceVerification.Recieved -- Read-only tab selection; changes no state.
$active_tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'clarity';
$success_message = '';
$error_message   = '';

// Initialize OAuth handler and Credential Manager.
$oauth_handler      = new OAuth_Handler();
$credential_manager = new Credential_Manager();

// Handle the Google OAuth redirect back to this page.
//
// A WordPress nonce is not applicable to this branch: the request is issued by
// Google's authorisation server rather than by a form this plugin rendered, so
// there is no nonce to carry through the round trip. The OAuth 2.0 `state`
// parameter is the CSRF defence here — OAuth_Handler generates it with
// wp_generate_password() and stores it before redirecting, then compares the
// returned value using hash_equals() in handle_callback(). A mismatched or
// absent state aborts the exchange. The capability check above restricts this
// branch to users allowed to manage connections.
// phpcs:ignore WordPress.Security.NonceVerification.Recieved -- OAuth callback; CSRF is enforced by the `state` parameter validated in OAuth_Handler::handle_callback().
if ( isset( $_GET['oauth_callback'], $_GET['code'], $_GET['state'] ) ) {
	$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
	$state         = sanitize_text_field( wp_unslash( $_GET['state'] ) );
	$callback_type = sanitize_text_field( wp_unslash( $_GET['oauth_callback'] ) );

	$result = $oauth_handler->handle_callback( $code, $state );

	if ( $result['success'] ) {
		$success_message = $result['message'];
		$active_tab      = $result['service']; // Switch to the service tab.
	} else {
		$error_message = $result['message'];
	}
} elseif ( isset( $_GET['oauth_callback'], $_GET['handoff'], $_GET['nonce'], $_GET['service'] ) ) {
	// Hosted flow: the Specflux proxy redirects back with a single-use handoff
	// code; CSRF is enforced by the nonce stored before redirecting to Google.
	// phpcs:disable WordPress.Security.NonceVerification.Recieved
	$handoff = sanitize_text_field( wp_unslash( $_GET['handoff'] ) );
	$nonce   = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
	$service = sanitize_key( wp_unslash( $_GET['service'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recieved

	$result = $oauth_handler->handle_hosted_callback( $handoff, $nonce, $service );

	if ( $result['success'] ) {
		$success_message = $result['message'];
		$active_tab      = $result['service'];
	} else {
		$error_message = $result['message'];
		if ( in_array( $service, array( 'ga4', 'gsc' ), true ) ) {
			$active_tab = $service;
		}
	}
} elseif ( isset( $_GET['oauth_callback'], $_GET['smac_oauth_error'] ) ) {
	// Hosted flow: Google or the proxy reported a failure before any token was issued.
	// phpcs:disable WordPress.Security.NonceVerification.Recieved
	$error_message = $oauth_handler->describe_hosted_error( sanitize_key( wp_unslash( $_GET['smac_oauth_error'] ) ) );
	$service       = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recieved
	if ( in_array( $service, array( 'ga4', 'gsc' ), true ) ) {
		$active_tab = $service;
	}
}

// Note: Google OAuth credentials are now managed in Settings > Google API tab.

// Handle OAuth disconnect.
if ( isset( $_POST['disconnect_oauth'] ) && check_admin_referer( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' ) ) {
	$service = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';

	if ( in_array( $service, array( 'ga4', 'gsc' ), true ) ) {
		if ( $oauth_handler->revoke_access( $service ) ) {
			$success_message = sprintf(
				/* translators: %s: service name */
				__( 'Successfully disconnected from %s.', 'specflux-marketing-analytics-chat' ),
				'ga4' === $service ? 'Google Analytics 4' : 'Google Search Console'
			);
			$active_tab = $service;
		} else {
			$error_message = __( 'Failed to disconnect.', 'specflux-marketing-analytics-chat' );
		}
	}
}

// Handle form submission for saving Clarity credentials.
if ( isset( $_POST['save_clarity'] ) && check_admin_referer( 'specflux_mac_save_clarity', 'clarity_nonce' ) ) {
	$api_token  = isset( $_POST['clarity_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['clarity_api_token'] ) ) : '';
	$project_id = isset( $_POST['clarity_project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['clarity_project_id'] ) ) : '';

	// Get existing credentials.
	$existing_credentials = Encryption::get_credentials( 'clarity' );

	// If API token is empty or is the masked display value, keep the existing token.
	if ( empty( $api_token ) || ( $existing_credentials && strpos( $api_token, '...' ) !== false ) ) {
		if ( $existing_credentials && isset( $existing_credentials['api_token'] ) ) {
			$api_token = $existing_credentials['api_token'];
		}
	}

	if ( ! empty( $api_token ) && ! empty( $project_id ) ) {
		$credentials = array(
			'api_token'  => $api_token,
			'project_id' => $project_id,
		);

		if ( Encryption::save_credentials( 'clarity', $credentials ) ) {
			// Update platform status to connected.
			$settings = get_option( 'specflux_mac_settings', array() );
			if ( ! isset( $settings['platforms'] ) ) {
				$settings['platforms'] = array();
			}
			if ( ! isset( $settings['platforms']['clarity'] ) ) {
				$settings['platforms']['clarity'] = array();
			}
			$settings['platforms']['clarity']['connected'] = true;
			$settings['platforms']['clarity']['enabled']   = true;
			update_option( 'specflux_mac_settings', $settings );

			$success_message = __( 'Clarity credentials saved successfully!', 'specflux-marketing-analytics-chat' );
		} else {
			$error_message = __( 'Failed to save Clarity credentials.', 'specflux-marketing-analytics-chat' );
		}
	} else {
		$error_message = __( 'Please fill in all required fields.', 'specflux-marketing-analytics-chat' );
	}
}

// Get saved credentials for display.
$saved_clarity      = Encryption::get_credentials( 'clarity' );
$clarity_project_id = $saved_clarity && isset( $saved_clarity['project_id'] ) ? $saved_clarity['project_id'] : '';
$clarity_has_token  = $saved_clarity && isset( $saved_clarity['api_token'] ) && ! empty( $saved_clarity['api_token'] );
// Show masked token for display (first 10 chars + ... + last 10 chars).
$clarity_token_display = '';
if ( $clarity_has_token ) {
	$token = $saved_clarity['api_token'];
	if ( strlen( $token ) > 30 ) {
		$clarity_token_display = substr( $token, 0, 10 ) . '...' . substr( $token, -10 );
	} else {
		$clarity_token_display = str_repeat( '•', strlen( $token ) );
	}
}

// Get platform connection status - check actual credentials instead of manual flag.
$settings          = get_option( 'specflux_mac_settings', array() );
$platforms         = isset( $settings['platforms'] ) ? $settings['platforms'] : array();
$clarity_connected = $credential_manager->has_credentials( 'clarity' );
?>

<div class="wrap specflux-mac-connections">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $success_message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $success_message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $error_message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $error_message ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Configure API credentials for your marketing analytics platforms.', 'specflux-marketing-analytics-chat' ); ?>
	</p>

	<h2 class="nav-tab-wrapper">
		<a href="?page=specflux-mac-connections&tab=clarity" class="nav-tab <?php echo esc_attr( 'clarity' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<a href="?page=specflux-mac-connections&tab=ga4" class="nav-tab <?php echo esc_attr( 'ga4' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Google Analytics 4', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<a href="?page=specflux-mac-connections&tab=gsc" class="nav-tab <?php echo esc_attr( 'gsc' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Google Search Console', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<?php
		/**
		 * Allow pro add-on to add additional connection tabs.
		 *
		 * @param string $active_tab The currently active tab.
		 */
		do_action( 'specflux_mac_connections_tabs', $active_tab );
		?>
	</h2>

	<div class="tab-content">
		<?php
		switch ( $active_tab ) {
			case 'clarity':
				?>
				<div class="connection-panel">
					<h3>
						<?php esc_html_e( 'Microsoft Clarity Configuration', 'specflux-marketing-analytics-chat' ); ?>
						<?php if ( $clarity_connected ) : ?>
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
					<p><?php esc_html_e( 'Connect to Microsoft Clarity to access session recordings, heatmaps, and user behavior insights.', 'specflux-marketing-analytics-chat' ); ?></p>

					<form method="post" action="" class="clarity-connection-form">
						<?php wp_nonce_field( 'specflux_mac_save_clarity', 'clarity_nonce' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="clarity_api_token"><?php esc_html_e( 'API Token', 'specflux-marketing-analytics-chat' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="clarity_api_token"
										name="clarity_api_token"
										class="regular-text"
										value="<?php echo esc_attr( $clarity_token_display ); ?>"
										placeholder="<?php echo $clarity_has_token ? esc_attr__( 'Token saved (enter new token to update)', 'specflux-marketing-analytics-chat' ) : esc_attr__( 'Enter your API token', 'specflux-marketing-analytics-chat' ); ?>"
											<?php
											if ( $clarity_has_token ) {
												echo 'readonly onfocus="this.removeAttribute(\'readonly\'); this.value=\'\'; this.type=\'password\';"';
											} else {
												echo 'type="password"';
											}
											?>
									/>
									<?php if ( $clarity_has_token ) : ?>
										<p class="description success">
											<span class="dashicons dashicons-yes-alt"></span>
											<?php esc_html_e( 'API token is securely stored and encrypted. Leave blank to keep current token, or enter a new one to update.', 'specflux-marketing-analytics-chat' ); ?>
										</p>
									<?php else : ?>
										<p class="description">
											<?php
											printf(
												/* translators: %s: link to Clarity documentation */
												esc_html__( 'Get your API token from the %s.', 'specflux-marketing-analytics-chat' ),
												'<a href="https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api" target="_blank">' . esc_html__( 'Clarity Data Export API', 'specflux-marketing-analytics-chat' ) . '</a>'
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="clarity_project_id"><?php esc_html_e( 'Project ID', 'specflux-marketing-analytics-chat' ); ?></label>
								</th>
								<td>
									<input type="text" id="clarity_project_id" name="clarity_project_id" class="regular-text" value="<?php echo esc_attr( $clarity_project_id ); ?>" />
									<p class="description"><?php esc_html_e( 'Your Clarity project ID (for reference only - the API token identifies your project)', 'specflux-marketing-analytics-chat' ); ?></p>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="button" class="button button-secondary test-connection" data-platform="clarity">
								<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
							</button>
							<input type="submit" name="save_clarity" class="button button-primary" value="<?php esc_attr_e( 'Save Credentials', 'specflux-marketing-analytics-chat' ); ?>" />
						</p>
					</form>
				</div>
				<?php
				break;

			case 'ga4':
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
							<button type="button" id="load-ga4-properties" class="button button-secondary">
								<?php esc_html_e( 'Load Available Properties', 'specflux-marketing-analytics-chat' ); ?>
							</button>
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
				<?php
				break;

			case 'gsc':
				$has_oauth_credentials = $oauth_handler->has_oauth_credentials();
				$can_connect_google    = $oauth_handler->can_connect_google();
				$is_connected          = $credential_manager->has_credentials( 'gsc' );
				$current_site_url      = get_option( 'specflux_mac_gsc_site_url' );
				?>
				<div class="connection-panel">
					<h3>
						<?php esc_html_e( 'Google Search Console Configuration', 'specflux-marketing-analytics-chat' ); ?>
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
					<p><?php esc_html_e( 'Connect to Google Search Console to access search performance data, indexing status, and query analytics.', 'specflux-marketing-analytics-chat' ); ?></p>

					<?php if ( ! $is_connected ) : ?>
						<?php if ( $can_connect_google ) : ?>
							<h4><?php esc_html_e( 'Connect your Google Search Console account', 'specflux-marketing-analytics-chat' ); ?></h4>
							<p>
								<?php esc_html_e( 'Sign in with Google and grant read-only access to your Google Search Console data.', 'specflux-marketing-analytics-chat' ); ?>
								<?php if ( ! $has_oauth_credentials ) : ?>
									<?php esc_html_e( 'No Google Cloud project or API keys are needed.', 'specflux-marketing-analytics-chat' ); ?>
								<?php endif; ?>
							</p>
							<p class="submit">
								<button type="button" class="button button-primary button-large smac-google-connect" data-service="gsc">
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
							<p><strong><?php esc_html_e( 'Connected!', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Your Google Search Console account is connected and ready to use.', 'specflux-marketing-analytics-chat' ); ?></p>
						</div>

						<!-- Site Selection -->
						<h4><?php esc_html_e( 'Select Search Console Property', 'specflux-marketing-analytics-chat' ); ?></h4>
						<p><?php esc_html_e( 'Choose which Search Console property to query for data:', 'specflux-marketing-analytics-chat' ); ?></p>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="gsc_site_selector"><?php esc_html_e( 'Site URL', 'specflux-marketing-analytics-chat' ); ?></label>
								</th>
								<td>
									<div id="gsc-site-loading" style="display: none;">
										<span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
										<?php esc_html_e( 'Loading sites...', 'specflux-marketing-analytics-chat' ); ?>
									</div>

									<div id="gsc-site-error" class="notice notice-error inline" style="display: none; margin: 10px 0; padding: 10px;">
										<p></p>
									</div>

									<select id="gsc_site_selector" name="gsc_site_url" class="regular-text" style="display: none;">
										<option value=""><?php esc_html_e( 'Select a site...', 'specflux-marketing-analytics-chat' ); ?></option>
									</select>

									<?php if ( $current_site_url ) : ?>
										<p class="description success">
											<span class="dashicons dashicons-yes-alt"></span>
											<?php
											printf(
												/* translators: %s: site URL */
												esc_html__( 'Currently selected site: %s', 'specflux-marketing-analytics-chat' ),
												'<strong>' . esc_html( $current_site_url ) . '</strong>'
											);
											?>
										</p>
									<?php else : ?>
										<p class="description">
											<?php esc_html_e( 'No site selected yet. Please select a site from the list above.', 'specflux-marketing-analytics-chat' ); ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="button" id="load-gsc-sites" class="button button-secondary">
								<?php esc_html_e( 'Load Available Sites', 'specflux-marketing-analytics-chat' ); ?>
							</button>
							<button type="button" id="save-gsc-site" class="button button-primary" style="display: none;">
								<?php esc_html_e( 'Save Selected Site', 'specflux-marketing-analytics-chat' ); ?>
							</button>
						</p>

						<hr style="margin: 30px 0;" />

						<form method="post" action="" style="margin-top: 20px;">
							<?php wp_nonce_field( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' ); ?>
							<input type="hidden" name="service" value="gsc" />

							<p class="submit">
								<button type="button" class="button button-secondary test-connection" data-platform="gsc">
									<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
								</button>
								<input type="submit" name="disconnect_oauth" class="button button-secondary" value="<?php esc_attr_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Google Search Console?', 'specflux-marketing-analytics-chat' ) ); ?>');" />
							</p>
						</form>
					<?php endif; ?>
				</div>
				<?php
				break;

			default:
				/**
				 * Allow pro add-on to render additional connection tab content.
				 *
				 * @param string $active_tab The currently active tab.
				 */
				do_action( 'specflux_mac_connections_tab_content', $active_tab );
				break;
		}
		?>
	</div>
</div>
