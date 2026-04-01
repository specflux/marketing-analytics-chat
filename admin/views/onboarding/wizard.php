<?php
/**
 * Onboarding Wizard Template
 *
 * Interactive step-by-step wizard shown to new users on first activation.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

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
$connections_url = admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections' );
$ai_chat_url     = admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant' );

// Determine initial step (auto-skip completed steps).
$initial_step = 1;
if ( $any_connected ) {
	$initial_step = 3;
}
?>

<div class="smac-wizard" id="smac-onboarding-wizard"
	data-initial-step="<?php echo esc_attr( $initial_step ); ?>"
	data-clarity-connected="<?php echo esc_attr( $clarity_connected ? '1' : '0' ); ?>"
	data-ga4-connected="<?php echo esc_attr( $ga4_connected ? '1' : '0' ); ?>"
	data-gsc-connected="<?php echo esc_attr( $gsc_connected ? '1' : '0' ); ?>">

	<!-- Skip Setup Link -->
	<a href="#" class="smac-wizard-skip" id="smac-wizard-skip">
		<?php esc_html_e( 'Skip Setup', 'specflux-marketing-analytics-chat' ); ?>
	</a>

	<!-- Progress Bar -->
	<div class="smac-wizard-progress">
		<div class="smac-wizard-progress-bar" id="smac-wizard-progress-bar"></div>
		<div class="smac-wizard-steps-indicator">
			<?php for ( $step_num = 1; $step_num <= 4; $step_num++ ) : ?>
				<span class="smac-wizard-step-dot<?php echo ( 1 === $step_num ) ? ' active' : ''; ?>"
					data-step="<?php echo esc_attr( $step_num ); ?>">
					<?php echo esc_html( $step_num ); ?>
				</span>
			<?php endfor; ?>
		</div>
	</div>

	<!-- Step 1: Welcome -->
	<div class="smac-wizard-step active" data-step="1">
		<div class="smac-wizard-step-content">
			<div class="smac-wizard-step-icon">
				<span class="dashicons dashicons-chart-line"></span>
			</div>
			<h2><?php esc_html_e( 'Welcome to Specflux Marketing Analytics Chat!', 'specflux-marketing-analytics-chat' ); ?></h2>
			<p class="smac-wizard-step-description">
				<?php esc_html_e( 'Chat with your marketing analytics data using AI. Connect Google Analytics 4, Search Console, and Microsoft Clarity to get instant insights about your website performance.', 'specflux-marketing-analytics-chat' ); ?>
			</p>
			<p class="smac-wizard-step-subtitle">
				<?php esc_html_e( "Let's get you set up in under 60 seconds.", 'specflux-marketing-analytics-chat' ); ?>
			</p>
			<div class="smac-wizard-step-actions">
				<button type="button" class="button button-primary smac-wizard-next">
					<?php esc_html_e( 'Get Started', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Step 2: Connect Your First Platform -->
	<div class="smac-wizard-step" data-step="2">
		<div class="smac-wizard-step-content">
			<h2><?php esc_html_e( 'Connect Your First Platform', 'specflux-marketing-analytics-chat' ); ?></h2>
			<p class="smac-wizard-step-description">
				<?php esc_html_e( 'Connect at least one analytics platform to start chatting with your data.', 'specflux-marketing-analytics-chat' ); ?>
			</p>

			<div class="smac-wizard-platform-cards">
				<!-- Microsoft Clarity -->
				<div class="smac-wizard-platform-card <?php echo esc_attr( $clarity_connected ? 'connected' : '' ); ?>">
					<div class="smac-wizard-platform-icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<div class="smac-wizard-platform-info">
						<h3><?php esc_html_e( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Session recordings and heatmaps', 'specflux-marketing-analytics-chat' ); ?></p>
					</div>
					<div class="smac-wizard-platform-status">
						<?php if ( $clarity_connected ) : ?>
							<span class="smac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=clarity' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'specflux-marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Google Analytics 4 -->
				<div class="smac-wizard-platform-card <?php echo esc_attr( $ga4_connected ? 'connected' : '' ); ?>">
					<div class="smac-wizard-platform-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="smac-wizard-platform-info">
						<h3><?php esc_html_e( 'Google Analytics 4', 'specflux-marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Traffic and user behavior metrics', 'specflux-marketing-analytics-chat' ); ?></p>
					</div>
					<div class="smac-wizard-platform-status">
						<?php if ( $ga4_connected ) : ?>
							<span class="smac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=ga4' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'specflux-marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Google Search Console -->
				<div class="smac-wizard-platform-card <?php echo esc_attr( $gsc_connected ? 'connected' : '' ); ?>">
					<div class="smac-wizard-platform-icon">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div class="smac-wizard-platform-info">
						<h3><?php esc_html_e( 'Google Search Console', 'specflux-marketing-analytics-chat' ); ?></h3>
						<p><?php esc_html_e( 'Search performance and indexing', 'specflux-marketing-analytics-chat' ); ?></p>
					</div>
					<div class="smac-wizard-platform-status">
						<?php if ( $gsc_connected ) : ?>
							<span class="smac-wizard-connected-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $connections_url . '&tab=gsc' ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect', 'specflux-marketing-analytics-chat' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="smac-wizard-step-actions">
				<button type="button" class="button smac-wizard-prev">
					<?php esc_html_e( 'Back', 'specflux-marketing-analytics-chat' ); ?>
				</button>
				<?php if ( $any_connected ) : ?>
					<button type="button" class="button button-primary smac-wizard-next">
						<?php esc_html_e( 'Next', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button smac-wizard-next">
						<?php esc_html_e( 'Skip for Now', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Step 3: Try Your First Analysis -->
	<div class="smac-wizard-step" data-step="3">
		<div class="smac-wizard-step-content">
			<h2><?php esc_html_e( 'Try Your First Analysis', 'specflux-marketing-analytics-chat' ); ?></h2>
			<p class="smac-wizard-step-description">
				<?php esc_html_e( 'Choose a quick analysis to see Specflux Marketing Analytics Chat in action.', 'specflux-marketing-analytics-chat' ); ?>
			</p>

			<div class="smac-wizard-action-cards">
				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Give me a weekly report of my website analytics for the past 7 days.' ) ); ?>" class="smac-wizard-action-card">
					<span class="dashicons dashicons-calendar-alt"></span>
					<h3><?php esc_html_e( 'Weekly Report', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Get a summary of your past 7 days of analytics data.', 'specflux-marketing-analytics-chat' ); ?></p>
				</a>

				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Run an SEO health check on my website. Check search console for any issues, top queries, and indexing status.' ) ); ?>" class="smac-wizard-action-card">
					<span class="dashicons dashicons-search"></span>
					<h3><?php esc_html_e( 'SEO Health Check', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Analyze your search performance and find issues.', 'specflux-marketing-analytics-chat' ); ?></p>
				</a>

				<a href="<?php echo esc_url( $ai_chat_url . '&prompt=' . rawurlencode( 'Investigate any anomalies in my analytics data from the past 30 days. Look for unusual spikes or drops in traffic, engagement, or conversions.' ) ); ?>" class="smac-wizard-action-card">
					<span class="dashicons dashicons-warning"></span>
					<h3><?php esc_html_e( 'Anomaly Investigation', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Find unusual patterns in your analytics data.', 'specflux-marketing-analytics-chat' ); ?></p>
				</a>
			</div>

			<div class="smac-wizard-or-section">
				<span><?php esc_html_e( 'Or ask anything...', 'specflux-marketing-analytics-chat' ); ?></span>
				<a href="<?php echo esc_url( $ai_chat_url ); ?>" class="button">
					<?php esc_html_e( 'Open AI Chat', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>

			<div class="smac-wizard-step-actions">
				<button type="button" class="button smac-wizard-prev">
					<?php esc_html_e( 'Back', 'specflux-marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button button-primary smac-wizard-next">
					<?php esc_html_e( 'Next', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Step 4: Complete! -->
	<div class="smac-wizard-step" data-step="4">
		<div class="smac-wizard-step-content">
			<div class="smac-wizard-step-icon smac-wizard-success-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<h2><?php esc_html_e( "You're All Set!", 'specflux-marketing-analytics-chat' ); ?></h2>
			<p class="smac-wizard-step-description">
				<?php esc_html_e( 'Specflux Marketing Analytics Chat is ready to use. Here are some quick links to get the most out of the plugin.', 'specflux-marketing-analytics-chat' ); ?>
			</p>

			<div class="smac-wizard-final-links">
				<a href="<?php echo esc_url( $ai_chat_url ); ?>" class="smac-wizard-final-link">
					<span class="dashicons dashicons-format-chat"></span>
					<span><?php esc_html_e( 'AI Chat', 'specflux-marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( $connections_url ); ?>" class="smac-wizard-final-link">
					<span class="dashicons dashicons-admin-plugins"></span>
					<span><?php esc_html_e( 'Connections', 'specflux-marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-prompts' ) ); ?>" class="smac-wizard-final-link">
					<span class="dashicons dashicons-editor-code"></span>
					<span><?php esc_html_e( 'Custom Prompts', 'specflux-marketing-analytics-chat' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-settings' ) ); ?>" class="smac-wizard-final-link">
					<span class="dashicons dashicons-admin-generic"></span>
					<span><?php esc_html_e( 'Settings', 'specflux-marketing-analytics-chat' ); ?></span>
				</a>
			</div>

			<div class="smac-wizard-step-actions">
				<button type="button" class="button smac-wizard-prev">
					<?php esc_html_e( 'Back', 'specflux-marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button button-primary smac-wizard-dismiss" id="smac-wizard-dismiss">
					<?php esc_html_e( 'Dismiss Wizard', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
