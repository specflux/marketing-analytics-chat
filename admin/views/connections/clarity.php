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

use Specflux_Marketing_Analytics\Admin\Form_Ui;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;

$clarity_state = Form_Ui::connection_state( $clarity_connected, $clarity_connected ? Connection_Tester::get_last_result( 'clarity' ) : null );
?>
	<section class="aui-card smac-connection-card" data-platform="clarity">
		<div class="aui-card-h">
			<span class="aui-tile" aria-hidden="true"><?php echo Ui_Kit::icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
			<div>
				<h2 class="aui-card-t"><?php esc_html_e( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ); ?></h2>
				<p class="aui-card-d"><?php esc_html_e( 'Connect to Microsoft Clarity to access session recordings, heatmaps, and user behavior insights.', 'specflux-marketing-analytics-chat' ); ?></p>
			</div>
			<?php echo Form_Ui::status_badge( $clarity_state['state'], $clarity_state['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::status_badge(). ?>
		</div>

		<form method="post" action="" class="clarity-connection-form">
			<?php wp_nonce_field( 'specflux_mac_save_clarity', 'clarity_nonce' ); ?>

			<div class="aui-card-b smac-fields">
				<?php if ( '' !== $clarity_state['message'] ) : ?>
					<?php echo Form_Ui::notice( 'error', $clarity_state['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
				<?php endif; ?>

				<div class="aui-field">
					<label class="aui-label" for="clarity_api_token"><?php esc_html_e( 'API Token', 'specflux-marketing-analytics-chat' ); ?></label>
					<input
						type="text"
						id="clarity_api_token"
						name="clarity_api_token"
						class="aui-input smac-secret"
						autocomplete="off"
						data-1p-ignore
						data-lpignore="true"
						data-bwignore
						data-form-type="other"
						spellcheck="false"
						autocapitalize="off"
						value="<?php echo esc_attr( $clarity_token_display ); ?>"
						placeholder="<?php echo $clarity_has_token ? esc_attr__( 'Token saved (enter new token to update)', 'specflux-marketing-analytics-chat' ) : esc_attr__( 'Enter your API token', 'specflux-marketing-analytics-chat' ); ?>"
						aria-describedby="clarity_api_token_help"
						<?php
						if ( $clarity_has_token ) {
							echo 'readonly onfocus="this.removeAttribute(\'readonly\'); this.value=\'\';"';
						}
						?>
					/>
					<?php if ( $clarity_has_token ) : ?>
						<p class="aui-help" id="clarity_api_token_help">
							<?php esc_html_e( 'API token is securely stored and encrypted. Leave blank to keep current token, or enter a new one to update.', 'specflux-marketing-analytics-chat' ); ?>
						</p>
					<?php else : ?>
						<p class="aui-help" id="clarity_api_token_help">
							<?php
							printf(
								/* translators: %s: link to Clarity documentation */
								esc_html__( 'Get your API token from the %s.', 'specflux-marketing-analytics-chat' ),
								'<a href="https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Clarity Data Export API', 'specflux-marketing-analytics-chat' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="clarity_project_id"><?php esc_html_e( 'Project ID', 'specflux-marketing-analytics-chat' ); ?></label>
					<input type="text" id="clarity_project_id" name="clarity_project_id" class="aui-input" value="<?php echo esc_attr( $clarity_project_id ); ?>" aria-describedby="clarity_project_id_help" />
					<p class="aui-help" id="clarity_project_id_help"><?php esc_html_e( 'Your Clarity project ID (for reference only - the API token identifies your project)', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>
			</div>

			<div class="aui-card-f">
				<button type="button" class="aui-btn aui-btn--secondary test-connection" data-platform="clarity">
					<?php esc_html_e( 'Test Connection', 'specflux-marketing-analytics-chat' ); ?>
				</button>
				<button type="submit" name="save_clarity" value="1" class="aui-btn aui-btn--primary aui-end">
					<?php esc_html_e( 'Save Credentials', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>
		</form>
	</section>
