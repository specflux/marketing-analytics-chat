<?php
/**
 * Onboarding Wizard Template
 *
 * Interactive step-by-step wizard shown to new users on first activation.
 *
 * @package Marketing_Analytics_MCP
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Marketing_Analytics_MCP\Credentials\Credential_Manager;

// Use existing variables from parent scope if available, otherwise create them.
if ( ! isset( $credential_manager ) ) {
	$credential_manager = new Credential_Manager();
}
if ( ! isset( $clarity_connected ) ) {
	$clarity_connected = $credential_manager->has_credentials( 'clarity' );
}
if ( ! isset( $ga4_connected ) ) {
	$ga4_connected = $credential_manager->has_credentials( 'ga4' );
}
if ( ! isset( $gsc_connected ) ) {
	$gsc_connected = $credential_manager->has_credentials( 'gsc' );
}
$all_connected   = $clarity_connected && $ga4_connected && $gsc_connected;
$any_connected   = $clarity_connected || $ga4_connected || $gsc_connected;
$connections_url = admin_url( 'admin.php?page=marketing-analytics-chat-connections' );
$ai_chat_url     = admin_url( 'admin.php?page=marketing-analytics-chat-ai-assistant' );

// Determine initial step (auto-skip completed steps).
$initial_step = 1;
if ( $any_connected ) {
	$initial_step = 3;
}
?>

<div class="mac-wizard" id="mac-onboarding-wizard"
	data-initial-step="<?php echo esc_attr( $initial_step ); ?>"
	data-clarity-connected="<?php echo esc_attr( $clarity_connected ? '1' : '0' ); ?>"
	data-ga4-connected="<?php echo esc_attr( $ga4_connected ? '1' : '0' ); ?>"
	data-gsc-connected="<?php echo esc_attr( $gsc_connected ? '1' : '0' ); ?>">

	<!-- Skip Setup Link -->
	<a href="#" class="mac-wizard-skip" id="mac-wizard-skip">
		<?php esc_html_e( 'Skip Setup', 'marketing-analytics-chat' ); ?>
	</a>

	<!-- Progress Bar -->
	<div class="mac-wizard-progress">
		<div class="mac-wizard-progress-bar" id="mac-wizard-progress-bar"></div>
		<div class="mac-wizard-steps-indicator">
			<?php for ( $step_num = 1; $step_num <= 4; $step_num++ ) : ?>
				<span class="mac-wizard-step-dot<?php echo ( 1 === $step_num ) ? ' active' : ''; ?>"
					data-step="<?php echo esc_attr( $step_num ); ?>">
					<?php echo esc_html( $step_num ); ?>
				</span>
			<?php endfor; ?>
		</div>
	</div>

	<!-- Step 1: Welcome -->
	<div class="mac-wizard-step active" data-step="1">
		<div class="mac-wizard-step-content">
			<div class="mac-wizard-step-icon">
				<span class="dashicons dashicons-chart-line"></span>
			</div>
			<h2><?php esc_html_e( 'Welcome to Marketing Analytics Chat!', 'marketing-analytics-chat' ); ?></h2>
			<p class="mac-wizard-step-description">
				<?php esc_html_e( 'Chat with your marketing analytics data using AI. Connect Google Analytics 4, Search Console, and Microsoft Clarity to get instant insights about your website performance.', 'marketing-analytics-chat' ); ?>
			</p>
			<p class="mac-wizard-step-subtitle">
				<?php esc_html_e( "Let's get you set up in under 60 seconds.", 'marketing-analytics-chat' ); ?>
			</p>
			<div class="mac-wizard-step-actions">
				<button type="button" class="button button-primary mac-wizard-next">
					<?php esc_html_e( 'Get Started', 'marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Step 2: Connect Your First Platform -->
	<div class="mac-wizard-step" data-step="2">
		<div class="mac-wizard-step-content">
			<h2><?php esc_html_e( 'Connect Your First Platform', 'marketing-analytics-chat' ); ?></h2>
			<p class="mac-wizard-step-description">
				<?php esc_html_e( 'Connect at least one analytics platform to start chatting with your data.', 'marketing-analytics-chat' ); ?>
			</p>

			<div class="mac-wizard-platform-cards">
				<!-- Microsoft Clarity -->
				<div class="mac-wizard-platform-card <?php echo esc_attr( $clarity_connected ? 'connected' : '' ); ?>">
					<div class="mac-wizard-platform-icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<div class="mac-wizard-platform-info">
						<h3><?php esc_html_e( 'Microsoft Clarity', 'marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Session recordings and heatmaps', 'marketing-analytics-chat' ); ?></p>
					</div>
					<div class="mac-wizard-platform-status">
						<?php if ( $clarity_connected ) : ?>
							<span class="mac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=clarity' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Google Analytics 4 -->
				<div class="mac-wizard-platform-card <?php echo esc_attr( $ga4_connected ? 'connected' : '' ); ?>">
					<div class="mac-wizard-platform-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="mac-wizard-platform-info">
						<h3><?php esc_html_e( 'Google Analytics 4', 'marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Traffic and user behavior metrics', 'marketing-analytics-chat' ); ?></p>
					</div>
					<div class="mac-wizard-platform-status">
						<?php if ( $ga4_connected ) : ?>
							<span class="mac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=ga4' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Google Search Console -->
				<div class="mac-wizard-platform-card <?php echo esc_attr( $gsc_connected ? 'connected' : '' ); ?>">
					<div class="mac-wizard-platform-icon">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div class="mac-wizard-platform-info">
						<h3><?php esc_html_e( 'Google Search Console', 'marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Search performance and indexing', 'marketing-analytics-chat' ); ?></p>
					</div>
					<div class="mac-wizard-platform-status">
						<?php if ( $gsc_connected ) : ?>
							<span class="mac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=gsc' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="mac-wizard-step-actions">
				<button type="button" class="button mac-wizard-prev">
					<?php esc_html_e( 'Back', 'marketing-analytics-chat' ); ?>
				</button>
				<?php if ( $any_connected ) : ?>
					<button type="button" class="button button-primary mac-wizard-next">
						<?php esc_html_e( 'Next', 'marketing-analytics-chat' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button mac-wizard-next">
						<?php esc_html_e( 'Skip for Now', 'marketing-analytics-chat' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Step 3: Try Your First Analysis -->
	<div class="mac-wizard-step" data-step="3">
		<div class="mac-wizard-step-content">
			<h2><?php esc_html_e( 'Try Your First Analysis', 'marketing-analytics-chat' ); ?></h2>
			<p class="mac-wizard-step-description">
				<?php esc_html_e( 'Choose a quick analysis to see Marketing Analytics Chat in action.', 'marketing-analytics-chat' ); ?>
			</p>

			<div class="mac-wizard-action-cards">
				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Give me a weekly report of my website analytics for the past 7 days.' ) ); ?>" class="mac-wizard-action-card">
					<span class="dashicons dashicons-calendar-alt"></span>
					<h3><?php esc_html_e( 'Weekly Report', 'marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Get a summary of your past 7 days of analytics data.', 'marketing-analytics-chat' ); ?></p>
				</a>

				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Run an SEO health check on my website. Check search console for any issues, top queries, and indexing status.' ) ); ?>" class="mac-wizard-action-card">
					<span class="dashicons dashicons-search"></span>
					<h3><?php esc_html_e( 'SEO Health Check', 'marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Analyze your search performance and find issues.', 'marketing-analytics-chat' ); ?></p>
				</a>

				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Investigate any anomalies in my analytics data from the past 30 days. Look for unusual spikes or drops in traffic, engagement, or conversions.' ) ); ?>" class="mac-wizard-action-card">
					<span class="dashicons dashicons-warning"></span>
					<h3><?php esc_html_e( 'Anomaly Investigation', 'marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Find unusual patterns in your analytics data.', 'marketing-analytics-chat' ); ?></p>
				</a>
			</div>

			<div class="mac-wizard-or-section">
				<span><?php esc_html_e( 'Or ask anything...', 'marketing-analytics-chat' ); ?></span>
				<a href="<?php echo esc_url( $ai_chat_url ); ?>" class="button">
					<?php esc_html_e( 'Open AI Chat', 'marketing-analytics-chat' ); ?>
				</a>
			</div>

			<div class="mac-wizard-step-actions">
				<button type="button" class="button mac-wizard-prev">
					<?php esc_html_e( 'Back', 'marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button button-primary mac-wizard-next">
					<?php esc_html_e( 'Next', 'marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Step 4: Complete! -->
	<div class="mac-wizard-step" data-step="4">
		<div class="mac-wizard-step-content">
			<div class="mac-wizard-step-icon mac-wizard-success-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<h2><?php esc_html_e( "You're All Set!", 'marketing-analytics-chat' ); ?></h2>
			<p class="mac-wizard-step-description">
				<?php esc_html_e( 'Marketing Analytics Chat is ready to use. Here are some quick links to get the most out of the plugin.', 'marketing-analytics-chat' ); ?>
			</p>

			<div class="mac-wizard-final-links">
				<a href="<?php echo esc_url( $ai_chat_url ); ?>" class="mac-wizard-final-link">
					<span class="dashicons dashicons-format-chat"></span>
					<span><?php esc_html_e( 'AI Chat', 'marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( $connections_url ); ?>" class="mac-wizard-final-link">
					<span class="dashicons dashicons-admin-plugins"></span>
					<span><?php esc_html_e( 'Connections', 'marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=marketing-analytics-chat-prompts' ) ); ?>" class="mac-wizard-final-link">
					<span class="dashicons dashicons-editor-code"></span>
					<span><?php esc_html_e( 'Custom Prompts', 'marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=marketing-analytics-chat-settings' ) ); ?>" class="mac-wizard-final-link">
					<span class="dashicons dashicons-admin-generic"></span>
					<span><?php esc_html_e( 'Settings', 'marketing-analytics-chat' ); ?></span>
				</a>
			</div>

			<div class="mac-wizard-step-actions">
				<button type="button" class="button mac-wizard-prev">
					<?php esc_html_e( 'Back', 'marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button button-primary mac-wizard-dismiss" id="mac-wizard-dismiss">
					<?php esc_html_e( 'Dismiss Wizard', 'marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
