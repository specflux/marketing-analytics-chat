<?php
/**
 * Connections page panel: Microsoft Clarity
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
