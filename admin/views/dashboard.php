<?php
/**
 * Dashboard Page Template
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Connection_Promoter;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

$settings  = get_option( 'specflux_mac_settings', array() );
$platforms = isset( $settings['platforms'] ) ? $settings['platforms'] : array();

// Check actual credential existence instead of manual flags.
$credential_manager = new Credential_Manager();
$clarity_connected  = $credential_manager->has_credentials( 'clarity' );
$ga4_connected      = $credential_manager->has_credentials( 'ga4' );
$gsc_connected      = $credential_manager->has_credentials( 'gsc' );
?>

<div class="wrap specflux-marketing-analytics-chat-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php
	$onboarding_complete = get_option( 'specflux_mac_onboarding_complete' );
	if ( ! $onboarding_complete ) :
		require_once SPECFLUX_MAC_PATH . 'admin/views/onboarding/wizard.php';
	endif;
	?>

	<div class="smac-welcome">
		<h2><?php esc_html_e( 'Welcome to Specflux Marketing Analytics Chat', 'specflux-marketing-analytics-chat' ); ?></h2>
		<p><?php esc_html_e( 'Chat with your marketing analytics data using AI. Connect Google Analytics 4, Search Console, Microsoft Clarity, and more to get instant insights.', 'specflux-marketing-analytics-chat' ); ?></p>
	</div>

	<!-- AI Assistant Quick Access -->
	<div class="smac-quick-actions">
		<div class="quick-action-card">
			<div class="quick-action-icon">
				<span class="dashicons dashicons-format-chat"></span>
			</div>
			<div class="quick-action-content">
				<h3><?php esc_html_e( 'AI Assistant', 'specflux-marketing-analytics-chat' ); ?></h3>
				<p><?php esc_html_e( 'Start chatting with your analytics data right now', 'specflux-marketing-analytics-chat' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open AI Chat', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>
		</div>
		<div class="quick-action-card">
			<div class="quick-action-icon">
				<span class="dashicons dashicons-editor-code"></span>
			</div>
			<div class="quick-action-content">
				<h3><?php esc_html_e( 'Custom Prompts', 'specflux-marketing-analytics-chat' ); ?></h3>
				<p><?php esc_html_e( 'Create reusable prompt templates for common analyses', 'specflux-marketing-analytics-chat' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-prompts' ) ); ?>" class="button">
					<?php esc_html_e( 'Manage Prompts', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>
		</div>
		<div class="quick-action-card">
			<div class="quick-action-icon">
				<span class="dashicons dashicons-analytics"></span>
			</div>
			<div class="quick-action-content">
				<h3><?php esc_html_e( 'Quick Analysis', 'specflux-marketing-analytics-chat' ); ?></h3>
				<p><?php esc_html_e( 'Run a pre-built analysis with one click', 'specflux-marketing-analytics-chat' ); ?></p>
				<div class="smac-quick-analysis-buttons">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant&prompt=weekly-report' ) ); ?>" class="button">
						<?php esc_html_e( 'Weekly Report', 'specflux-marketing-analytics-chat' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant&prompt=seo-health-check' ) ); ?>" class="button">
						<?php esc_html_e( 'SEO Health', 'specflux-marketing-analytics-chat' ); ?>
					</a>
				</div>
			</div>
		</div>
	</div>

	<?php
	/**
	 * Fires after quick actions, before analytics panels.
	 *
	 * Used by the premium add-on to inject AI Insight Cards and other dashboard widgets.
	 *
	 * @since 1.5.0
	 */
	do_action( 'specflux_mac_dashboard_cards' );
	?>

	<?php
	// Analytics at a Glance — only show if at least one platform is connected.
	$has_any_connection = $clarity_connected || $ga4_connected || $gsc_connected;
	if ( $has_any_connection ) :

		// Read transients (no live API calls).
		$ga4_data     = $ga4_connected ? get_transient( 'specflux_mac_ga4_day_summary' ) : false;
		$clarity_data = $clarity_connected ? get_transient( 'specflux_mac_clarity_day_summary' ) : false;
		$gsc_data     = $gsc_connected ? get_transient( 'specflux_mac_gsc_day_summary' ) : false;
		$has_any_data = ( false !== $ga4_data || false !== $clarity_data || false !== $gsc_data );
		?>
		<div class="smac-insights-panel">
			<div class="smac-insights-header">
				<h3><?php esc_html_e( 'Analytics at a Glance', 'specflux-marketing-analytics-chat' ); ?></h3>
				<button type="button" class="button button-small smac-insights-refresh">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>

			<?php if ( $has_any_data ) : ?>
				<div class="smac-insights-grid">

					<?php
					// --- GA4 Metrics ---
					if ( false !== $ga4_data && isset( $ga4_data['rows'] ) && is_array( $ga4_data['rows'] ) ) :
						$ga4_sessions        = 0;
						$ga4_users           = 0;
						$ga4_pageviews       = 0;
						$ga4_sessions_spark  = array();
						$ga4_users_spark     = array();
						$ga4_pageviews_spark = array();

						foreach ( $ga4_data['rows'] as $ga4_row ) {
							if ( isset( $ga4_row['metricValues'] ) && is_array( $ga4_row['metricValues'] ) ) {
								$ga4_s = isset( $ga4_row['metricValues'][0]['value'] ) ? (int) $ga4_row['metricValues'][0]['value'] : 0;
								$ga4_u = isset( $ga4_row['metricValues'][1]['value'] ) ? (int) $ga4_row['metricValues'][1]['value'] : 0;
								$ga4_p = isset( $ga4_row['metricValues'][2]['value'] ) ? (int) $ga4_row['metricValues'][2]['value'] : 0;

								$ga4_sessions  += $ga4_s;
								$ga4_users     += $ga4_u;
								$ga4_pageviews += $ga4_p;

								$ga4_sessions_spark[]  = $ga4_s;
								$ga4_users_spark[]     = $ga4_u;
								$ga4_pageviews_spark[] = $ga4_p;
							}
						}
						?>

						<div class="smac-metric-card" data-platform="ga4" data-metric="sessions">
							<span class="smac-metric-platform"><?php esc_html_e( 'GA4', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Sessions', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $ga4_sessions ) ); ?></span>
							<?php if ( count( $ga4_sessions_spark ) > 1 ) : ?>
								<div class="smac-sparkline" data-values="<?php echo esc_attr( wp_json_encode( $ga4_sessions_spark ) ); ?>" data-color="#2271b1"></div>
							<?php endif; ?>
						</div>

						<div class="smac-metric-card" data-platform="ga4" data-metric="users">
							<span class="smac-metric-platform"><?php esc_html_e( 'GA4', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Users', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $ga4_users ) ); ?></span>
							<?php if ( count( $ga4_users_spark ) > 1 ) : ?>
								<div class="smac-sparkline" data-values="<?php echo esc_attr( wp_json_encode( $ga4_users_spark ) ); ?>" data-color="#2271b1"></div>
							<?php endif; ?>
						</div>

						<div class="smac-metric-card" data-platform="ga4" data-metric="pageviews">
							<span class="smac-metric-platform"><?php esc_html_e( 'GA4', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Pageviews', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $ga4_pageviews ) ); ?></span>
							<?php if ( count( $ga4_pageviews_spark ) > 1 ) : ?>
								<div class="smac-sparkline" data-values="<?php echo esc_attr( wp_json_encode( $ga4_pageviews_spark ) ); ?>" data-color="#2271b1"></div>
							<?php endif; ?>
						</div>

					<?php endif; ?>

					<?php
					// --- Clarity Metrics ---
					if ( false !== $clarity_data ) :
						$clarity_sessions          = isset( $clarity_data['totalSessions'] ) ? (int) $clarity_data['totalSessions'] : 0;
						$clarity_pages_per_session = isset( $clarity_data['pagesPerSession'] ) ? (float) $clarity_data['pagesPerSession'] : 0;
						?>

						<div class="smac-metric-card" data-platform="clarity" data-metric="sessions">
							<span class="smac-metric-platform"><?php esc_html_e( 'Clarity', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Sessions', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $clarity_sessions ) ); ?></span>
						</div>

						<div class="smac-metric-card" data-platform="clarity" data-metric="pages_per_session">
							<span class="smac-metric-platform"><?php esc_html_e( 'Clarity', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Pages / Session', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format( $clarity_pages_per_session, 1 ) ); ?></span>
						</div>

					<?php endif; ?>

					<?php
					// --- GSC Metrics ---
					if ( false !== $gsc_data && isset( $gsc_data['rows'] ) && is_array( $gsc_data['rows'] ) ) :
						$gsc_clicks      = 0;
						$gsc_impressions = 0;
						$gsc_position    = 0;
						$gsc_row_count   = count( $gsc_data['rows'] );

						$gsc_clicks_spark      = array();
						$gsc_impressions_spark = array();

						foreach ( $gsc_data['rows'] as $gsc_row ) {
							$gsc_c = isset( $gsc_row['clicks'] ) ? (int) $gsc_row['clicks'] : 0;
							$gsc_i = isset( $gsc_row['impressions'] ) ? (int) $gsc_row['impressions'] : 0;

							$gsc_clicks      += $gsc_c;
							$gsc_impressions += $gsc_i;
							$gsc_position    += isset( $gsc_row['position'] ) ? (float) $gsc_row['position'] : 0;

							$gsc_clicks_spark[]      = $gsc_c;
							$gsc_impressions_spark[] = $gsc_i;
						}

						$gsc_avg_position = $gsc_row_count > 0 ? $gsc_position / $gsc_row_count : 0;
						?>

						<div class="smac-metric-card" data-platform="gsc" data-metric="clicks">
							<span class="smac-metric-platform"><?php esc_html_e( 'GSC', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Clicks', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $gsc_clicks ) ); ?></span>
							<?php if ( count( $gsc_clicks_spark ) > 1 ) : ?>
								<div class="smac-sparkline" data-values="<?php echo esc_attr( wp_json_encode( $gsc_clicks_spark ) ); ?>" data-color="#2271b1"></div>
							<?php endif; ?>
						</div>

						<div class="smac-metric-card" data-platform="gsc" data-metric="impressions">
							<span class="smac-metric-platform"><?php esc_html_e( 'GSC', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Impressions', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format_i18n( $gsc_impressions ) ); ?></span>
							<?php if ( count( $gsc_impressions_spark ) > 1 ) : ?>
								<div class="smac-sparkline" data-values="<?php echo esc_attr( wp_json_encode( $gsc_impressions_spark ) ); ?>" data-color="#2271b1"></div>
							<?php endif; ?>
						</div>

						<div class="smac-metric-card" data-platform="gsc" data-metric="avg_position">
							<span class="smac-metric-platform"><?php esc_html_e( 'GSC', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-label"><?php esc_html_e( 'Avg Position', 'specflux-marketing-analytics-chat' ); ?></span>
							<span class="smac-metric-value"><?php echo esc_html( number_format( $gsc_avg_position, 1 ) ); ?></span>
						</div>

					<?php endif; ?>

				</div>

			<?php else : ?>
				<div class="smac-insights-empty">
					<span class="dashicons dashicons-chart-bar"></span>
					<p><?php esc_html_e( 'No cached data yet. Click Refresh to load metrics from your connected platforms.', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>
			<?php endif; ?>

		</div>
	<?php endif; ?>

	<?php
	/**
	 * Fires after the insights panel, before the status cards.
	 *
	 * Used by the pro add-on to inject additional dashboard cards
	 * (e.g. Quick Wins, AI Insights summary).
	 */
	do_action( 'specflux_mac_dashboard_cards_after_insights' );
	?>

	<div class="smac-status-cards">
		<h3><?php esc_html_e( 'Platform Status', 'specflux-marketing-analytics-chat' ); ?></h3>

		<div class="status-cards-grid">
			<!-- Microsoft Clarity -->
			<div class="status-card <?php echo esc_attr( $clarity_connected ? 'connected' : 'disconnected' ); ?>">
				<div class="status-icon">
					<span class="dashicons dashicons-chart-area"></span>
				</div>
				<h4><?php esc_html_e( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ); ?></h4>
				<p class="status-label">
					<?php
					if ( $clarity_connected ) {
						echo '<span class="status-badge connected">' . esc_html__( 'Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					} else {
						echo '<span class="status-badge disconnected">' . esc_html__( 'Not Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					}
					?>
				</p>
				<p class="status-description"><?php esc_html_e( 'Session recordings and heatmaps', 'specflux-marketing-analytics-chat' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=clarity' ) ); ?>" class="button">
					<?php esc_html_e( 'Configure', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>

			<!-- Google Analytics 4 -->
			<div class="status-card <?php echo esc_attr( $ga4_connected ? 'connected' : 'disconnected' ); ?>">
				<div class="status-icon">
					<span class="dashicons dashicons-chart-line"></span>
				</div>
				<h4><?php esc_html_e( 'Google Analytics 4', 'specflux-marketing-analytics-chat' ); ?></h4>
				<p class="status-label">
					<?php
					if ( $ga4_connected ) {
						echo '<span class="status-badge connected">' . esc_html__( 'Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					} else {
						echo '<span class="status-badge disconnected">' . esc_html__( 'Not Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					}
					?>
				</p>
				<p class="status-description"><?php esc_html_e( 'Traffic and user behavior metrics', 'specflux-marketing-analytics-chat' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=ga4' ) ); ?>" class="button">
					<?php esc_html_e( 'Configure', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>

			<!-- Google Search Console -->
			<div class="status-card <?php echo esc_attr( $gsc_connected ? 'connected' : 'disconnected' ); ?>">
				<div class="status-icon">
					<span class="dashicons dashicons-search"></span>
				</div>
				<h4><?php esc_html_e( 'Google Search Console', 'specflux-marketing-analytics-chat' ); ?></h4>
				<p class="status-label">
					<?php
					if ( $gsc_connected ) {
						echo '<span class="status-badge connected">' . esc_html__( 'Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					} else {
						echo '<span class="status-badge disconnected">' . esc_html__( 'Not Connected', 'specflux-marketing-analytics-chat' ) . '</span>';
					}
					?>
				</p>
				<p class="status-description"><?php esc_html_e( 'Search performance and indexing', 'specflux-marketing-analytics-chat' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections&tab=gsc' ) ); ?>" class="button">
					<?php esc_html_e( 'Configure', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</div>
		</div>
	</div>

	<?php
	// Connection depth prompt — suggest connecting more platforms.
	$mac_connection_promoter = new Connection_Promoter();
	$mac_connected_platforms = $mac_connection_promoter->get_connected_platforms();

	if ( count( $mac_connected_platforms ) < 3 ) :
		$mac_connection_promoter->render_connection_prompt();
	endif;
	?>

	<div class="smac-getting-started"<?php echo ! $onboarding_complete ? ' style="display:none;"' : ''; ?>>
		<h3><?php esc_html_e( 'Getting Started', 'specflux-marketing-analytics-chat' ); ?></h3>
		<ol>
			<li>
				<strong><?php esc_html_e( 'Connect Your Analytics', 'specflux-marketing-analytics-chat' ); ?></strong>
				<br>
				<?php esc_html_e( 'Connect at least one analytics platform using the ', 'specflux-marketing-analytics-chat' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-connections' ) ); ?>"><?php esc_html_e( 'Connections page', 'specflux-marketing-analytics-chat' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Start Chatting', 'specflux-marketing-analytics-chat' ); ?></strong>
				<br>
				<?php esc_html_e( 'Open the ', 'specflux-marketing-analytics-chat' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant' ) ); ?>"><?php esc_html_e( 'AI Assistant', 'specflux-marketing-analytics-chat' ); ?></a>
				<?php esc_html_e( ' and ask questions about your marketing data', 'specflux-marketing-analytics-chat' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Save Common Prompts', 'specflux-marketing-analytics-chat' ); ?></strong>
				<br>
				<?php esc_html_e( 'Create ', 'specflux-marketing-analytics-chat' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-prompts' ) ); ?>"><?php esc_html_e( 'custom prompts', 'specflux-marketing-analytics-chat' ); ?></a>
				<?php esc_html_e( ' for analyses you run frequently', 'specflux-marketing-analytics-chat' ); ?>
			</li>
		</ol>
	</div>

	<!-- Advanced: External AI Assistants -->
	<div class="smac-advanced">
		<details>
			<summary><h3><?php esc_html_e( 'Advanced: Connect External AI Assistants', 'specflux-marketing-analytics-chat' ); ?></h3></summary>
			<p><?php esc_html_e( 'You can also connect external AI assistants like Claude Desktop using the MCP endpoint below:', 'specflux-marketing-analytics-chat' ); ?></p>
			<div class="mcp-endpoint-box">
				<code class="mcp-endpoint"><?php echo esc_url( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code>
				<button type="button" class="button button-secondary copy-endpoint">
					<?php esc_html_e( 'Copy URL', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to documentation */
					esc_html__( 'Learn how to configure Claude Desktop and other MCP clients in our %s.', 'specflux-marketing-analytics-chat' ),
					'<a href="https://github.com/specflux/specflux-marketing-analytics-chat/blob/main/docs/setup-guides/" target="_blank">' . esc_html__( 'documentation', 'specflux-marketing-analytics-chat' ) . '</a>'
				);
				?>
			</p>
		</details>
	</div>
</div>
