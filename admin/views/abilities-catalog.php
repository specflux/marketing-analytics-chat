<?php
/**
 * Abilities Catalog Page Template
 *
 * Showcases all available MCP abilities for AI assistants.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the abilities list.
 *
 * Uses wp_get_registered_abilities() if available (WordPress 7.0+),
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
			'name'        => __( 'Get GA4 Metrics', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-metrics',
			'description' => __( 'Retrieve traffic, user, and conversion metrics from Google Analytics 4.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),
		array(
			'name'        => __( 'Get GA4 Realtime', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-realtime',
			'description' => __( 'Get real-time active users and pageviews from Google Analytics 4.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),
		array(
			'name'        => __( 'Get GA4 Audience', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-ga4-audience',
			'description' => __( 'Analyze audience demographics, devices, and geographic distribution.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'ga4',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-line',
		),

		// GSC abilities.
		array(
			'name'        => __( 'Get Search Performance', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-search-performance',
			'description' => __( 'Query search analytics for clicks, impressions, CTR, and average position.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Top Queries', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-top-queries',
			'description' => __( 'Retrieve top-performing search queries by clicks or impressions.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Page Performance', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-page-performance',
			'description' => __( 'Analyze individual page search performance and keyword rankings.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'gsc',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),

		// Bing Webmaster Tools abilities.
		array(
			'name'        => __( 'Get Bing Search Performance', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-bing-search-performance',
			'description' => __( 'Daily clicks and impressions from Bing and Microsoft Copilot search.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'bing',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Bing Top Queries', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-bing-top-queries',
			'description' => __( 'Top Bing search queries with clicks, impressions, CTR, and position.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'bing',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Bing Top Pages', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-bing-top-pages',
			'description' => __( 'Top pages by Bing clicks and impressions.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'bing',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),
		array(
			'name'        => __( 'Get Bing Crawl Stats', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-bing-crawl-stats',
			'description' => __( 'Bing crawl errors, indexed pages, and HTTP status counts.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'bing',
			'tier'        => 'free',
			'icon'        => 'dashicons-search',
		),

		// Matomo abilities.
		array(
			'name'        => __( 'Get Matomo Traffic', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-matomo-traffic',
			'description' => __( 'Matomo visits, actions, bounce rate and time on site with change vs the previous period.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'matomo',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Matomo Top Pages', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-matomo-top-pages',
			'description' => __( 'Most viewed pages in Matomo with time on page, bounce and exit rates.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'matomo',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Matomo Traffic Sources', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-matomo-sources',
			'description' => __( 'Matomo referrer types, websites, search engines and campaigns.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'matomo',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Matomo Goals', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-matomo-goals',
			'description' => __( 'Matomo goal conversions, revenue and conversion rates.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'matomo',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),

		// PageSpeed abilities.
		array(
			'name'        => __( 'Get Page Speed', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-page-speed',
			'description' => __( 'Lighthouse score, lab metrics, field data and top fixes for a page.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'pagespeed',
			'tier'        => 'free',
			'icon'        => 'dashicons-performance',
		),
		array(
			'name'        => __( 'Get Core Web Vitals', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-core-web-vitals',
			'description' => __( 'Real-user LCP, INP and CLS for a page and its origin with pass/fail.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'pagespeed',
			'tier'        => 'free',
			'icon'        => 'dashicons-performance',
		),
		array(
			'name'        => __( 'Audit Top Pages Speed', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/audit-top-pages-speed',
			'description' => __( 'Rank your most-visited pages by speed, slowest first.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'pagespeed',
			'tier'        => 'free',
			'icon'        => 'dashicons-performance',
		),

		// Clarity abilities.
		array(
			'name'        => __( 'Get Clarity Insights', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-clarity-insights',
			'description' => __( 'Retrieve session recordings, heatmap data, and user behavior insights.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'clarity',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Clarity Metrics', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-clarity-metrics',
			'description' => __( 'Get dead clicks, rage clicks, scroll depth, and engagement metrics.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'clarity',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),

		// Cross-platform abilities.
		array(
			'name'        => __( 'Cross-Platform Summary', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/cross-platform-summary',
			'description' => __( 'Get a unified summary combining data from all connected platforms.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'cross-platform',
			'tier'        => 'free',
			'icon'        => 'dashicons-networking',
		),
		array(
			'name'        => __( 'Compare Platforms', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/compare-platforms',
			'description' => __( 'Compare metrics across platforms to identify correlations and discrepancies.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'cross-platform',
			'tier'        => 'free',
			'icon'        => 'dashicons-networking',
		),

		// WordPress content and SEO abilities.
		array(
			'name'        => __( 'Get Content Inventory', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-content-inventory',
			'description' => __( 'List published posts and pages with dates, author, categories, word count, and comment count.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'wpcontent',
			'tier'        => 'free',
			'icon'        => 'dashicons-admin-page',
		),
		array(
			'name'        => __( 'Get Content Performance', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-content-performance',
			'description' => __( 'Join content with 28-day GA4 views and Search Console clicks, impressions, CTR, and position; find stale content to refresh.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'wpcontent',
			'tier'        => 'free',
			'icon'        => 'dashicons-admin-page',
		),
		array(
			'name'        => __( 'Get SEO Metadata', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-seo-metadata',
			'description' => __( 'Read SEO titles, meta descriptions, focus keywords, noindex, and canonicals from Yoast SEO, Rank Math, All in One SEO, or SEOPress.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'wpcontent',
			'tier'        => 'free',
			'icon'        => 'dashicons-admin-page',
		),
		array(
			'name'        => __( 'Find SEO Issues', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/find-seo-issues',
			'description' => __( 'Ranked list of SEO problems: missing or duplicate titles and descriptions, noindex on pages with traffic, and low CTR on high-impression pages.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'wpcontent',
			'tier'        => 'free',
			'icon'        => 'dashicons-admin-page',
		),
		array(
			'name'        => __( 'Get Form Entries', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-form-entries',
			'description' => __( 'Count form submissions per form and day or week with the change versus the previous period. Counts only, never names, emails or messages.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'forms',
			'tier'        => 'free',
			'icon'        => 'dashicons-feedback',
		),
		array(
			'name'        => __( 'Get Leads by Page', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-leads-by-page',
			'description' => __( 'Which pages produce leads: form entries per submission page joined with GA4 views to give a lead conversion rate.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'forms',
			'tier'        => 'free',
			'icon'        => 'dashicons-feedback',
		),
		array(
			'name'        => __( 'Get Lead Sources', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-lead-sources',
			'description' => __( 'Form entries by UTM source, medium and campaign found on the submission page URL.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'forms',
			'tier'        => 'free',
			'icon'        => 'dashicons-feedback',
		),

		// WooCommerce abilities (registered only when WooCommerce is active).
		array(
			'name'        => __( 'Get Sales by Source', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-sales-by-source',
			'description' => __( 'WooCommerce paid orders and revenue by channel, source, campaign, device or landing page, with the change versus the previous period. Totals only, never customer details.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'store',
			'tier'        => 'free',
			'icon'        => 'dashicons-cart',
		),
		array(
			'name'        => __( 'Get Store Funnel', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-store-funnel',
			'description' => __( 'Product views to purchase from GA4 shop events, or sessions to paid orders, or paid orders alone, whichever your data supports.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'store',
			'tier'        => 'free',
			'icon'        => 'dashicons-cart',
		),

		// GiveWP abilities (registered only when GiveWP is active).
		array(
			'name'        => __( 'Get Donations by Source', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-donations-by-source',
			'description' => __( 'GiveWP completed donations, donors, amount and recurring share by channel, source, campaign or form, per currency, with the change versus the previous period. Totals only, never donor details.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'store',
			'tier'        => 'free',
			'icon'        => 'dashicons-heart',
		),

		// Site analytics plugin abilities (registered only when Burst, Independent Analytics, or Koko Analytics has data).
		array(
			'name'        => __( 'Get Site Traffic', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-site-traffic',
			'description' => __( 'Visitors, pageviews, change vs the previous period and a daily series from Burst Statistics, Independent Analytics, or Koko Analytics, for sites without GA4.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'localstats',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Site Top Pages', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-site-top-pages',
			'description' => __( 'Most viewed pages with pageviews and visitors from the analytics plugin installed on this site.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'localstats',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),
		array(
			'name'        => __( 'Get Site Referrers', 'specflux-marketing-analytics-chat' ),
			'slug'        => 'marketing-analytics/get-site-referrers',
			'description' => __( 'Top traffic sources from the analytics plugin installed on this site.', 'specflux-marketing-analytics-chat' ),
			'category'    => 'localstats',
			'tier'        => 'free',
			'icon'        => 'dashicons-chart-area',
		),

	);
}

$mac_mcp_endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
?>

<div class="wrap smac-abilities-catalog">
	<h1><?php esc_html_e( 'MCP Abilities Catalog', 'specflux-marketing-analytics-chat' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'All the analytics abilities available to your AI assistants via the Model Context Protocol. No competitor has MCP-native architecture.', 'specflux-marketing-analytics-chat' ); ?>
	</p>

	<!-- Category filter -->
	<div class="smac-abilities-filter">
		<button type="button" class="smac-abilities-filter-btn active" data-filter="all">
			<?php esc_html_e( 'All', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="ga4">
			<?php esc_html_e( 'Google Analytics', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="gsc">
			<?php esc_html_e( 'Search Console', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="bing">
			<?php esc_html_e( 'Bing', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="matomo">
			<?php esc_html_e( 'Matomo', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="clarity">
			<?php esc_html_e( 'Clarity', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="pagespeed">
			<?php esc_html_e( 'PageSpeed', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="cross-platform">
			<?php esc_html_e( 'Cross-Platform', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="wpcontent">
			<?php esc_html_e( 'Content & SEO', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="forms">
			<?php esc_html_e( 'Form entries', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="store">
			<?php esc_html_e( 'WooCommerce', 'specflux-marketing-analytics-chat' ); ?>
		</button>
		<button type="button" class="smac-abilities-filter-btn" data-filter="localstats">
			<?php esc_html_e( 'Site analytics plugin', 'specflux-marketing-analytics-chat' ); ?>
		</button>
	</div>

	<!-- Abilities grid -->
	<div class="smac-abilities-grid">
		<?php foreach ( $mac_abilities as $mac_ability ) : ?>
			<?php
			$mac_category = isset( $mac_ability['category'] ) ? $mac_ability['category'] : 'other';
			$mac_tier     = isset( $mac_ability['tier'] ) ? $mac_ability['tier'] : 'free';
			$mac_icon     = isset( $mac_ability['icon'] ) ? $mac_ability['icon'] : 'dashicons-admin-generic';
			?>
			<div class="smac-ability-card" data-category="<?php echo esc_attr( $mac_category ); ?>">
				<div class="smac-ability-icon">
					<span class="dashicons <?php echo esc_attr( $mac_icon ); ?>"></span>
				</div>
				<h3><?php echo esc_html( $mac_ability['name'] ); ?></h3>
				<p><?php echo esc_html( $mac_ability['description'] ); ?></p>
				<div class="smac-ability-meta">
					<span class="smac-ability-status free">
						<?php esc_html_e( 'Free', 'specflux-marketing-analytics-chat' ); ?>
					</span>
					<code><?php echo esc_html( $mac_ability['slug'] ); ?></code>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- MCP endpoint info -->
	<div class="smac-abilities-mcp-info">
		<h3><?php esc_html_e( 'Connect Your AI Assistant', 'specflux-marketing-analytics-chat' ); ?></h3>
		<p><?php esc_html_e( 'Any MCP-compatible AI assistant can use these abilities. Add this endpoint to your assistant configuration:', 'specflux-marketing-analytics-chat' ); ?></p>
		<div class="mcp-endpoint-box">
			<code class="mcp-endpoint"><?php echo esc_url( $mac_mcp_endpoint ); ?></code>
			<button type="button" class="button button-secondary copy-endpoint">
				<?php esc_html_e( 'Copy URL', 'specflux-marketing-analytics-chat' ); ?>
			</button>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to documentation */
				esc_html__( 'Works with Claude Desktop, ChatGPT, Cursor, and any MCP-compatible client. See our %s for setup guides.', 'specflux-marketing-analytics-chat' ),
				'<a href="https://github.com/specflux/marketing-analytics-chat#configuration" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'specflux-marketing-analytics-chat' ) . '</a>'
			);
			?>
		</p>
	</div>
</div>
