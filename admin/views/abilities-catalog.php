<?php
/**
 * Abilities Catalog Page Template
 *
 * Showcases all available MCP abilities for AI assistants.
 *
 * @package Marketing_Analytics_MCP
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the abilities list.
 *
 * Uses wp_get_registered_abilities() if available (WordPress 6.9+),
 * otherwise falls back to a static list of known abilities.
 */
$mac_abilities = array();

if ( function_exists( 'wp_get_registered_abilities' ) ) {
	$registered = wp_get_registered_abilities();
	if ( is_array( $registered ) ) {
		foreach ( $registered as $ability ) {
			$mac_abilities[] = array(
				'name'        => isset( $ability['name'] ) ? $ability['name'] : '',
				'slug'        => isset( $ability['slug'] ) ? $ability['slug'] : '',
				'description' => isset( $ability['description'] ) ? $ability['description'] : '',
				'category'    => isset( $ability['category'] ) ? $ability['category'] : 'other',
				'tier'        => isset( $ability['tier'] ) ? $ability['tier'] : 'free',
			);
		}
	}
}

// Fallback: static list if Abilities API not available or returned nothing.
if ( empty( $mac_abilities ) ) {
	$mac_abilities = array(
		// GA4 abilities.
		array(
			'name'        => __( 'Get GA4 Metrics', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-metrics',
			'description' => __( 'Retrieve traffic, user, and conversion metrics from Google Analytics 4.', 'marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),
		array(
			'name'        => __( 'Get GA4 Realtime', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-realtime',
			'description' => __( 'Get real-time active users and pageviews from Google Analytics 4.', 'marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),
		array(
			'name'        => __( 'Get GA4 Audience', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-audience',
			'description' => __( 'Analyze audience demographics, devices, and geographic distribution.', 'marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),

		// GSC abilities.
		array(
			'name'        => __( 'Get Search Performance', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-search-performance',
			'description' => __( 'Query search analytics for clicks, impressions, CTR, and average position.', 'marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Top Queries', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-top-queries',
			'description' => __( 'Retrieve top-performing search queries by clicks or impressions.', 'marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Page Performance', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-page-performance',
			'description' => __( 'Analyze individual page search performance and keyword rankings.', 'marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),

		// Clarity abilities.
		array(
			'name'        => __( 'Get Clarity Insights', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-clarity-insights',
			'description' => __( 'Retrieve session recordings, heatmap data, and user behavior insights.', 'marketing-analytics-chat' ),
			'category'    => 'clarity',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Clarity Metrics', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-clarity-metrics',
			'description' => __( 'Get dead clicks, rage clicks, scroll depth, and engagement metrics.', 'marketing-analytics-chat' ),
			'category'    => 'clarity',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),

		// Cross-platform abilities.
		array(
			'name'        => __( 'Cross-Platform Summary', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/cross-platform-summary',
			'description' => __( 'Get a unified summary combining data from all connected platforms.', 'marketing-analytics-chat' ),
			'category'    => 'cross-platform',
			'tier'        => 'free',
			'icon'        => 'dashicons-networking',
		),
		array(
			'name'        => __( 'Compare Platforms', 'marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/compare-platforms',
			'description' => __( 'Compare metrics across platforms to identify correlations and discrepancies.', 'marketing-analytics-chat' ),
			'category'    => 'cross-platform',
			'tier'        => 'free',
			'icon'        => 'dashicons-networking',
		),

	);
}

$mac_mcp_endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
?>

<div class="wrap mac-abilities-catalog">
	<h1><?php esc_html_e( 'MCP Abilities Catalog', 'marketing-analytics-chat' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'All the analytics abilities available to your AI assistants via the Model Context Protocol. No competitor has MCP-native architecture.', 'marketing-analytics-chat' ); ?>
	</p>

	<!-- Category filter -->
	<div class="mac-abilities-filter">
		<button type="button" class="mac-abilities-filter-btn active" data-filter="all">
			<?php esc_html_e( 'All', 'marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="mac-abilities-filter-btn" data-filter="ga4">
			<?php esc_html_e( 'Google Analytics', 'marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="mac-abilities-filter-btn" data-filter="gsc">
			<?php esc_html_e( 'Search Console', 'marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="mac-abilities-filter-btn" data-filter="clarity">
			<?php esc_html_e( 'Clarity', 'marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="mac-abilities-filter-btn" data-filter="cross-platform">
			<?php esc_html_e( 'Cross-Platform', 'marketing-analytics-chat' ); ?>
		</button>
	</div>

	<!-- Abilities grid -->
	<div class="mac-abilities-grid">
		<?php foreach ( $mac_abilities as $mac_ability ) : ?>
			<?php
			$mac_category = isset( $mac_ability['category'] ) ? $mac_ability['category'] : 'other';
			$mac_tier     = isset( $mac_ability['tier'] ) ? $mac_ability['tier'] : 'free';
			$mac_icon     = isset( $mac_ability['icon'] ) ? $mac_ability['icon'] : 'dashicons-admin-generic';
			?>
			<div class="mac-ability-card" data-category="<?php echo esc_attr( $mac_category ); ?>">
				<div class="mac-ability-icon">
					<span class="dashicons <?php echo esc_attr( $mac_icon ); ?>"></span>
				</div>
				<h3><?php echo esc_html( $mac_ability['name'] ); ?></h3>
				<p><?php echo esc_html( $mac_ability['description'] ); ?></p>
				<div class="mac-ability-meta">
					<span class="mac-ability-status free">
						<?php esc_html_e( 'Free', 'marketing-analytics-chat' ); ?>
					</span>
					<code><?php echo esc_html( $mac_ability['slug'] ); ?></code>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- MCP endpoint info -->
	<div class="mac-abilities-mcp-info">
		<h3><?php esc_html_e( 'Connect Your AI Assistant', 'marketing-analytics-chat' ); ?></h3>
		<p><?php esc_html_e( 'Any MCP-compatible AI assistant can use these abilities. Add this endpoint to your assistant configuration:', 'marketing-analytics-chat' ); ?></p>
		<div class="mcp-endpoint-box">
			<code class="mcp-endpoint"><?php echo esc_url( $mac_mcp_endpoint ); ?></code>
			<button type="button" class="button button-secondary copy-endpoint">
				<?php esc_html_e( 'Copy URL', 'marketing-analytics-chat' ); ?>
			</button>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to documentation */
				esc_html__( 'Works with Claude Desktop, ChatGPT, Cursor, and any MCP-compatible client. See our %s for setup guides.', 'marketing-analytics-chat' ),
				'<a href="https://github.com/stephen1204paul/marketing-analytics-chat/blob/main/docs/setup-guides/" target="_blank">' . esc_html__( 'documentation', 'marketing-analytics-chat' ) . '</a>'
			);
			?>
		</p>
	</div>
</div>
