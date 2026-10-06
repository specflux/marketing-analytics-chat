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

use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
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
	$credential_manager   = new Credential_Manager();
	$existing_credentials = $credential_manager->get_credentials( 'clarity' );

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

		if ( $credential_manager->save_credentials( 'clarity', $credentials ) ) {
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
$saved_clarity      = ( new Credential_Manager() )->get_credentials( 'clarity' );
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
		<?php foreach ( Platform_Registry::all() as $tab_slug => $tab_definition ) : ?>
			<?php if ( empty( $tab_definition['tab'] ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<a href="?page=specflux-mac-connections&tab=<?php echo esc_attr( $tab_slug ); ?>" class="nav-tab <?php echo esc_attr( $tab_slug === $active_tab ? 'nav-tab-active' : '' ); ?>">
				<?php echo esc_html( $tab_definition['label'] ); ?>
			</a>
		<?php endforeach; ?>
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
		$panel_definition = Platform_Registry::get( $active_tab );
		$panel_file       = __DIR__ . '/connections/' . $active_tab . '.php';

		if ( null !== $panel_definition && file_exists( $panel_file ) ) {
			include $panel_file;
		} else {
			if ( null !== $panel_definition && ! has_action( 'specflux_mac_render_connection_panel_' . $active_tab ) ) {
				include __DIR__ . '/connections/generic.php';
			} elseif ( null !== $panel_definition ) {
				/**
				 * Render the connection panel for a registered platform.
				 *
				 * The dynamic portion is the platform slug registered via specflux_mac_platforms.
				 *
				 * @param array $panel_definition The platform definition.
				 */
				do_action( 'specflux_mac_render_connection_panel_' . $active_tab, $panel_definition );
			}

			/**
			 * Allow pro add-on to render additional connection tab content.
			 *
			 * @param string $active_tab The currently active tab.
			 */
			do_action( 'specflux_mac_connections_tab_content', $active_tab );
		}
		?>
	</div>
</div>
