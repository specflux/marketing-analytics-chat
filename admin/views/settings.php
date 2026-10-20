<?php
	/**
	 * Settings Page Template
	 *
	 * @package Specflux_Marketing_Analytics
	 */

	// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	use Specflux_Marketing_Analytics\Chat\MCP_Client;
	use Specflux_Marketing_Analytics\Chat\Tool_Policy;
	use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
	use Specflux_Marketing_Analytics\Attribution\Source_Capture;
	use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Preview;
	use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Scheduler;
	use Specflux_Marketing_Analytics\Utils\Permission_Manager;
	use Specflux_Marketing_Analytics\Admin\Form_Ui;
	use Specflux_Marketing_Analytics\Admin\Ui_Kit;

	$active_tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
	$success_message = '';
	$error_message   = '';

	// Initialize OAuth handler.
	$oauth_handler = new OAuth_Handler();

	// Handle Google OAuth credential setup.
if ( isset( $_POST['save_google_oauth'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'specflux_mac_save_google_oauth', 'google_oauth_nonce' ) ) {
	$client_id     = isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '';
	$client_secret = isset( $_POST['google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_secret'] ) ) : '';

	// Client ID is always required.
	if ( empty( $client_id ) ) {
		$error_message = __( 'Client ID is required.', 'specflux-marketing-analytics-chat' );
	} elseif ( empty( $client_secret ) && ! $oauth_handler->has_oauth_credentials() ) {
		// Client Secret is required for first-time setup.
		$error_message = __( 'Client Secret is required for initial setup.', 'specflux-marketing-analytics-chat' );
	} else {
		// Save credentials (secret can be empty to keep existing).
		if ( $oauth_handler->set_oauth_credentials( $client_id, $client_secret ) ) {
			$success_message = __( 'Google OAuth credentials saved successfully!', 'specflux-marketing-analytics-chat' );
		} else {
			$error_message = __( 'Failed to save Google OAuth credentials.', 'specflux-marketing-analytics-chat' );
		}
	}
	$active_tab = 'google-api';
}

if ( isset( $_POST['remove_google_oauth'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'specflux_mac_remove_google_oauth', 'remove_google_oauth_nonce' ) ) {
	$disconnected    = $oauth_handler->clear_oauth_credentials();
	$success_message = __( 'Your Google OAuth client was removed. Connections now use Specflux sign-in.', 'specflux-marketing-analytics-chat' );
	if ( ! empty( $disconnected ) ) {
		$success_message .= ' ' . __( 'Google connections made with that client were disconnected; reconnect them from the Connections page.', 'specflux-marketing-analytics-chat' );
	}
	$active_tab = 'google-api';
}

	// Handle general settings form submission.
if ( isset( $_POST['save_settings'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'specflux_mac_save_settings', 'settings_nonce' ) ) {
	$existing_settings = get_option( 'specflux_mac_settings', array() );
	$new_settings      = array();

	// AI Chat Settings. Chat is served by the WordPress core AI Client, so the
	// model provider and its credentials live at the site level under
	// Settings > Connectors. This plugin stores no provider choice or API key.
	$new_settings['ai_temperature'] = floatval( $_POST['ai_temperature'] ?? 0.7 );
	$new_settings['ai_max_tokens']  = absint( $_POST['ai_max_tokens'] ?? 4096 );

	// Tool Categories.
	$enabled_categories                      = isset( $_POST['enabled_tool_categories'] ) && is_array( $_POST['enabled_tool_categories'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_tool_categories'] ) )
		: array( 'all' );
	$new_settings['enabled_tool_categories'] = $enabled_categories;

	// Other plugins' tools in the chat. Only saved when that section was on the
	// submitted form, so the other tabs never reset it.
	if ( isset( $_POST['chat_providers_submitted'] ) ) {
		$ability_list = ( new MCP_Client() )->list_tools();
		$detected     = is_array( $ability_list ) ? array_keys( Tool_Policy::providers( $ability_list ) ) : array();
		$checked      = isset( $_POST['chat_providers_enabled'] ) && is_array( $_POST['chat_providers_enabled'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['chat_providers_enabled'] ) )
			: array();

		$new_settings['chat_providers'] = Tool_Policy::sanitize_provider_choices( $checked, $detected, $existing_settings['chat_providers'] ?? array() );
	}

	// Cache Settings.
	$new_settings['cache_ttl_clarity'] = absint( $_POST['cache_ttl_clarity'] ?? 60 ) * 60;
	$new_settings['cache_ttl_ga4']     = absint( $_POST['cache_ttl_ga4'] ?? 30 ) * 60;
	$new_settings['cache_ttl_gsc']     = absint( $_POST['cache_ttl_gsc'] ?? 1440 ) * 60;

	// Debug Settings.
	$new_settings['debug_mode'] = ! empty( $_POST['debug_mode'] ) ? 1 : 0;

	// Source capture switch. Only saved when its checkbox was on the submitted form.
	if ( isset( $_POST['source_capture_submitted'] ) ) {
		update_option( Source_Capture::OPTION_ENABLED, ! empty( $_POST['source_capture_enabled'] ) ? 1 : 0 );
	}

	// Preserve keys not present in the form (e.g. platforms, version).
	$new_settings = array_merge( $existing_settings, $new_settings );

	update_option( 'specflux_mac_settings', $new_settings );

	// Keep the standalone debug-mode option (read by Logger) in sync with the
	// form so toggling debug actually takes effect.
	update_option( 'specflux_mac_debug_mode', $new_settings['debug_mode'] ? 1 : 0 );

	$success_message = __( 'Settings saved successfully.', 'specflux-marketing-analytics-chat' );
}

	// Handle email summary settings submission.
if ( isset( $_POST['save_email_settings'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'specflux_mac_save_email_settings', 'email_settings_nonce' ) ) {
	$raw_recipients = isset( $_POST['weekly_summary_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['weekly_summary_recipients'] ) ) : '';
	$parsed         = Weekly_Summary_Scheduler::parse_recipients( $raw_recipients );

	if ( ! empty( $parsed['invalid'] ) ) {
		$error_message = sprintf(
			/* translators: %s: comma-separated list of invalid email addresses */
			__( 'These email addresses are not valid, so nothing was saved: %s', 'specflux-marketing-analytics-chat' ),
			implode( ', ', $parsed['invalid'] )
		);
	} else {
		update_option( Weekly_Summary_Scheduler::OPTION_ENABLED, ! empty( $_POST['weekly_summary_enabled'] ) ? 1 : 0 );
		update_option( Weekly_Summary_Scheduler::OPTION_RECIPIENTS, implode( ', ', $parsed['valid'] ) );
		( new Weekly_Summary_Scheduler() )->sync_schedule();

		$success_message = __( 'Email settings saved.', 'specflux-marketing-analytics-chat' );
	}
	$active_tab = 'email';
}


	$settings              = get_option( 'specflux_mac_settings', array() );
	$has_oauth_credentials = $oauth_handler->has_oauth_credentials();

	// Core tabs: same slugs and URLs as before. Add-ons append theirs through the hook.
	$specflux_mac_nav_items = array(
		array(
			'slug'  => 'general',
			'label' => __( 'General', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=general',
		),
		array(
			'slug'  => 'google-api',
			'label' => __( 'Google API', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=google-api',
			'badge' => $has_oauth_credentials ? Ui_Kit::icon( 'check', 'smac-tab-ok' ) . '<span class="aui-sr"> ' . esc_html__( '(configured)', 'specflux-marketing-analytics-chat' ) . '</span>' : '',
		),
		array(
			'slug'  => 'cache',
			'label' => __( 'Cache', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=cache',
		),
		array(
			'slug'  => 'advanced',
			'label' => __( 'Advanced', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=advanced',
		),
		array(
			'slug'  => 'access-control',
			'label' => __( 'Access Control', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=access-control',
		),
		array(
			'slug'  => 'email',
			'label' => __( 'Email', 'specflux-marketing-analytics-chat' ),
			'url'   => '?page=specflux-mac-settings&tab=email',
		),
	);
	?>

<div class="aui" data-brand="specflux">
<?php Ui_Kit::render_header( 'specflux-mac-settings' ); ?>

<div class="aui-body specflux-mac-settings">
<div class="aui-layout aui-layout--single"><div class="aui-stack">

	<div class="smac-notices" data-smac-notices aria-live="polite">
		<?php if ( $success_message ) : ?>
			<?php echo Form_Ui::notice( 'success', $success_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
		<?php endif; ?>
		<?php if ( $error_message ) : ?>
			<?php echo Form_Ui::notice( 'error', $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
		<?php endif; ?>
	</div>

	<?php
	// Prints the core tabs, then fires `specflux_mac_settings_tabs` with the active tab.
	Form_Ui::sub_nav( $specflux_mac_nav_items, $active_tab, 'specflux_mac_settings_tabs', __( 'Settings', 'specflux-marketing-analytics-chat' ) );
	?>

	<div class="tab-content smac-tab-content">
		<?php
		switch ( $active_tab ) {
			case 'general':
				?>
				<form method="post" action="" class="smac-form" data-aui-form>
					<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<div class="aui-stack">
						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'AI Chat Settings', 'specflux-marketing-analytics-chat' ); ?></h2>
									<p class="aui-card-d"><?php esc_html_e( 'Chat responses are generated through the AI Client built into WordPress. Choose your AI provider and enter its credentials once under Settings > Connectors; this plugin stores no provider keys of its own.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<div class="aui-grid-2">
									<div class="aui-field">
										<label class="aui-label" for="ai_temperature"><?php esc_html_e( 'Temperature', 'specflux-marketing-analytics-chat' ); ?></label>
										<input type="number" class="aui-input" id="ai_temperature" name="ai_temperature"
											value="<?php echo esc_attr( isset( $settings['ai_temperature'] ) ? $settings['ai_temperature'] : '0.7' ); ?>"
											min="0" max="1" step="0.1" aria-describedby="ai_temperature_help" />
										<p class="aui-help" id="ai_temperature_help"><?php esc_html_e( 'Controls randomness (0 = focused, 1 = creative). Default: 0.7', 'specflux-marketing-analytics-chat' ); ?></p>
									</div>
									<div class="aui-field">
										<label class="aui-label" for="ai_max_tokens"><?php esc_html_e( 'Max Tokens', 'specflux-marketing-analytics-chat' ); ?></label>
										<input type="number" class="aui-input" id="ai_max_tokens" name="ai_max_tokens"
											value="<?php echo esc_attr( isset( $settings['ai_max_tokens'] ) ? $settings['ai_max_tokens'] : '4096' ); ?>"
											min="256" max="8192" step="256" aria-describedby="ai_max_tokens_help" />
										<p class="aui-help" id="ai_max_tokens_help"><?php esc_html_e( 'Maximum response length. Default: 4096', 'specflux-marketing-analytics-chat' ); ?></p>
									</div>
								</div>
							</div>
						</section>

						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'AI Chat Tool Selection', 'specflux-marketing-analytics-chat' ); ?></h2>
									<p class="aui-card-d">
										<?php
										esc_html_e(
											'Select which tool categories to send to the AI. Fewer tools = lower token costs per request. The AI will only see and use tools from enabled categories. Token usage with tool count is displayed below each AI response.',
											'specflux-marketing-analytics-chat'
										);
										?>
									</p>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<?php
								$enabled_categories = $settings['enabled_tool_categories'] ?? array( 'all' );
								$is_all_enabled     = in_array( 'all', $enabled_categories, true );
								?>
								<fieldset class="smac-fieldset">
									<legend class="aui-label"><?php esc_html_e( 'Enabled Tool Categories', 'specflux-marketing-analytics-chat' ); ?></legend>
									<?php
									Form_Ui::render_toggle(
										array(
											'id'          => 'tool_category_all',
											'name'        => 'enabled_tool_categories[]',
											'value'       => 'all',
											'checked'     => $is_all_enabled,
											'label'       => __( 'All Tools', 'specflux-marketing-analytics-chat' ),
											'description' => __( '(Recommended - AI can use any available tool)', 'specflux-marketing-analytics-chat' ),
										)
									);
									?>
									<div class="smac-checklist">
										<label class="smac-check">
											<input type="checkbox" name="enabled_tool_categories[]" value="clarity"
											<?php checked( in_array( 'clarity', $enabled_categories, true ) || $is_all_enabled ); ?>
											<?php disabled( $is_all_enabled ); ?> />
											<span><?php esc_html_e( 'Microsoft Clarity Tools', 'specflux-marketing-analytics-chat' ); ?></span>
										</label>
										<label class="smac-check">
											<input type="checkbox" name="enabled_tool_categories[]" value="ga4"
											<?php checked( in_array( 'ga4', $enabled_categories, true ) || $is_all_enabled ); ?>
											<?php disabled( $is_all_enabled ); ?> />
											<span><?php esc_html_e( 'Google Analytics 4 Tools', 'specflux-marketing-analytics-chat' ); ?></span>
										</label>
										<label class="smac-check">
											<input type="checkbox" name="enabled_tool_categories[]" value="gsc"
											<?php checked( in_array( 'gsc', $enabled_categories, true ) || $is_all_enabled ); ?>
											<?php disabled( $is_all_enabled ); ?> />
											<span><?php esc_html_e( 'Google Search Console Tools', 'specflux-marketing-analytics-chat' ); ?></span>
										</label>
									</div>
								</fieldset>
							</div>
						</section>

						<section class="aui-card">
							<?php
							$chat_ability_list = ( new MCP_Client() )->list_tools();
							$chat_providers    = is_array( $chat_ability_list ) ? Tool_Policy::providers( $chat_ability_list ) : array();
							unset( $chat_providers[ Tool_Policy::OWN_PROVIDER ], $chat_providers[ Tool_Policy::CORE_PROVIDER ] );
							?>
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'Tools from other plugins', 'specflux-marketing-analytics-chat' ); ?></h2>
									<p class="aui-card-d">
										<?php esc_html_e( 'Other plugins, such as SEO plugins, can offer tools to the chat. Switch a plugin off to stop the chat from using its tools.', 'specflux-marketing-analytics-chat' ); ?>
										<?php esc_html_e( 'Tools that change your site need Specflux Marketing Analytics Chat Pro and always ask before running.', 'specflux-marketing-analytics-chat' ); ?>
									</p>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<input type="hidden" name="chat_providers_submitted" value="1" />
								<?php if ( empty( $chat_providers ) ) : ?>
									<p class="smac-copy"><?php esc_html_e( 'No other plugin offers tools to the chat on this site.', 'specflux-marketing-analytics-chat' ); ?></p>
								<?php else : ?>
									<fieldset class="smac-fieldset">
										<legend class="aui-sr"><?php esc_html_e( 'Tools from other plugins', 'specflux-marketing-analytics-chat' ); ?></legend>
										<?php foreach ( $chat_providers as $provider_slug => $provider ) : ?>
											<?php
											Form_Ui::render_toggle(
												array(
													'id'      => 'chat_provider_' . $provider_slug,
													'name'    => 'chat_providers_enabled[]',
													'value'   => $provider_slug,
													'checked' => Tool_Policy::provider_enabled( $provider_slug ),
													'label'   => $provider['label'],
													'description' => sprintf(
														/* translators: 1: number of read-only tools, 2: number of tools that change the site */
														__( '%1$d read, %2$d write', 'specflux-marketing-analytics-chat' ),
														$provider['read'],
														$provider['write']
													),
												)
											);
											?>
										<?php endforeach; ?>
									</fieldset>
								<?php endif; ?>
							</div>
						</section>
					</div>

					<?php Form_Ui::savebar( 'save_settings', __( 'Save Settings', 'specflux-marketing-analytics-chat' ) ); ?>
				</form>
				<?php
				break;

			case 'google-api':
				?>
				<div class="wizard-container">
				<section class="aui-card">
					<div class="aui-card-h">
						<div>
							<h2 class="aui-card-t"><?php esc_html_e( 'Google Cloud Console Setup', 'specflux-marketing-analytics-chat' ); ?></h2>
							<p class="aui-card-d"><?php esc_html_e( 'Follow this step-by-step wizard to set up Google OAuth credentials for Analytics and Search Console.', 'specflux-marketing-analytics-chat' ); ?></p>
						</div>
						<?php if ( $has_oauth_credentials ) : ?>
							<?php echo Form_Ui::status_badge( 'connected', __( 'Credentials Configured', 'specflux-marketing-analytics-chat' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::status_badge(). ?>
						<?php endif; ?>
					</div>

					<div class="aui-card-b smac-fields">
						<?php if ( ! $has_oauth_credentials && $oauth_handler->uses_hosted_auth() ) : ?>
							<div class="aui-notice aui-notice--info" role="status">
								<?php echo Ui_Kit::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
								<div>
									<strong><?php esc_html_e( 'Optional.', 'specflux-marketing-analytics-chat' ); ?></strong>
									<?php esc_html_e( 'You can connect Google Analytics and Search Console with one click from the Connections page, without a Google Cloud project. Only complete this wizard if your organisation requires its own OAuth client.', 'specflux-marketing-analytics-chat' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-connections&tab=ga4' ) ); ?>"><?php esc_html_e( 'Go to Connections', 'specflux-marketing-analytics-chat' ); ?></a>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $has_oauth_credentials ) : ?>
							<div class="aui-notice aui-notice--success" role="status">
								<?php echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
								<div>
									<strong><?php esc_html_e( 'Credentials Configured', 'specflux-marketing-analytics-chat' ); ?></strong> -
									<?php esc_html_e( 'Your Google OAuth credentials are saved. You can now connect GA4 and GSC from the Connections page.', 'specflux-marketing-analytics-chat' ); ?>
									<div class="smac-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-connections&tab=ga4' ) ); ?>" class="aui-btn aui-btn--primary">
											<?php esc_html_e( 'Go to Connections', 'specflux-marketing-analytics-chat' ); ?>
										</a>
									</div>
								</div>
							</div>
							<?php if ( '' !== $oauth_handler->get_proxy_url() ) : ?>
								<form method="post" action="" class="smac-remove-client">
									<?php wp_nonce_field( 'specflux_mac_remove_google_oauth', 'remove_google_oauth_nonce' ); ?>
									<p class="smac-copy">
										<?php esc_html_e( 'Google shows an "unverified app" warning for OAuth clients that Google has not verified. Remove your client to connect through Specflux sign-in instead. Connections made with your client will be disconnected and need reconnecting.', 'specflux-marketing-analytics-chat' ); ?>
									</p>
									<div class="smac-actions">
										<button type="button" class="aui-btn aui-btn--danger-outline" data-aui-open="smac-remove-client">
											<?php esc_html_e( 'Remove my client and use Specflux sign-in', 'specflux-marketing-analytics-chat' ); ?>
										</button>
									</div>

									<div class="aui-backdrop" id="smac-remove-client" hidden>
										<div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="smac-remove-client-title">
											<h2 id="smac-remove-client-title"><?php esc_html_e( 'Remove your Google OAuth client?', 'specflux-marketing-analytics-chat' ); ?></h2>
											<p><?php esc_html_e( 'Connections made with your client will be disconnected and need reconnecting. Sign-in will go through Specflux instead.', 'specflux-marketing-analytics-chat' ); ?></p>
											<div class="aui-dialog-f">
												<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Keep my client', 'specflux-marketing-analytics-chat' ); ?></button>
												<button type="submit" name="remove_google_oauth" value="1" class="aui-btn aui-btn--danger" data-aui-close><?php esc_html_e( 'Remove my client', 'specflux-marketing-analytics-chat' ); ?></button>
											</div>
										</div>
									</div>
								</form>
							<?php endif; ?>
						<?php endif; ?>

						<!-- Progress indicator -->
						<ol class="aui-stepper wizard-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'specflux-marketing-analytics-chat' ); ?>">
							<?php
							$specflux_mac_wizard_steps = array(
								1 => __( 'Project', 'specflux-marketing-analytics-chat' ),
								2 => __( 'APIs', 'specflux-marketing-analytics-chat' ),
								3 => __( 'Consent', 'specflux-marketing-analytics-chat' ),
								4 => __( 'Credentials', 'specflux-marketing-analytics-chat' ),
								5 => __( 'WordPress', 'specflux-marketing-analytics-chat' ),
								6 => __( 'Complete', 'specflux-marketing-analytics-chat' ),
							);
							foreach ( $specflux_mac_wizard_steps as $specflux_mac_step_no => $specflux_mac_step_label ) :
								?>
								<li class="progress-step" data-step="<?php echo esc_attr( $specflux_mac_step_no ); ?>"<?php echo 1 === $specflux_mac_step_no ? ' aria-current="step"' : ''; ?>>
									<button type="button" class="smac-step-btn">
										<span class="aui-step-n step-number"><?php echo esc_html( $specflux_mac_step_no ); ?></span>
										<span class="aui-step-t step-label"><?php echo esc_html( $specflux_mac_step_label ); ?></span>
									</button>
								</li>
							<?php endforeach; ?>
						</ol>

						<p class="wizard-time-estimate smac-copy">
							<?php echo Ui_Kit::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
							<?php esc_html_e( 'Estimated time: 5-7 minutes', 'specflux-marketing-analytics-chat' ); ?>
						</p>

						<!-- Step 1: Create Google Cloud Project -->
						<div class="wizard-step smac-step active" data-step="1" role="group" aria-labelledby="smac-step-1-title">
							<h3 class="smac-subhead" id="smac-step-1-title"><?php esc_html_e( 'Step 1: Create Google Cloud Project', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<p class="smac-copy"><?php esc_html_e( 'You need a Google Cloud project to create OAuth credentials.', 'specflux-marketing-analytics-chat' ); ?></p>

								<div class="smac-actions">
									<a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer" class="aui-btn aui-btn--primary direct-link-button">
										<?php esc_html_e( 'Create New Project in Google Cloud', 'specflux-marketing-analytics-chat' ); ?>
										<?php echo Ui_Kit::icon( 'arrow-square-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
									</a>
								</div>

								<div class="step-instructions">
									<p><strong><?php esc_html_e( 'In the new window:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
									<ol>
										<li>
											<?php esc_html_e( 'Enter project name:', 'specflux-marketing-analytics-chat' ); ?>
											<span class="smac-code-copy"><code id="smac-copy-project">WordPress Marketing Analytics</code><?php Form_Ui::copy_button( 'smac-copy-project' ); ?></span>
										</li>
										<li><?php esc_html_e( 'Click "Create"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Wait for project creation (takes a few seconds)', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Make sure your new project is selected in the project dropdown', 'specflux-marketing-analytics-chat' ); ?></li>
									</ol>
								</div>

								<label class="smac-check step-confirmation">
									<input type="checkbox" id="step1-complete" />
									<span><?php esc_html_e( "I've created and selected my Google Cloud project", 'specflux-marketing-analytics-chat' ); ?></span>
								</label>
							</div>
						</div>

						<!-- Step 2: Enable APIs -->
						<div class="wizard-step smac-step" data-step="2" role="group" aria-labelledby="smac-step-2-title">
							<h3 class="smac-subhead" id="smac-step-2-title"><?php esc_html_e( 'Step 2: Enable Required APIs', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<p class="smac-copy"><?php esc_html_e( 'Click each button below to enable the required APIs in your Google Cloud project.', 'specflux-marketing-analytics-chat' ); ?></p>

								<div class="aui-grid-3 api-enable-grid">
									<?php
									$specflux_mac_apis = array(
										array(
											'key'   => 'ga4-data',
											'title' => __( 'Google Analytics Data API', 'specflux-marketing-analytics-chat' ),
											'desc'  => __( 'Required for reading GA4 metrics and reports', 'specflux-marketing-analytics-chat' ),
											'url'   => 'https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com',
										),
										array(
											'key'   => 'ga4-admin',
											'title' => __( 'Google Analytics Admin API', 'specflux-marketing-analytics-chat' ),
											'desc'  => __( 'Required for listing your GA4 properties', 'specflux-marketing-analytics-chat' ),
											'url'   => 'https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com',
										),
										array(
											'key'   => 'gsc',
											'title' => __( 'Google Search Console API', 'specflux-marketing-analytics-chat' ),
											'desc'  => __( 'Required for Search Console data', 'specflux-marketing-analytics-chat' ),
											'url'   => 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
										),
									);
									foreach ( $specflux_mac_apis as $specflux_mac_api ) :
										?>
										<div class="api-enable-card smac-api-card">
											<h4><?php echo esc_html( $specflux_mac_api['title'] ); ?></h4>
											<p class="aui-help"><?php echo esc_html( $specflux_mac_api['desc'] ); ?></p>
											<a href="<?php echo esc_url( $specflux_mac_api['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="aui-btn aui-btn--secondary api-enable-btn">
												<?php esc_html_e( 'Enable API', 'specflux-marketing-analytics-chat' ); ?>
												<?php echo Ui_Kit::icon( 'arrow-square-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
											</a>
											<label class="smac-check api-status">
												<input type="checkbox" class="api-enabled-checkbox" data-api="<?php echo esc_attr( $specflux_mac_api['key'] ); ?>" />
												<span><?php esc_html_e( 'Enabled', 'specflux-marketing-analytics-chat' ); ?></span>
											</label>
										</div>
									<?php endforeach; ?>
								</div>

								<div class="step-help">
									<p><strong><?php esc_html_e( 'For each API:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
									<ol>
										<li><?php esc_html_e( 'Click the "Enable API" button (opens in new tab)', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'On the API page, click the blue "Enable" button', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Wait for "API enabled" message', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Come back here and check the "Enabled" checkbox', 'specflux-marketing-analytics-chat' ); ?></li>
									</ol>
								</div>

								<div class="aui-notice aui-notice--warning apis-remaining" role="status" style="display: none;">
									<?php echo Ui_Kit::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
									<div><?php esc_html_e( 'Please enable all 3 APIs before continuing', 'specflux-marketing-analytics-chat' ); ?></div>
								</div>
							</div>
						</div>

						<!-- Step 3: OAuth Consent Screen -->
						<div class="wizard-step smac-step" data-step="3" role="group" aria-labelledby="smac-step-3-title">
							<h3 class="smac-subhead" id="smac-step-3-title"><?php esc_html_e( 'Step 3: Configure OAuth Consent Screen', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<p class="smac-copy"><?php esc_html_e( 'Tell Google what permissions your app needs.', 'specflux-marketing-analytics-chat' ); ?></p>

								<div class="smac-actions">
									<a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener noreferrer" class="aui-btn aui-btn--primary direct-link-button">
										<?php esc_html_e( 'Configure OAuth Consent Screen', 'specflux-marketing-analytics-chat' ); ?>
										<?php echo Ui_Kit::icon( 'arrow-square-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
									</a>
								</div>

								<div class="step-instructions">
									<p><strong><?php esc_html_e( 'Configuration:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
									<ol>
										<li><strong><?php esc_html_e( 'User Type:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Choose "External"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><strong><?php esc_html_e( 'App Name:', 'specflux-marketing-analytics-chat' ); ?></strong>
											<span class="smac-code-copy"><code id="smac-copy-appname">Marketing Analytics MCP</code><?php Form_Ui::copy_button( 'smac-copy-appname' ); ?></span>
										</li>
										<li><strong><?php esc_html_e( 'User Support Email:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Your email address', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><strong><?php esc_html_e( 'Developer Contact:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Your email address', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Click "Save and Continue"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><strong><?php esc_html_e( 'Scopes:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Add these two scopes:', 'specflux-marketing-analytics-chat' ); ?>
											<ul>
												<li><span class="smac-code-copy"><code id="smac-copy-scope-ga">https://www.googleapis.com/auth/analytics.readonly</code><?php Form_Ui::copy_button( 'smac-copy-scope-ga' ); ?></span></li>
												<li><span class="smac-code-copy"><code id="smac-copy-scope-gsc">https://www.googleapis.com/auth/webmasters.readonly</code><?php Form_Ui::copy_button( 'smac-copy-scope-gsc' ); ?></span></li>
											</ul>
										</li>
										<li><strong><?php esc_html_e( 'Test Users:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Add your Google account email', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Click "Save and Continue" until complete', 'specflux-marketing-analytics-chat' ); ?></li>
									</ol>
								</div>

								<label class="smac-check step-confirmation">
									<input type="checkbox" id="step3-complete" />
									<span><?php esc_html_e( "I've configured the OAuth consent screen", 'specflux-marketing-analytics-chat' ); ?></span>
								</label>
							</div>
						</div>

						<!-- Step 4: Create OAuth Credentials -->
						<div class="wizard-step smac-step" data-step="4" role="group" aria-labelledby="smac-step-4-title">
							<h3 class="smac-subhead" id="smac-step-4-title"><?php esc_html_e( 'Step 4: Create OAuth 2.0 Credentials', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<p class="smac-copy"><?php esc_html_e( "Create the Client ID and Secret you'll use in WordPress.", 'specflux-marketing-analytics-chat' ); ?></p>

								<div class="smac-actions">
									<a href="https://console.cloud.google.com/apis/credentials/oauthclient" target="_blank" rel="noopener noreferrer" class="aui-btn aui-btn--primary direct-link-button">
										<?php esc_html_e( 'Create OAuth Client ID', 'specflux-marketing-analytics-chat' ); ?>
										<?php echo Ui_Kit::icon( 'arrow-square-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
									</a>
								</div>

								<div class="step-instructions">
									<p><strong><?php esc_html_e( 'In Google Cloud Console:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
									<ol>
										<li><strong><?php esc_html_e( 'Application Type:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Select "Web application"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><strong><?php esc_html_e( 'Name:', 'specflux-marketing-analytics-chat' ); ?></strong>
											<span class="smac-code-copy"><code id="smac-copy-clientname">WordPress MCP Plugin</code><?php Form_Ui::copy_button( 'smac-copy-clientname' ); ?></span>
										</li>
										<li><strong><?php esc_html_e( 'Authorized Redirect URIs:', 'specflux-marketing-analytics-chat' ); ?></strong> <?php esc_html_e( 'Click "Add URI" and paste this:', 'specflux-marketing-analytics-chat' ); ?>
											<div class="aui-copy redirect-uri-box">
												<input type="text" class="aui-input" id="redirect-uri-display" readonly value="<?php echo esc_attr( $oauth_handler->get_redirect_uri() ); ?>" title="<?php echo esc_attr( $oauth_handler->get_redirect_uri() ); ?>" aria-label="<?php esc_attr_e( 'Authorized redirect URI', 'specflux-marketing-analytics-chat' ); ?>" />
												<button type="button" class="aui-btn aui-btn--secondary copy-redirect-uri" data-aui-copy data-aui-target="#redirect-uri-display" data-copied-label="<?php esc_attr_e( 'Copied', 'specflux-marketing-analytics-chat' ); ?>">
													<?php echo Ui_Kit::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
													<span data-aui-copy-label><?php esc_html_e( 'Copy URI', 'specflux-marketing-analytics-chat' ); ?></span>
												</button>
											</div>
											<p class="aui-help"><?php esc_html_e( 'Must match exactly including https:// and all parameters', 'specflux-marketing-analytics-chat' ); ?></p>
										</li>
										<li><?php esc_html_e( 'Click "Create"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Copy the Client ID and Client Secret shown in the popup', 'specflux-marketing-analytics-chat' ); ?></li>
									</ol>
								</div>

								<label class="smac-check step-confirmation">
									<input type="checkbox" id="step4-complete" />
									<span><?php esc_html_e( "I've created OAuth credentials and copied the Client ID and Secret", 'specflux-marketing-analytics-chat' ); ?></span>
								</label>
							</div>
						</div>

						<!-- Step 5: Enter Credentials in WordPress -->
						<div class="wizard-step smac-step" data-step="5" role="group" aria-labelledby="smac-step-5-title">
							<h3 class="smac-subhead" id="smac-step-5-title"><?php esc_html_e( 'Step 5: Enter Credentials in WordPress', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<p class="smac-copy"><?php esc_html_e( 'Paste the credentials you copied from Google Cloud Console.', 'specflux-marketing-analytics-chat' ); ?></p>

								<form id="credentials-form" method="post" action="" class="smac-fields">
									<?php wp_nonce_field( 'specflux_mac_save_google_oauth', 'google_oauth_nonce' ); ?>

									<div class="aui-field">
										<label class="aui-label" for="google_client_id"><?php esc_html_e( 'Client ID', 'specflux-marketing-analytics-chat' ); ?></label>
										<input type="text"
												id="google_client_id"
												name="google_client_id"
												class="aui-input validate-on-blur"
												autocomplete="off"
												placeholder="123456789-abc123.apps.googleusercontent.com"
												aria-describedby="google_client_id_help"
												value="<?php echo esc_attr( $oauth_handler->get_client_id() ?: '' ); ?>" />
										<div class="validation-feedback" role="status"></div>
										<p class="aui-help" id="google_client_id_help"><?php esc_html_e( 'Should end with .apps.googleusercontent.com', 'specflux-marketing-analytics-chat' ); ?></p>
									</div>

									<div class="aui-field">
										<label class="aui-label" for="google_client_secret"><?php esc_html_e( 'Client Secret', 'specflux-marketing-analytics-chat' ); ?></label>
										<div class="aui-copy password-field-wrapper">
											<input type="password"
													id="google_client_secret"
													name="google_client_secret"
													class="aui-input validate-on-blur"
													autocomplete="off"
													data-has-secret="<?php echo $has_oauth_credentials ? '1' : '0'; ?>"
													aria-describedby="google_client_secret_help"
													placeholder="<?php echo $has_oauth_credentials ? esc_attr__( 'Enter new secret to update', 'specflux-marketing-analytics-chat' ) : esc_attr__( 'GOCSPX-...', 'specflux-marketing-analytics-chat' ); ?>" />
											<button type="button" class="aui-btn aui-btn--secondary toggle-password" aria-pressed="false" aria-controls="google_client_secret">
												<span class="toggle-password-label"><?php esc_html_e( 'Show', 'specflux-marketing-analytics-chat' ); ?></span>
											</button>
										</div>
										<div class="validation-feedback" role="status"></div>
										<?php if ( $has_oauth_credentials ) : ?>
											<p class="aui-help smac-help-ok" id="google_client_secret_help">
												<?php esc_html_e( 'Secret saved. Leave blank to keep current, or enter new to update.', 'specflux-marketing-analytics-chat' ); ?>
											</p>
										<?php else : ?>
											<p class="aui-help" id="google_client_secret_help"><?php esc_html_e( 'Should start with GOCSPX-', 'specflux-marketing-analytics-chat' ); ?></p>
										<?php endif; ?>
									</div>

									<div class="smac-actions">
										<button type="submit" name="save_google_oauth" value="1" class="aui-btn aui-btn--primary" id="save-credentials-btn">
											<?php esc_html_e( 'Save Credentials', 'specflux-marketing-analytics-chat' ); ?>
										</button>
									</div>
								</form>
							</div>
						</div>

						<!-- Step 6: Complete -->
						<div class="wizard-step smac-step" data-step="6" role="group" aria-labelledby="smac-step-6-title">
							<h3 class="smac-subhead" id="smac-step-6-title"><?php esc_html_e( 'Step 6: Setup Complete!', 'specflux-marketing-analytics-chat' ); ?></h3>
							<div class="step-content smac-fields">
								<?php if ( $has_oauth_credentials ) : ?>
									<div class="aui-notice aui-notice--success success-message" role="status">
										<?php echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
										<div><strong><?php esc_html_e( 'Credentials saved successfully!', 'specflux-marketing-analytics-chat' ); ?></strong></div>
									</div>

									<p class="smac-copy"><?php esc_html_e( 'Now go to the Connections page to authorize Google Analytics and Search Console.', 'specflux-marketing-analytics-chat' ); ?></p>

									<div class="smac-actions final-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-connections&tab=ga4' ) ); ?>" class="aui-btn aui-btn--primary aui-btn--lg">
											<?php esc_html_e( 'Go to Connections Page', 'specflux-marketing-analytics-chat' ); ?>
										</a>
									</div>
								<?php else : ?>
									<div class="aui-notice aui-notice--info info-message" role="status">
										<?php echo Ui_Kit::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
										<div><?php esc_html_e( 'Complete Step 5 to save your credentials first.', 'specflux-marketing-analytics-chat' ); ?></div>
									</div>
								<?php endif; ?>

								<div class="wizard-complete-section">
									<h4><?php esc_html_e( 'Next Steps:', 'specflux-marketing-analytics-chat' ); ?></h4>
									<ol>
										<li><?php esc_html_e( 'Go to Marketing Analytics → Connections', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Click "Connect to Google Analytics"', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Authorize access to your Google account', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Select your GA4 property', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Repeat for Google Search Console (if needed)', 'specflux-marketing-analytics-chat' ); ?></li>
										<li><?php esc_html_e( 'Start chatting with your analytics data!', 'specflux-marketing-analytics-chat' ); ?></li>
									</ol>
								</div>
							</div>
						</div>
					</div>

					<!-- Navigation buttons -->
					<div class="aui-card-f wizard-navigation">
						<button type="button" class="aui-btn aui-btn--secondary wizard-prev" disabled>
							<?php esc_html_e( 'Previous Step', 'specflux-marketing-analytics-chat' ); ?>
						</button>
						<button type="button" class="aui-btn aui-btn--primary aui-end wizard-next">
							<?php esc_html_e( 'Next Step', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					</div>
				</section>

				<section class="aui-card wizard-help-section">
					<div class="aui-card-b">
						<details class="smac-details">
							<summary><?php esc_html_e( 'Need Help? Common Issues', 'specflux-marketing-analytics-chat' ); ?></summary>
							<div class="help-content smac-fields">
								<h4><?php esc_html_e( 'redirect_uri_mismatch error', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'The redirect URI in Google Cloud must match exactly. Copy it again from Step 4 and make sure there are no extra spaces.', 'specflux-marketing-analytics-chat' ); ?></p>

								<h4><?php esc_html_e( '"This app isn\'t verified" warning', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'This is normal. Click "Advanced" → "Go to [Your App] (unsafe)" to proceed.', 'specflux-marketing-analytics-chat' ); ?></p>

								<h4><?php esc_html_e( 'APIs not showing as enabled', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'Make sure you\'re in the correct Google Cloud project. Check the project dropdown at the top.', 'specflux-marketing-analytics-chat' ); ?></p>

								<p>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac' ) ); ?>">
										<?php esc_html_e( 'View Full Documentation', 'specflux-marketing-analytics-chat' ); ?> →
									</a>
								</p>
							</div>
						</details>
					</div>
				</section>
				</div>
				<?php
				break;

			case 'cache':
				?>
				<form method="post" action="" class="smac-form" data-aui-form>
					<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<!-- Preserve other settings -->
					<input type="hidden" name="ai_temperature" value="<?php echo esc_attr( $settings['ai_temperature'] ?? '0.7' ); ?>" />
					<input type="hidden" name="ai_max_tokens" value="<?php echo esc_attr( $settings['ai_max_tokens'] ?? '4096' ); ?>" />
					<?php
					$enabled_categories = $settings['enabled_tool_categories'] ?? array( 'all' );
					foreach ( $enabled_categories as $category ) :
						?>
						<input type="hidden" name="enabled_tool_categories[]" value="<?php echo esc_attr( $category ); ?>" />
					<?php endforeach; ?>
					<input type="hidden" name="debug_mode" value="<?php echo esc_attr( $settings['debug_mode'] ?? '0' ); ?>" />

					<div class="aui-stack">
						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'Cache Duration Settings', 'specflux-marketing-analytics-chat' ); ?></h2>
									<p class="aui-card-d"><?php esc_html_e( 'Configure how long API responses are cached to reduce API calls and improve performance.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<div class="aui-field">
									<label class="aui-label" for="cache_ttl_clarity"><?php esc_html_e( 'Clarity Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
									<div class="smac-suffix">
										<input type="number" class="aui-input" id="cache_ttl_clarity" name="cache_ttl_clarity"
											value="<?php echo esc_attr( isset( $settings['cache_ttl_clarity'] ) ? $settings['cache_ttl_clarity'] / 60 : 60 ); ?>"
											min="5" max="1440" aria-describedby="cache_ttl_clarity_help" />
										<span><?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?></span>
									</div>
									<p class="aui-help" id="cache_ttl_clarity_help"><?php esc_html_e( 'Default: 60 minutes. Clarity has a rate limit of 10 requests per day.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
								<div class="aui-field">
									<label class="aui-label" for="cache_ttl_ga4"><?php esc_html_e( 'GA4 Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
									<div class="smac-suffix">
										<input type="number" class="aui-input" id="cache_ttl_ga4" name="cache_ttl_ga4"
											value="<?php echo esc_attr( isset( $settings['cache_ttl_ga4'] ) ? $settings['cache_ttl_ga4'] / 60 : 30 ); ?>"
											min="5" max="1440" aria-describedby="cache_ttl_ga4_help" />
										<span><?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?></span>
									</div>
									<p class="aui-help" id="cache_ttl_ga4_help"><?php esc_html_e( 'Default: 30 minutes. Balance between freshness and API quota.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
								<div class="aui-field">
									<label class="aui-label" for="cache_ttl_gsc"><?php esc_html_e( 'Search Console Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
									<div class="smac-suffix">
										<input type="number" class="aui-input" id="cache_ttl_gsc" name="cache_ttl_gsc"
											value="<?php echo esc_attr( isset( $settings['cache_ttl_gsc'] ) ? $settings['cache_ttl_gsc'] / 60 : 1440 ); ?>"
											min="60" max="2880" aria-describedby="cache_ttl_gsc_help" />
										<span><?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?></span>
									</div>
									<p class="aui-help" id="cache_ttl_gsc_help"><?php esc_html_e( 'Default: 1440 minutes (24 hours). GSC data has a 2-3 day delay.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
							</div>
						</section>

						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'Cache Management', 'specflux-marketing-analytics-chat' ); ?></h2>
									<p class="aui-card-d"><?php esc_html_e( 'Remove all cached API responses. Fresh data will be fetched on next request.', 'specflux-marketing-analytics-chat' ); ?></p>
								</div>
							</div>
							<div class="aui-card-b">
								<button type="button" class="aui-btn aui-btn--secondary" data-aui-open="smac-clear-caches">
									<?php esc_html_e( 'Clear All Cached Data', 'specflux-marketing-analytics-chat' ); ?>
								</button>
							</div>
						</section>
					</div>

					<div class="aui-backdrop" id="smac-clear-caches" hidden>
						<div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="smac-clear-caches-title">
							<h2 id="smac-clear-caches-title"><?php esc_html_e( 'Clear all cached data?', 'specflux-marketing-analytics-chat' ); ?></h2>
							<p><?php esc_html_e( 'Are you sure you want to clear all cached data?', 'specflux-marketing-analytics-chat' ); ?></p>
							<div class="aui-dialog-f">
								<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Keep cache', 'specflux-marketing-analytics-chat' ); ?></button>
								<button type="button" class="aui-btn aui-btn--danger clear-all-caches" data-aui-close><?php esc_html_e( 'Clear all cached data', 'specflux-marketing-analytics-chat' ); ?></button>
							</div>
						</div>
					</div>

					<?php Form_Ui::savebar( 'save_settings', __( 'Save Cache Settings', 'specflux-marketing-analytics-chat' ) ); ?>
				</form>
				<?php
				break;

			case 'advanced':
				?>
				<form method="post" action="" class="smac-form" data-aui-form>
					<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<!-- Preserve other settings -->
					<input type="hidden" name="ai_temperature" value="<?php echo esc_attr( $settings['ai_temperature'] ?? '0.7' ); ?>" />
					<input type="hidden" name="ai_max_tokens" value="<?php echo esc_attr( $settings['ai_max_tokens'] ?? '4096' ); ?>" />
					<?php
					$enabled_categories = $settings['enabled_tool_categories'] ?? array( 'all' );
					foreach ( $enabled_categories as $category ) :
						?>
						<input type="hidden" name="enabled_tool_categories[]" value="<?php echo esc_attr( $category ); ?>" />
					<?php endforeach; ?>
					<input type="hidden" name="cache_ttl_clarity" value="<?php echo esc_attr( isset( $settings['cache_ttl_clarity'] ) ? $settings['cache_ttl_clarity'] / 60 : 60 ); ?>" />
					<input type="hidden" name="cache_ttl_ga4" value="<?php echo esc_attr( isset( $settings['cache_ttl_ga4'] ) ? $settings['cache_ttl_ga4'] / 60 : 30 ); ?>" />
					<input type="hidden" name="cache_ttl_gsc" value="<?php echo esc_attr( isset( $settings['cache_ttl_gsc'] ) ? $settings['cache_ttl_gsc'] / 60 : 1440 ); ?>" />

					<div class="aui-stack">
						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'Debug Settings', 'specflux-marketing-analytics-chat' ); ?></h2>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<?php
								Form_Ui::render_toggle(
									array(
										'id'          => 'debug_mode',
										'name'        => 'debug_mode',
										'value'       => '1',
										'checked'     => isset( $settings['debug_mode'] ) && $settings['debug_mode'],
										'label'       => __( 'Enable debug logging', 'specflux-marketing-analytics-chat' ),
										'description' => __( 'Log API requests and responses to the WordPress debug log (credentials are never logged).', 'specflux-marketing-analytics-chat' ),
									)
								);
								?>
							</div>
						</section>

						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'Visit source capture', 'specflux-marketing-analytics-chat' ); ?></h2>
								</div>
							</div>
							<div class="aui-card-b smac-fields">
								<input type="hidden" name="source_capture_submitted" value="1" />
								<?php
								Form_Ui::render_toggle(
									array(
										'id'          => 'source_capture_enabled',
										'name'        => 'source_capture_enabled',
										'value'       => '1',
										'checked'     => (bool) get_option( Source_Capture::OPTION_ENABLED, 1 ),
										'label'       => __( 'Record where visitors came from', 'specflux-marketing-analytics-chat' ),
										'description' => __( 'Sets an anonymous first-party cookie (no identifier, 30 minutes after the last page view) and saves the source on new Easy Digital Downloads orders, GiveWP donations and form entries. Respects the WP Consent API when a consent plugin is active.', 'specflux-marketing-analytics-chat' ),
									)
								);
								?>
							</div>
						</section>

						<section class="aui-card">
							<div class="aui-card-h">
								<div>
									<h2 class="aui-card-t"><?php esc_html_e( 'System Information', 'specflux-marketing-analytics-chat' ); ?></h2>
								</div>
							</div>
							<div class="aui-card-b">
								<dl class="smac-dl">
									<dt><?php esc_html_e( 'Plugin Version', 'specflux-marketing-analytics-chat' ); ?></dt>
									<dd><code><?php echo esc_html( SPECFLUX_MAC_VERSION ); ?></code></dd>
									<dt><?php esc_html_e( 'PHP Version', 'specflux-marketing-analytics-chat' ); ?></dt>
									<dd><code><?php echo esc_html( phpversion() ); ?></code></dd>
									<dt><?php esc_html_e( 'WordPress Version', 'specflux-marketing-analytics-chat' ); ?></dt>
									<dd><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></dd>
									<dt><?php esc_html_e( 'Sodium Extension', 'specflux-marketing-analytics-chat' ); ?></dt>
									<dd>
										<?php if ( extension_loaded( 'sodium' ) ) : ?>
											<span class="aui-badge aui-badge--ok"><?php echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><?php esc_html_e( 'Installed', 'specflux-marketing-analytics-chat' ); ?></span>
										<?php else : ?>
											<span class="aui-badge aui-badge--bad"><?php echo Ui_Kit::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><?php esc_html_e( 'Not installed (required for credential encryption)', 'specflux-marketing-analytics-chat' ); ?></span>
										<?php endif; ?>
									</dd>
								</dl>
							</div>
						</section>
					</div>

					<?php Form_Ui::savebar( 'save_settings', __( 'Save Settings', 'specflux-marketing-analytics-chat' ) ); ?>
				</form>
				<?php
				break;

			case 'access-control':
				// Handle form submission.
				if ( isset( $_POST['save_access_control'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'specflux_mac_save_access_control', 'access_control_nonce' ) ) {

					$allowed_roles = isset( $_POST['allowed_roles'] ) && is_array( $_POST['allowed_roles'] )
						? array_map( 'sanitize_text_field', wp_unslash( $_POST['allowed_roles'] ) )
						: array();

					if ( Permission_Manager::set_allowed_roles( $allowed_roles ) ) {
						echo Form_Ui::notice( 'success', __( 'Access control settings saved successfully.', 'specflux-marketing-analytics-chat' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice().
					} else {
						echo Form_Ui::notice( 'error', __( 'Failed to save access control settings.', 'specflux-marketing-analytics-chat' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice().
					}
				}

				$current_allowed = Permission_Manager::get_allowed_roles();
				$available_roles = Permission_Manager::get_available_roles();
				?>

				<form method="post" action="" class="smac-form" data-aui-form>
					<?php wp_nonce_field( 'specflux_mac_save_access_control', 'access_control_nonce' ); ?>

					<section class="aui-card">
						<div class="aui-card-h">
							<div>
								<h2 class="aui-card-t"><?php esc_html_e( 'Plugin Access Permissions', 'specflux-marketing-analytics-chat' ); ?></h2>
								<p class="aui-card-d"><?php esc_html_e( 'Select which WordPress roles can access this plugin. All features are accessible to selected roles (all-or-nothing access).', 'specflux-marketing-analytics-chat' ); ?></p>
							</div>
						</div>
						<div class="aui-card-b smac-fields">
							<fieldset class="smac-fieldset">
								<legend class="aui-label"><?php esc_html_e( 'Allowed Roles', 'specflux-marketing-analytics-chat' ); ?></legend>
								<div class="smac-checklist">
									<?php foreach ( $available_roles as $slug => $name ) : ?>
										<label class="smac-check">
											<input type="checkbox" name="allowed_roles[]"
												value="<?php echo esc_attr( $slug ); ?>"
												<?php checked( in_array( $slug, $current_allowed, true ) ); ?> />
											<span>
												<strong><?php echo esc_html( $name ); ?></strong>
												<?php if ( 'administrator' === $slug ) : ?>
													<span class="aui-help"><?php esc_html_e( '(Recommended - Full access)', 'specflux-marketing-analytics-chat' ); ?></span>
												<?php endif; ?>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="aui-help"><?php esc_html_e( 'If no roles are selected, only Administrators will have access (default behavior).', 'specflux-marketing-analytics-chat' ); ?></p>
							</fieldset>
						</div>
					</section>

					<?php Form_Ui::savebar( 'save_access_control', __( 'Save Access Control Settings', 'specflux-marketing-analytics-chat' ) ); ?>
				</form>
				<?php
				break;

			case 'email':
				$summary_scheduler  = new Weekly_Summary_Scheduler();
				$summary_recipients = (string) get_option( Weekly_Summary_Scheduler::OPTION_RECIPIENTS, '' );
				?>
				<form method="post" action="" class="smac-form" data-aui-form>
					<?php wp_nonce_field( 'specflux_mac_save_email_settings', 'email_settings_nonce' ); ?>

					<section class="aui-card">
						<div class="aui-card-h">
							<div>
								<h2 class="aui-card-t"><?php esc_html_e( 'Email summaries', 'specflux-marketing-analytics-chat' ); ?></h2>
								<p class="aui-card-d"><?php esc_html_e( 'Every Monday morning (site time) we email a short summary of the last 7 days compared with the 7 days before, from the platforms you have connected, plus Bing search and the SEO issues worth fixing when those are available.', 'specflux-marketing-analytics-chat' ); ?></p>
							</div>
						</div>
						<div class="aui-card-b smac-fields">
							<div class="smac-toggle-row">
								<?php
								Form_Ui::render_toggle(
									array(
										'id'      => 'weekly_summary_enabled',
										'name'    => 'weekly_summary_enabled',
										'value'   => '1',
										'checked' => $summary_scheduler->is_enabled(),
										'label'   => __( 'Email me a weekly summary', 'specflux-marketing-analytics-chat' ),
									)
								);
								?>
								<a class="aui-end" href="<?php echo esc_url( Weekly_Summary_Preview::url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( "See what you'd get", 'specflux-marketing-analytics-chat' ); ?></a>
							</div>

							<div class="aui-field">
								<label class="aui-label" for="weekly_summary_recipients"><?php esc_html_e( 'Recipients', 'specflux-marketing-analytics-chat' ); ?></label>
								<input type="text" id="weekly_summary_recipients" name="weekly_summary_recipients" class="aui-input" value="<?php echo esc_attr( $summary_recipients ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'admin_email' ) ); ?>" aria-describedby="weekly_summary_recipients_help" />
								<p class="aui-help" id="weekly_summary_recipients_help"><?php esc_html_e( 'Separate several addresses with commas. Leave blank to use the site admin email.', 'specflux-marketing-analytics-chat' ); ?></p>
							</div>

							<div class="aui-field">
								<span class="aui-label" id="smac-test-summary-label"><?php esc_html_e( 'Test', 'specflux-marketing-analytics-chat' ); ?></span>
								<div class="smac-actions">
									<button type="button" class="aui-btn aui-btn--secondary" id="smac-send-test-summary" aria-describedby="smac-test-summary-help"><?php esc_html_e( 'Send a test summary now', 'specflux-marketing-analytics-chat' ); ?></button>
									<span id="smac-send-test-summary-result" class="smac-result" role="status"></span>
								</div>
								<p class="aui-help" id="smac-test-summary-help"><?php esc_html_e( 'Sends to the saved recipients. Save your changes first.', 'specflux-marketing-analytics-chat' ); ?></p>
							</div>
						</div>
					</section>

					<?php Form_Ui::savebar( 'save_email_settings', __( 'Save Email Settings', 'specflux-marketing-analytics-chat' ) ); ?>
				</form>
				<?php
				break;

			default:
				ob_start();
				/**
				 * Allow pro add-on to render additional settings tab content.
				 *
				 * @param string $active_tab The currently active tab.
				 */
				do_action( 'specflux_mac_settings_tab_content', $active_tab );
				$specflux_mac_extension_html = (string) ob_get_clean();
				?>
				<?php if ( '' !== trim( $specflux_mac_extension_html ) ) : ?>
					<section class="aui-card"><div class="aui-card-b smac-pro-slot">
						<?php echo $specflux_mac_extension_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup printed by an add-on's own action callback, escaped there; unchanged from calling the action directly. ?>
					</div></section>
				<?php endif; ?>
				<?php
				break;
		}
		?>
	</div>
</div></div>
</div>
</div>
