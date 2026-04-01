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

	use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
	use Specflux_Marketing_Analytics\Utils\Permission_Manager;

	$active_tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
	$success_message = '';
	$error_message   = '';

	// Initialize OAuth handler.
	$oauth_handler = new OAuth_Handler();

	// Handle Google OAuth credential setup.
if ( isset( $_POST['save_google_oauth'] ) && check_admin_referer( 'specflux_mac_save_google_oauth', 'google_oauth_nonce' ) ) {
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

	// Handle general settings form submission.
if ( isset( $_POST['save_settings'] ) && check_admin_referer( 'specflux_mac_save_settings', 'settings_nonce' ) ) {
	$existing_settings = get_option( 'specflux_mac_settings', array() );
	$new_settings      = array();

	// AI Chat Settings.
	$new_settings['ai_provider']    = sanitize_text_field( wp_unslash( $_POST['ai_provider'] ?? 'claude' ) );
	$new_settings['claude_api_key'] = sanitize_text_field( wp_unslash( $_POST['claude_api_key'] ?? '' ) );
	$new_settings['claude_model']   = sanitize_text_field( wp_unslash( $_POST['claude_model'] ?? 'claude-sonnet-4-20250514' ) );
	$new_settings['openai_api_key'] = sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ?? '' ) );
	$new_settings['openai_model']   = sanitize_text_field( wp_unslash( $_POST['openai_model'] ?? 'gpt-5.1' ) );
	$new_settings['gemini_api_key'] = sanitize_text_field( wp_unslash( $_POST['gemini_api_key'] ?? '' ) );
	$new_settings['gemini_model']   = sanitize_text_field( wp_unslash( $_POST['gemini_model'] ?? 'gemini-2.5-pro' ) );
	$new_settings['ai_temperature'] = floatval( $_POST['ai_temperature'] ?? 0.7 );
	$new_settings['ai_max_tokens']  = absint( $_POST['ai_max_tokens'] ?? 4096 );

	// Tool Categories.
	$enabled_categories                      = isset( $_POST['enabled_tool_categories'] ) && is_array( $_POST['enabled_tool_categories'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_tool_categories'] ) )
		: array( 'all' );
	$new_settings['enabled_tool_categories'] = $enabled_categories;

	// Cache Settings.
	$new_settings['cache_ttl_clarity'] = absint( $_POST['cache_ttl_clarity'] ?? 60 ) * 60;
	$new_settings['cache_ttl_ga4']     = absint( $_POST['cache_ttl_ga4'] ?? 30 ) * 60;
	$new_settings['cache_ttl_gsc']     = absint( $_POST['cache_ttl_gsc'] ?? 1440 ) * 60;

	// Debug Settings.
	$new_settings['debug_mode'] = ! empty( $_POST['debug_mode'] ) ? 1 : 0;

	// Preserve keys not present in the form (e.g. platforms, version).
	$new_settings = array_merge( $existing_settings, $new_settings );
	update_option( 'specflux_mac_settings', $new_settings );

	$success_message = __( 'Settings saved successfully.', 'specflux-marketing-analytics-chat' );
}

	$settings              = get_option( 'specflux_mac_settings', array() );
	$has_oauth_credentials = $oauth_handler->has_oauth_credentials();
?>

<div class="wrap specflux-marketing-analytics-chat-settings">
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

	<h2 class="nav-tab-wrapper">
		<a href="?page=specflux-marketing-analytics-chat-settings&tab=general" class="nav-tab		                                                                             <?php echo esc_attr( 'general' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'General', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<a href="?page=specflux-marketing-analytics-chat-settings&tab=google-api" class="nav-tab		                                                                                <?php echo esc_attr( 'google-api' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Google API', 'specflux-marketing-analytics-chat' ); ?>
			<?php if ( $has_oauth_credentials ) : ?>
				<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 14px; margin-left: 5px;"></span>
			<?php endif; ?>
		</a>
		<a href="?page=specflux-marketing-analytics-chat-settings&tab=cache" class="nav-tab		                                                                           <?php echo esc_attr( 'cache' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Cache', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<a href="?page=specflux-marketing-analytics-chat-settings&tab=advanced" class="nav-tab		                                                                              <?php echo esc_attr( 'advanced' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Advanced', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<a href="?page=specflux-marketing-analytics-chat-settings&tab=access-control" class="nav-tab		                                                                                    <?php echo esc_attr( 'access-control' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Access Control', 'specflux-marketing-analytics-chat' ); ?>
		</a>
		<?php
		/**
		 * Allow pro add-on to add additional settings tabs.
		 *
		 * @param string $active_tab The currently active tab.
		 */
		do_action( 'specflux_mac_settings_tabs', $active_tab );
		?>
	</h2>

	<div class="tab-content" style="margin-top: 20px;">
		<?php
		switch ( $active_tab ) {
			case 'general':
				?>
				<form method="post" action="">
				<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<h2><?php esc_html_e( 'AI Chat Settings', 'specflux-marketing-analytics-chat' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="ai_provider"><?php esc_html_e( 'AI Provider', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<select id="ai_provider" name="ai_provider">
									<option value="claude"									                       <?php selected( isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude', 'claude' ); ?>>
									<?php esc_html_e( 'Claude (Anthropic)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="openai"									                       <?php selected( isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : '', 'openai' ); ?>>
									<?php esc_html_e( 'OpenAI GPT', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="gemini"									                       <?php selected( isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : '', 'gemini' ); ?>>
									<?php esc_html_e( 'Google Gemini', 'specflux-marketing-analytics-chat' ); ?>
									</option>
								</select>
								<p class="description"><?php esc_html_e( 'Select the AI provider for chat responses.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="claude_api_key"><?php esc_html_e( 'Claude API Key', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="password" id="claude_api_key" name="claude_api_key"
									value="<?php echo esc_attr( isset( $settings['claude_api_key'] ) ? $settings['claude_api_key'] : '' ); ?>"
									class="regular-text" placeholder="sk-ant-..." />
								<p class="description">
								<?php
									printf(
												/* translators: %s: URL to Anthropic API keys page */
										wp_kses_post( __( 'Get your API key from <a href="%s" target="_blank">Anthropic Console</a>.', 'specflux-marketing-analytics-chat' ) ),
										esc_url( 'https://console.anthropic.com/settings/keys' )
									);
								?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="claude_model"><?php esc_html_e( 'Claude Model', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<select id="claude_model" name="claude_model">
									<option value="claude-sonnet-4-5-20250929"									                                           <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : 'claude-sonnet-4-20250514', 'claude-sonnet-4-5-20250929' ); ?>>
										<?php esc_html_e( 'Claude Sonnet 4.5 (Latest & Best)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="claude-sonnet-4-20250514"									                                         <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : 'claude-sonnet-4-20250514', 'claude-sonnet-4-20250514' ); ?>>
										<?php esc_html_e( 'Claude Sonnet 4 (Recommended)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="claude-opus-4-1-20250805"									                                         <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : '', 'claude-opus-4-1-20250805' ); ?>>
										<?php esc_html_e( 'Claude Opus 4.1 (Most Capable)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="claude-opus-4-20250514"									                                       <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : '', 'claude-opus-4-20250514' ); ?>>
										<?php esc_html_e( 'Claude Opus 4', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="claude-haiku-4-5-20251001"									                                          <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : '', 'claude-haiku-4-5-20251001' ); ?>>
										<?php esc_html_e( 'Claude Haiku 4.5 (Fastest)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="claude-3-haiku-20240307"									                                        <?php selected( isset( $settings['claude_model'] ) ? $settings['claude_model'] : '', 'claude-3-haiku-20240307' ); ?>>
										<?php esc_html_e( 'Claude 3 Haiku (Legacy)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
								</select>
								<p class="description"><?php esc_html_e( 'Select the Claude model to use for chat responses.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="password" id="openai_api_key" name="openai_api_key"
									value="<?php echo esc_attr( isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '' ); ?>"
									class="regular-text" placeholder="sk-..." />
								<p class="description">
									<?php
									printf(
												/* translators: %s: URL to OpenAI API keys page */
										wp_kses_post( __( 'Get your API key from <a href="%s" target="_blank">OpenAI Platform</a>.', 'specflux-marketing-analytics-chat' ) ),
										esc_html( 'https://platform.openai.com/api-keys' )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="openai_model"><?php esc_html_e( 'OpenAI Model', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<select id="openai_model" name="openai_model">
									<optgroup label="<?php esc_attr_e( 'GPT-5 Series (Reasoning)', 'specflux-marketing-analytics-chat' ); ?>">
										<option value="gpt-5.1"										                        <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-5.1', 'gpt-5.1' ); ?>>
											<?php esc_html_e( 'GPT-5.1 - Flagship reasoning model (Recommended)', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5"										                      <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5' ); ?>>
											<?php esc_html_e( 'GPT-5 - Superseded by GPT-5.1', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5-mini"										                           <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5-mini' ); ?>>
											<?php esc_html_e( 'GPT-5 mini - Faster & cheaper reasoning', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5-nano"										                           <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5-nano' ); ?>>
											<?php esc_html_e( 'GPT-5 nano - Budget reasoning', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5.1-chat"										                             <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5.1-chat' ); ?>>
											<?php esc_html_e( 'GPT-5.1 Chat - ChatGPT reasoning', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5.1-codex"										                              <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5.1-codex' ); ?>>
											<?php esc_html_e( 'GPT-5.1 Codex - Advanced coding', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-5.1-codex-mini"										                                   <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-5.1-codex-mini' ); ?>>
											<?php esc_html_e( 'GPT-5.1 Codex mini - Cost-efficient coding', 'specflux-marketing-analytics-chat' ); ?>
										</option>
									</optgroup>
									<optgroup label="<?php esc_attr_e( 'GPT-4.1 Series', 'specflux-marketing-analytics-chat' ); ?>">
										<option value="gpt-4.1"										                        <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-4.1' ); ?>>
											<?php esc_html_e( 'GPT-4.1 - Versatile general model', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-4.1-mini"										                             <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-4.1-mini' ); ?>>
											<?php esc_html_e( 'GPT-4.1 mini - Balanced performance', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-4.1-nano"										                             <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-4.1-nano' ); ?>>
											<?php esc_html_e( 'GPT-4.1 nano - Fast & cheap', 'specflux-marketing-analytics-chat' ); ?>
										</option>
									</optgroup>
									<optgroup label="<?php esc_attr_e( 'GPT-4o Series (Multimodal)', 'specflux-marketing-analytics-chat' ); ?>">
										<option value="gpt-4o"										                       <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-4o' ); ?>>
											<?php esc_html_e( 'GPT-4o - Text, images, audio', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-4o-mini"										                            <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-4o-mini' ); ?>>
											<?php esc_html_e( 'GPT-4o mini - Budget multimodal', 'specflux-marketing-analytics-chat' ); ?>
										</option>
									</optgroup>
									<optgroup label="<?php esc_attr_e( 'o-series (Legacy Reasoning)', 'specflux-marketing-analytics-chat' ); ?>">
										<option value="o3"										                   <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'o3' ); ?>>
											<?php esc_html_e( 'o3 - Superseded by GPT-5', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="o3-pro"										                       <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'o3-pro' ); ?>>
											<?php esc_html_e( 'o3-pro - Advanced reasoning', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="o4-mini"										                        <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'o4-mini' ); ?>>
											<?php esc_html_e( 'o4-mini - Superseded by GPT-5 mini', 'specflux-marketing-analytics-chat' ); ?>
										</option>
									</optgroup>
									<optgroup label="<?php esc_attr_e( 'Open Weight Models', 'specflux-marketing-analytics-chat' ); ?>">
										<option value="gpt-oss-120b"										                             <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-oss-120b' ); ?>>
											<?php esc_html_e( 'GPT-OSS-120B - Open weight (117B params)', 'specflux-marketing-analytics-chat' ); ?>
										</option>
										<option value="gpt-oss-20b"										                            <?php selected( isset( $settings['openai_model'] ) ? $settings['openai_model'] : '', 'gpt-oss-20b' ); ?>>
											<?php esc_html_e( 'GPT-OSS-20B - Medium open weight (21B params)', 'specflux-marketing-analytics-chat' ); ?>
										</option>
									</optgroup>
								</select>
								<p class="description"><?php esc_html_e( 'Select the OpenAI model to use for chat responses.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="password" id="gemini_api_key" name="gemini_api_key"
									value="<?php echo esc_attr( isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '' ); ?>"
									class="regular-text" placeholder="AIza..." />
								<p class="description">
									<?php
									printf(
												/* translators: %s: URL to Google AI Studio */
										wp_kses_post( __( 'Get your API key from <a href="%s" target="_blank">Google AI Studio</a>.', 'specflux-marketing-analytics-chat' ) ),
										esc_html( 'https://aistudio.google.com/apikey' )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gemini_model"><?php esc_html_e( 'Gemini Model', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<select id="gemini_model" name="gemini_model">
									<option value="gemini-3-pro-preview"									                                     <?php selected( isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-pro', 'gemini-3-pro-preview' ); ?>>
										<?php esc_html_e( 'Gemini 3 Pro (Preview - Latest)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="gemini-2.5-pro"									                               <?php selected( isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-pro', 'gemini-2.5-pro' ); ?>>
										<?php esc_html_e( 'Gemini 2.5 Pro (Recommended)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="gemini-2.5-flash"									                                 <?php selected( isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : '', 'gemini-2.5-flash' ); ?>>
										<?php esc_html_e( 'Gemini 2.5 Flash (Fast)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="gemini-2.5-flash-lite"									                                      <?php selected( isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : '', 'gemini-2.5-flash-lite' ); ?>>
										<?php esc_html_e( 'Gemini 2.5 Flash Lite (Budget)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
									<option value="gemini-2.0-flash"									                                 <?php selected( isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : '', 'gemini-2.0-flash' ); ?>>
										<?php esc_html_e( 'Gemini 2.0 Flash (Legacy)', 'specflux-marketing-analytics-chat' ); ?>
									</option>
								</select>
								<p class="description"><?php esc_html_e( 'Select the Gemini model to use for chat responses.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ai_temperature"><?php esc_html_e( 'Temperature', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="number" id="ai_temperature" name="ai_temperature"
									value="<?php echo esc_attr( isset( $settings['ai_temperature'] ) ? $settings['ai_temperature'] : '0.7' ); ?>"
									min="0" max="1" step="0.1" />
								<p class="description"><?php esc_html_e( 'Controls randomness (0 = focused, 1 = creative). Default: 0.7', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ai_max_tokens"><?php esc_html_e( 'Max Tokens', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="number" id="ai_max_tokens" name="ai_max_tokens"
									value="<?php echo esc_attr( isset( $settings['ai_max_tokens'] ) ? $settings['ai_max_tokens'] : '4096' ); ?>"
									min="256" max="8192" step="256" />
								<p class="description"><?php esc_html_e( 'Maximum response length. Default: 4096', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'AI Chat Tool Selection', 'specflux-marketing-analytics-chat' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Enabled Tool Categories', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<?php
								$enabled_categories     = $settings['enabled_tool_categories'] ?? array( 'all' );
										$is_all_enabled = in_array( 'all', $enabled_categories, true );
								?>
								<fieldset>
									<legend class="screen-reader-text"><span><?php esc_html_e( 'Enabled Tool Categories', 'specflux-marketing-analytics-chat' ); ?></span></legend>
									<label>
										<input type="checkbox" name="enabled_tool_categories[]" value="all"
										<?php checked( $is_all_enabled ); ?>
											id="tool_category_all" />
										<strong><?php esc_html_e( 'All Tools', 'specflux-marketing-analytics-chat' ); ?></strong>
										<span class="description"><?php esc_html_e( '(Recommended - AI can use any available tool)', 'specflux-marketing-analytics-chat' ); ?></span>
									</label>
									<br/>
									<label>
										<input type="checkbox" name="enabled_tool_categories[]" value="clarity"
										<?php checked( in_array( 'clarity', $enabled_categories, true ) || $is_all_enabled ); ?>
										<?php disabled( $is_all_enabled ); ?> />
										<?php esc_html_e( 'Microsoft Clarity Tools', 'specflux-marketing-analytics-chat' ); ?>
									</label>
									<br/>
									<label>
										<input type="checkbox" name="enabled_tool_categories[]" value="ga4"
										<?php checked( in_array( 'ga4', $enabled_categories, true ) || $is_all_enabled ); ?>
										<?php disabled( $is_all_enabled ); ?> />
										<?php esc_html_e( 'Google Analytics 4 Tools', 'specflux-marketing-analytics-chat' ); ?>
									</label>
									<br/>
									<label>
										<input type="checkbox" name="enabled_tool_categories[]" value="gsc"
										<?php checked( in_array( 'gsc', $enabled_categories, true ) || $is_all_enabled ); ?>
										<?php disabled( $is_all_enabled ); ?> />
										<?php esc_html_e( 'Google Search Console Tools', 'specflux-marketing-analytics-chat' ); ?>
									</label>
								</fieldset>
								<p class="description">
									<?php
									esc_html_e(
										'Select which tool categories to send to the AI. Fewer tools = lower token costs per request. The AI will only see and use tools from enabled categories. Token usage with tool count is displayed below each AI response.',
										'specflux-marketing-analytics-chat'
									);
									?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<input type="submit" name="save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'specflux-marketing-analytics-chat' ); ?>" />
					</p>
				</form>
				<?php
				break;

			case 'google-api':
				?>
				<div class="wizard-container">
					<h2><?php esc_html_e( 'Google Cloud Console Setup', 'specflux-marketing-analytics-chat' ); ?></h2>
					<p class="description">
				<?php esc_html_e( 'Follow this step-by-step wizard to set up Google OAuth credentials for Analytics and Search Console.', 'specflux-marketing-analytics-chat' ); ?>
					</p>

				<?php if ( $has_oauth_credentials ) : ?>
						<div class="notice notice-success inline" style="margin: 20px 0;">
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
								<strong><?php esc_html_e( 'Credentials Configured', 'specflux-marketing-analytics-chat' ); ?></strong> -
								<?php esc_html_e( 'Your Google OAuth credentials are saved. You can now connect GA4 and GSC from the Connections page.', 'specflux-marketing-analytics-chat' ); ?>
							</p>
							<p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=ga4' ) ); ?>" class="button button-primary">
									<?php esc_html_e( 'Go to Connections', 'specflux-marketing-analytics-chat' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>

					<!-- Progress Indicator -->
					<div class="wizard-progress">
						<div class="progress-bar">
							<div class="progress-fill" style="width: 0%;"></div>
						</div>
						<div class="progress-steps">
							<div class="progress-step active" data-step="1">
								<span class="step-number">1</span>
								<span class="step-label"><?php esc_html_e( 'Project', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
							<div class="progress-step" data-step="2">
								<span class="step-number">2</span>
								<span class="step-label"><?php esc_html_e( 'APIs', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
							<div class="progress-step" data-step="3">
								<span class="step-number">3</span>
								<span class="step-label"><?php esc_html_e( 'Consent', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
							<div class="progress-step" data-step="4">
								<span class="step-number">4</span>
								<span class="step-label"><?php esc_html_e( 'Credentials', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
							<div class="progress-step" data-step="5">
								<span class="step-number">5</span>
								<span class="step-label"><?php esc_html_e( 'WordPress', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
							<div class="progress-step" data-step="6">
								<span class="step-number">6</span>
								<span class="step-label"><?php esc_html_e( 'Complete', 'specflux-marketing-analytics-chat' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Estimated Time -->
					<p class="wizard-time-estimate">
						<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Estimated time: 5-7 minutes', 'specflux-marketing-analytics-chat' ); ?>
					</p>

					<!-- Step 1: Create Google Cloud Project -->
					<div class="wizard-step active" data-step="1">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-cloud"></span>
							</span>
							<h3><?php esc_html_e( 'Step 1: Create Google Cloud Project', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
							<p><?php esc_html_e( 'You need a Google Cloud project to create OAuth credentials.', 'specflux-marketing-analytics-chat' ); ?></p>

							<div class="step-action">
								<a href="https://console.cloud.google.com/projectcreate"
									target="_blank"
									class="button button-primary direct-link-button">
									<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Create New Project in Google Cloud', 'specflux-marketing-analytics-chat' ); ?>
								</a>
							</div>

							<div class="step-instructions">
								<p><strong><?php esc_html_e( 'In the new window:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
								<ol>
									<li>
						<?php esc_html_e( 'Enter project name:', 'specflux-marketing-analytics-chat' ); ?>
										<code class="copyable">WordPress Marketing Analytics
											<button type="button" class="copy-btn" data-copy="WordPress Marketing Analytics">
												<span class="dashicons dashicons-clipboard"></span>
											</button>
										</code>
									</li>
									<li><?php esc_html_e( 'Click "Create"', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><?php esc_html_e( 'Wait for project creation (takes a few seconds)', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><?php esc_html_e( 'Make sure your new project is selected in the project dropdown', 'specflux-marketing-analytics-chat' ); ?></li>
								</ol>
							</div>

							<div class="step-confirmation">
								<label>
									<input type="checkbox" id="step1-complete" />
					<?php esc_html_e( "I've created and selected my Google Cloud project", 'specflux-marketing-analytics-chat' ); ?>
								</label>
							</div>
						</div>
					</div>

					<!-- Step 2: Enable APIs -->
					<div class="wizard-step" data-step="2">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-admin-plugins"></span>
							</span>
							<h3><?php esc_html_e( 'Step 2: Enable Required APIs', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
							<p><?php esc_html_e( 'Click each button below to enable the required APIs in your Google Cloud project.', 'specflux-marketing-analytics-chat' ); ?></p>

							<div class="api-enable-grid">
								<!-- GA4 Data API -->
								<div class="api-enable-card">
									<div class="api-icon">
										<span class="dashicons dashicons-chart-area"></span>
									</div>
									<h4><?php esc_html_e( 'Google Analytics Data API', 'specflux-marketing-analytics-chat' ); ?></h4>
									<p class="description"><?php esc_html_e( 'Required for reading GA4 metrics and reports', 'specflux-marketing-analytics-chat' ); ?></p>
									<a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com"
										target="_blank"
										class="button button-secondary api-enable-btn">
										<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Enable API', 'specflux-marketing-analytics-chat' ); ?>
									</a>
									<div class="api-status">
										<label>
											<input type="checkbox" class="api-enabled-checkbox" data-api="ga4-data" />
							<?php esc_html_e( 'Enabled', 'specflux-marketing-analytics-chat' ); ?>
										</label>
									</div>
								</div>

								<!-- GA4 Admin API -->
								<div class="api-enable-card">
									<div class="api-icon">
										<span class="dashicons dashicons-admin-settings"></span>
									</div>
									<h4><?php esc_html_e( 'Google Analytics Admin API', 'specflux-marketing-analytics-chat' ); ?></h4>
									<p class="description"><?php esc_html_e( 'Required for listing your GA4 properties', 'specflux-marketing-analytics-chat' ); ?></p>
									<a href="https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com"
										target="_blank"
										class="button button-secondary api-enable-btn">
										<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Enable API', 'specflux-marketing-analytics-chat' ); ?>
									</a>
									<div class="api-status">
										<label>
											<input type="checkbox" class="api-enabled-checkbox" data-api="ga4-admin" />
							<?php esc_html_e( 'Enabled', 'specflux-marketing-analytics-chat' ); ?>
										</label>
									</div>
								</div>

								<!-- GSC API -->
								<div class="api-enable-card">
									<div class="api-icon">
										<span class="dashicons dashicons-search"></span>
									</div>
									<h4><?php esc_html_e( 'Google Search Console API', 'specflux-marketing-analytics-chat' ); ?></h4>
									<p class="description"><?php esc_html_e( 'Required for Search Console data', 'specflux-marketing-analytics-chat' ); ?></p>
									<a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com"
										target="_blank"
										class="button button-secondary api-enable-btn">
										<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Enable API', 'specflux-marketing-analytics-chat' ); ?>
									</a>
									<div class="api-status">
										<label>
											<input type="checkbox" class="api-enabled-checkbox" data-api="gsc" />
							<?php esc_html_e( 'Enabled', 'specflux-marketing-analytics-chat' ); ?>
										</label>
									</div>
								</div>
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

							<div class="step-confirmation">
								<p class="apis-remaining" style="display: none;">
									<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Please enable all 3 APIs before continuing', 'specflux-marketing-analytics-chat' ); ?>
								</p>
							</div>
						</div>
					</div>

					<!-- Step 3: OAuth Consent Screen -->
					<div class="wizard-step" data-step="3">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-shield"></span>
							</span>
							<h3><?php esc_html_e( 'Step 3: Configure OAuth Consent Screen', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
							<p><?php esc_html_e( 'Tell Google what permissions your app needs.', 'specflux-marketing-analytics-chat' ); ?></p>

							<div class="step-action">
								<a href="https://console.cloud.google.com/apis/credentials/consent"
									target="_blank"
									class="button button-primary direct-link-button">
									<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Configure OAuth Consent Screen', 'specflux-marketing-analytics-chat' ); ?>
								</a>
							</div>

							<div class="step-instructions">
								<p><strong><?php esc_html_e( 'Configuration:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
								<ol>
									<li><strong><?php esc_html_e( 'User Type:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Choose "External"', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><strong><?php esc_html_e( 'App Name:', 'specflux-marketing-analytics-chat' ); ?></strong>
										<code class="copyable">Marketing Analytics MCP
											<button type="button" class="copy-btn" data-copy="Marketing Analytics MCP">
												<span class="dashicons dashicons-clipboard"></span>
											</button>
										</code>
									</li>
									<li><strong><?php esc_html_e( 'User Support Email:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Your email address', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><strong><?php esc_html_e( 'Developer Contact:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Your email address', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><?php esc_html_e( 'Click "Save and Continue"', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><strong><?php esc_html_e( 'Scopes:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Add these two scopes:', 'specflux-marketing-analytics-chat' ); ?>
										<ul style="margin-top: 8px;">
											<li><code class="copyable">https://www.googleapis.com/auth/analytics.readonly
												<button type="button" class="copy-btn"
													data-copy="https://www.googleapis.com/auth/analytics.readonly">
													<span class="dashicons dashicons-clipboard"></span>
												</button>
											</code></li>
											<li><code class="copyable">https://www.googleapis.com/auth/webmasters.readonly
												<button type="button" class="copy-btn"
													data-copy="https://www.googleapis.com/auth/webmasters.readonly">
													<span class="dashicons dashicons-clipboard"></span>
												</button>
											</code></li>
										</ul>
									</li>
									<li><strong><?php esc_html_e( 'Test Users:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Add your Google account email', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><?php esc_html_e( 'Click "Save and Continue" until complete', 'specflux-marketing-analytics-chat' ); ?></li>
								</ol>
							</div>

							<div class="step-confirmation">
								<label>
									<input type="checkbox" id="step3-complete" />
					<?php esc_html_e( "I've configured the OAuth consent screen", 'specflux-marketing-analytics-chat' ); ?>
								</label>
							</div>
						</div>
					</div>

					<!-- Step 4: Create OAuth Credentials -->
					<div class="wizard-step" data-step="4">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-admin-network"></span>
							</span>
							<h3><?php esc_html_e( 'Step 4: Create OAuth 2.0 Credentials', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
							<p><?php esc_html_e( "Create the Client ID and Secret you'll use in WordPress.", 'specflux-marketing-analytics-chat' ); ?></p>

							<div class="step-action">
								<a href="https://console.cloud.google.com/apis/credentials/oauthclient"
									target="_blank"
									class="button button-primary direct-link-button">
									<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Create OAuth Client ID', 'specflux-marketing-analytics-chat' ); ?>
								</a>
							</div>

							<div class="step-instructions">
								<p><strong><?php esc_html_e( 'In Google Cloud Console:', 'specflux-marketing-analytics-chat' ); ?></strong></p>
								<ol>
									<li><strong><?php esc_html_e( 'Application Type:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Select "Web application"', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><strong><?php esc_html_e( 'Name:', 'specflux-marketing-analytics-chat' ); ?></strong>
										<code class="copyable">WordPress MCP Plugin
											<button type="button" class="copy-btn" data-copy="WordPress MCP Plugin">
												<span class="dashicons dashicons-clipboard"></span>
											</button>
										</code>
									</li>
									<li><strong><?php esc_html_e( 'Authorized Redirect URIs:', 'specflux-marketing-analytics-chat' ); ?></strong><?php esc_html_e( 'Click "Add URI" and paste this:', 'specflux-marketing-analytics-chat' ); ?>
										<div class="redirect-uri-box">
											<code id="redirect-uri-display">
								<?php echo esc_html( $oauth_handler->get_redirect_uri() ); ?>
											</code>
											<button type="button" class="button button-secondary copy-redirect-uri">
												<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy URI', 'specflux-marketing-analytics-chat' ); ?>
											</button>
										</div>
										<p class="description">⚠️										                              <?php esc_html_e( 'Must match exactly including https:// and all parameters', 'specflux-marketing-analytics-chat' ); ?></p>
									</li>
									<li><?php esc_html_e( 'Click "Create"', 'specflux-marketing-analytics-chat' ); ?></li>
									<li><?php esc_html_e( 'Copy the Client ID and Client Secret shown in the popup', 'specflux-marketing-analytics-chat' ); ?></li>
								</ol>
							</div>

							<div class="step-confirmation">
								<label>
									<input type="checkbox" id="step4-complete" />
					<?php esc_html_e( "I've created OAuth credentials and copied the Client ID and Secret", 'specflux-marketing-analytics-chat' ); ?>
								</label>
							</div>
						</div>
					</div>

					<!-- Step 5: Enter Credentials in WordPress -->
					<div class="wizard-step" data-step="5">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-wordpress"></span>
							</span>
							<h3><?php esc_html_e( 'Step 5: Enter Credentials in WordPress', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
							<p><?php esc_html_e( 'Paste the credentials you copied from Google Cloud Console.', 'specflux-marketing-analytics-chat' ); ?></p>

							<form id="credentials-form" method="post" action="">
				<?php wp_nonce_field( 'specflux_mac_save_google_oauth', 'google_oauth_nonce' ); ?>

								<table class="form-table">
									<tr>
										<th scope="row">
											<label for="google_client_id"><?php esc_html_e( 'Client ID', 'specflux-marketing-analytics-chat' ); ?></label>
										</th>
										<td>
											<input type="text"
													id="google_client_id"
													name="google_client_id"
													class="large-text validate-on-blur"
													placeholder="123456789-abc123.apps.googleusercontent.com"
													value="<?php echo esc_attr( $oauth_handler->get_client_id() ?: '' ); ?>" />
											<div class="validation-feedback"></div>
											<p class="description"><?php esc_html_e( 'Should end with .apps.googleusercontent.com', 'specflux-marketing-analytics-chat' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="google_client_secret"><?php esc_html_e( 'Client Secret', 'specflux-marketing-analytics-chat' ); ?></label>
										</th>
										<td>
											<div class="password-field-wrapper">
												<input type="password"
														id="google_client_secret"
														name="google_client_secret"
														class="regular-text validate-on-blur"
														placeholder="<?php echo $has_oauth_credentials ? esc_attr__( 'Enter new secret to update', 'specflux-marketing-analytics-chat' ) : esc_attr__( 'GOCSPX-...', 'specflux-marketing-analytics-chat' ); ?>" />
												<button type="button" class="button button-secondary toggle-password">
													<span class="dashicons dashicons-visibility"></span>
												</button>
											</div>
											<div class="validation-feedback"></div>
							<?php if ( $has_oauth_credentials ) : ?>
												<p class="description" style="color: #00a32a;">
													<span class="dashicons dashicons-yes-alt"></span>
													<?php esc_html_e( 'Secret saved. Leave blank to keep current, or enter new to update.', 'specflux-marketing-analytics-chat' ); ?>
												</p>
											<?php else : ?>
												<p class="description"><?php esc_html_e( 'Should start with GOCSPX-', 'specflux-marketing-analytics-chat' ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								</table>

								<p class="submit">
									<button type="submit"
											name="save_google_oauth"
											class="button button-primary"
											id="save-credentials-btn">
						<?php esc_html_e( 'Save Credentials', 'specflux-marketing-analytics-chat' ); ?>
									</button>
									<span class="spinner" style="float: none; margin: 0 10px;"></span>
								</p>
							</form>
						</div>
					</div>

					<!-- Step 6: Complete -->
					<div class="wizard-step" data-step="6">
						<div class="step-header">
							<span class="step-icon">
								<span class="dashicons dashicons-yes-alt"></span>
							</span>
							<h3><?php esc_html_e( 'Step 6: Setup Complete!', 'specflux-marketing-analytics-chat' ); ?></h3>
							<span class="step-status"></span>
						</div>
						<div class="step-content">
				<?php if ( $has_oauth_credentials ) : ?>
								<div class="success-message">
									<span class="dashicons dashicons-yes-alt"></span>
									<strong><?php esc_html_e( 'Credentials saved successfully!', 'specflux-marketing-analytics-chat' ); ?></strong>
								</div>

								<p><?php esc_html_e( 'Now go to the Connections page to authorize Google Analytics and Search Console.', 'specflux-marketing-analytics-chat' ); ?></p>

								<div class="final-actions">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=ga4' ) ); ?>"
										class="button button-primary button-hero">
										<span class="dashicons dashicons-admin-links"></span>
										<?php esc_html_e( 'Go to Connections Page', 'specflux-marketing-analytics-chat' ); ?>
									</a>
								</div>
							<?php else : ?>
								<div class="info-message">
									<span class="dashicons dashicons-info"></span>
									<?php esc_html_e( 'Complete Step 5 to save your credentials first.', 'specflux-marketing-analytics-chat' ); ?>
								</div>
							<?php endif; ?>

							<div class="wizard-complete-section" style="margin-top: 30px;">
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

					<!-- Navigation Buttons -->
					<div class="wizard-navigation">
						<button type="button" class="button button-large wizard-prev" disabled>
							<span class="dashicons dashicons-arrow-left-alt2"></span>
				<?php esc_html_e( 'Previous Step', 'specflux-marketing-analytics-chat' ); ?>
						</button>
						<button type="button" class="button button-primary button-large wizard-next">
				<?php esc_html_e( 'Next Step', 'specflux-marketing-analytics-chat' ); ?>
							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</button>
					</div>

					<!-- Help Section -->
					<div class="wizard-help-section">
						<details>
							<summary>
								<span class="dashicons dashicons-sos"></span>
				<?php esc_html_e( 'Need Help? Common Issues', 'specflux-marketing-analytics-chat' ); ?>
							</summary>
							<div class="help-content">
								<h4><?php esc_html_e( 'redirect_uri_mismatch error', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'The redirect URI in Google Cloud must match exactly. Copy it again from Step 4 and make sure there are no extra spaces.', 'specflux-marketing-analytics-chat' ); ?></p>

								<h4><?php esc_html_e( '"This app isn\'t verified" warning', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'This is normal. Click "Advanced" → "Go to [Your App] (unsafe)" to proceed.', 'specflux-marketing-analytics-chat' ); ?></p>

								<h4><?php esc_html_e( 'APIs not showing as enabled', 'specflux-marketing-analytics-chat' ); ?></h4>
								<p><?php esc_html_e( 'Make sure you\'re in the correct Google Cloud project. Check the project dropdown at the top.', 'specflux-marketing-analytics-chat' ); ?></p>

								<p>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat' ) ); ?>">
						<?php esc_html_e( 'View Full Documentation', 'specflux-marketing-analytics-chat' ); ?> →
									</a>
								</p>
							</div>
						</details>
					</div>
				</div>
				<?php
				break;

			case 'cache':
				?>
				<form method="post" action="">
					<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<!-- Preserve other settings -->
					<input type="hidden" name="ai_provider" value="<?php echo esc_attr( $settings['ai_provider'] ?? 'claude' ); ?>" />
					<input type="hidden" name="claude_api_key" value="<?php echo esc_attr( $settings['claude_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="claude_model" value="<?php echo esc_attr( $settings['claude_model'] ?? 'claude-sonnet-4-20250514' ); ?>" />
					<input type="hidden" name="openai_api_key" value="<?php echo esc_attr( $settings['openai_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="openai_model" value="<?php echo esc_attr( $settings['openai_model'] ?? 'gpt-5.1' ); ?>" />
					<input type="hidden" name="gemini_api_key" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="gemini_model" value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-2.5-pro' ); ?>" />
					<input type="hidden" name="ai_temperature" value="<?php echo esc_attr( $settings['ai_temperature'] ?? '0.7' ); ?>" />
					<input type="hidden" name="ai_max_tokens" value="<?php echo esc_attr( $settings['ai_max_tokens'] ?? '4096' ); ?>" />
					<?php
					$enabled_categories = $settings['enabled_tool_categories'] ?? array( 'all' );
					foreach ( $enabled_categories as $category ) :
						?>
						<input type="hidden" name="enabled_tool_categories[]" value="<?php echo esc_attr( $category ); ?>" />
					<?php endforeach; ?>
					<input type="hidden" name="debug_mode" value="<?php echo esc_attr( $settings['debug_mode'] ?? '0' ); ?>" />

					<h2><?php esc_html_e( 'Cache Duration Settings', 'specflux-marketing-analytics-chat' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Configure how long API responses are cached to reduce API calls and improve performance.', 'specflux-marketing-analytics-chat' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="cache_ttl_clarity"><?php esc_html_e( 'Clarity Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="number" id="cache_ttl_clarity" name="cache_ttl_clarity"
									value="<?php echo esc_attr( isset( $settings['cache_ttl_clarity'] ) ? $settings['cache_ttl_clarity'] / 60 : 60 ); ?>"
									min="5" max="1440" />									                      <?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?>
								<p class="description"><?php esc_html_e( 'Default: 60 minutes. Clarity has a rate limit of 10 requests per day.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cache_ttl_ga4"><?php esc_html_e( 'GA4 Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="number" id="cache_ttl_ga4" name="cache_ttl_ga4"
									value="<?php echo esc_attr( isset( $settings['cache_ttl_ga4'] ) ? $settings['cache_ttl_ga4'] / 60 : 30 ); ?>"
									min="5" max="1440" />									                      <?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?>
								<p class="description"><?php esc_html_e( 'Default: 30 minutes. Balance between freshness and API quota.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cache_ttl_gsc"><?php esc_html_e( 'Search Console Cache Duration', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<input type="number" id="cache_ttl_gsc" name="cache_ttl_gsc"
									value="<?php echo esc_attr( isset( $settings['cache_ttl_gsc'] ) ? $settings['cache_ttl_gsc'] / 60 : 1440 ); ?>"
									min="60" max="2880" />									                       <?php esc_html_e( 'minutes', 'specflux-marketing-analytics-chat' ); ?>
								<p class="description"><?php esc_html_e( 'Default: 1440 minutes (24 hours). GSC data has a 2-3 day delay.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Cache Management', 'specflux-marketing-analytics-chat' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
					<?php esc_html_e( 'Clear All Caches', 'specflux-marketing-analytics-chat' ); ?>
							</th>
							<td>
								<button type="button" class="button button-secondary clear-all-caches">
						<?php esc_html_e( 'Clear All Cached Data', 'specflux-marketing-analytics-chat' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Remove all cached API responses. Fresh data will be fetched on next request.', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<input type="submit" name="save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Cache Settings', 'specflux-marketing-analytics-chat' ); ?>" />
					</p>
				</form>
					<?php
				break;

			case 'advanced':
				?>
				<form method="post" action="">
				<?php wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' ); ?>

					<!-- Preserve other settings -->
					<input type="hidden" name="ai_provider" value="<?php echo esc_attr( $settings['ai_provider'] ?? 'claude' ); ?>" />
					<input type="hidden" name="claude_api_key" value="<?php echo esc_attr( $settings['claude_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="claude_model" value="<?php echo esc_attr( $settings['claude_model'] ?? 'claude-sonnet-4-20250514' ); ?>" />
					<input type="hidden" name="openai_api_key" value="<?php echo esc_attr( $settings['openai_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="openai_model" value="<?php echo esc_attr( $settings['openai_model'] ?? 'gpt-5.1' ); ?>" />
					<input type="hidden" name="gemini_api_key" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>" />
					<input type="hidden" name="gemini_model" value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-2.5-pro' ); ?>" />
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

					<h2><?php esc_html_e( 'Debug Settings', 'specflux-marketing-analytics-chat' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="debug_mode"><?php esc_html_e( 'Debug Mode', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="debug_mode" name="debug_mode" value="1"
							<?php checked( isset( $settings['debug_mode'] ) && $settings['debug_mode'] ); ?> />
						<?php esc_html_e( 'Enable debug logging', 'specflux-marketing-analytics-chat' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Log API requests and responses to the WordPress debug log (credentials are never logged).', 'specflux-marketing-analytics-chat' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'System Information', 'specflux-marketing-analytics-chat' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugin Version', 'specflux-marketing-analytics-chat' ); ?></th>
							<td><code><?php echo esc_html( SPECFLUX_MAC_VERSION ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'PHP Version', 'specflux-marketing-analytics-chat' ); ?></th>
							<td><code><?php echo esc_html( phpversion() ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WordPress Version', 'specflux-marketing-analytics-chat' ); ?></th>
							<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sodium Extension', 'specflux-marketing-analytics-chat' ); ?></th>
							<td>
							<?php if ( extension_loaded( 'sodium' ) ) : ?>
									<span style="color: #00a32a;"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Installed', 'specflux-marketing-analytics-chat' ); ?></span>
								<?php else : ?>
									<span style="color: #d63638;"><span class="dashicons dashicons-warning"></span><?php esc_html_e( 'Not installed (required for credential encryption)', 'specflux-marketing-analytics-chat' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<p class="submit">
						<input type="submit" name="save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'specflux-marketing-analytics-chat' ); ?>" />
					</p>
				</form>
						<?php
				break;

			case 'access-control':
				// Handle form submission.
				if ( isset( $_POST['save_access_control'] ) && check_admin_referer( 'specflux_mac_save_access_control', 'access_control_nonce' ) ) {

					$allowed_roles = isset( $_POST['allowed_roles'] ) && is_array( $_POST['allowed_roles'] )
						? array_map( 'sanitize_text_field', wp_unslash( $_POST['allowed_roles'] ) )
						: array();

					if ( Permission_Manager::set_allowed_roles( $allowed_roles ) ) {
						echo '<div class="notice notice-success is-dismissible"><p>' .
						esc_html__( 'Access control settings saved successfully.', 'specflux-marketing-analytics-chat' ) .
							'</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' .
						esc_html__( 'Failed to save access control settings.', 'specflux-marketing-analytics-chat' ) .
							'</p></div>';
					}
				}

				$current_allowed = Permission_Manager::get_allowed_roles();
				$available_roles = Permission_Manager::get_available_roles();
				?>

				<form method="post" action="">
				<?php wp_nonce_field( 'specflux_mac_save_access_control', 'access_control_nonce' ); ?>

					<h2><?php esc_html_e( 'Plugin Access Permissions', 'specflux-marketing-analytics-chat' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Select which WordPress roles can access this plugin. All features are accessible to selected roles (all-or-nothing access).', 'specflux-marketing-analytics-chat' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Allowed Roles', 'specflux-marketing-analytics-chat' ); ?></label>
							</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">
										<span><?php esc_html_e( 'Allowed Roles', 'specflux-marketing-analytics-chat' ); ?></span>
									</legend>
						<?php foreach ( $available_roles as $slug => $name ) : ?>
										<label style="display: block; margin: 8px 0;">
											<input type="checkbox" name="allowed_roles[]"
												value="<?php echo esc_attr( $slug ); ?>"
												<?php checked( in_array( $slug, $current_allowed, true ) ); ?> />
											<strong><?php echo esc_html( $name ); ?></strong>
											<?php if ( 'administrator' === $slug ) : ?>
												<span class="description"><?php esc_html_e( '(Recommended - Full access)', 'specflux-marketing-analytics-chat' ); ?></span>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description">
						<?php esc_html_e( 'If no roles are selected, only Administrators will have access (default behavior).', 'specflux-marketing-analytics-chat' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<input type="submit" name="save_access_control" class="button button-primary"
							value="<?php esc_attr_e( 'Save Access Control Settings', 'specflux-marketing-analytics-chat' ); ?>" />
					</p>
				</form>
						<?php
				break;

			default:
				/**
				 * Allow pro add-on to render additional settings tab content.
				 *
				 * @param string $active_tab The currently active tab.
				 */
				do_action( 'specflux_mac_settings_tab_content', $active_tab );
				break;
		}
		?>
	</div>
</div>
