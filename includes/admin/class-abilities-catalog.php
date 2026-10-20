<?php
/**
 * Abilities catalog data
 *
 * The list of analytics abilities shown on the Abilities screen, plus the grouping
 * and summary logic. Pure data and string building, so it can be tested without
 * rendering the view.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abilities catalog: items, categories, grouping and counts.
 */
class Abilities_Catalog {

	/**
	 * Category slug => label, in display order. Unknown categories fall into "other".
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			'ga4'            => __( 'Google Analytics', 'specflux-marketing-analytics-chat' ),
			'gsc'            => __( 'Search Console', 'specflux-marketing-analytics-chat' ),
			'bing'           => __( 'Bing', 'specflux-marketing-analytics-chat' ),
			'matomo'         => __( 'Matomo', 'specflux-marketing-analytics-chat' ),
			'clarity'        => __( 'Clarity', 'specflux-marketing-analytics-chat' ),
			'pagespeed'      => __( 'PageSpeed', 'specflux-marketing-analytics-chat' ),
			'cross-platform' => __( 'Cross-Platform', 'specflux-marketing-analytics-chat' ),
			'wpcontent'      => __( 'Content & SEO', 'specflux-marketing-analytics-chat' ),
			'forms'          => __( 'Form entries', 'specflux-marketing-analytics-chat' ),
			'store'          => __( 'Store', 'specflux-marketing-analytics-chat' ),
			'localstats'     => __( 'Site analytics plugin', 'specflux-marketing-analytics-chat' ),
			'other'          => __( 'Other', 'specflux-marketing-analytics-chat' ),
		);
	}

	/**
	 * Kit icon name for a category.
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	public static function category_icon( $slug ) {
		$icons = array(
			'ga4'            => 'chart',
			'gsc'            => 'magnifying-glass',
			'bing'           => 'magnifying-glass',
			'matomo'         => 'chart',
			'clarity'        => 'user',
			'pagespeed'      => 'clock',
			'cross-platform' => 'flow',
			'wpcontent'      => 'file-text',
			'forms'          => 'chat',
			'store'          => 'storefront',
			'localstats'     => 'table',
		);

		return isset( $icons[ $slug ] ) ? $icons[ $slug ] : 'plug';
	}

	/**
	 * The ability list: the Abilities API when it exposes one, else the built-in list.
	 *
	 * @return array<int,array<string,string>> Each: name, slug, description, category, tier.
	 */
	public static function items() {
		$items = array();

		if ( function_exists( 'wp_get_registered_abilities' ) ) {
			$registered = wp_get_registered_abilities();
			if ( is_array( $registered ) ) {
				foreach ( $registered as $ability ) {
					$items[] = array(
						'name'        => isset( $ability['name'] ) ? $ability['name'] : '',
						'slug'        => isset( $ability['slug'] ) ? $ability['slug'] : '',
						'description' => isset( $ability['description'] ) ? $ability['description'] : '',
						'category'    => isset( $ability['category'] ) ? $ability['category'] : 'other',
						'tier'        => isset( $ability['tier'] ) ? $ability['tier'] : 'free',
					);
				}
			}
		}

		if ( empty( $items ) ) {
			$items = self::builtin();
		}

		return self::normalize( $items );
	}

	/**
	 * Normalise tier and category on every item.
	 *
	 * @param array<int,array<string,string>> $items Raw items.
	 * @return array<int,array<string,string>>
	 */
	public static function normalize( array $items ) {
		$known = self::categories();
		$out   = array();

		foreach ( $items as $item ) {
			$category = isset( $item['category'] ) ? (string) $item['category'] : 'other';
			$tier     = isset( $item['tier'] ) ? strtolower( (string) $item['tier'] ) : 'free';

			$item['category'] = isset( $known[ $category ] ) ? $category : 'other';
			$item['tier']     = in_array( $tier, array( 'pro', 'premium' ), true ) ? 'pro' : 'free';
			$out[]            = $item;
		}

		return $out;
	}

	/**
	 * Group items by category, in category order. Empty categories are left out.
	 *
	 * @param array<int,array<string,string>> $items Normalised items.
	 * @return array<int,array{slug:string,label:string,items:array<int,array<string,string>>,free:int,pro:int}>
	 */
	public static function group( array $items ) {
		$labels  = self::categories();
		$by_slug = array();

		foreach ( $items as $item ) {
			$by_slug[ $item['category'] ][] = $item;
		}

		$groups = array();
		foreach ( $labels as $slug => $label ) {
			if ( empty( $by_slug[ $slug ] ) ) {
				continue;
			}
			$tiers    = self::tier_counts( $by_slug[ $slug ] );
			$groups[] = array(
				'slug'  => $slug,
				'label' => $label,
				'items' => $by_slug[ $slug ],
				'free'  => $tiers['free'],
				'pro'   => $tiers['pro'],
			);
		}

		return $groups;
	}

	/**
	 * Count items per tier.
	 *
	 * @param array<int,array<string,string>> $items Normalised items.
	 * @return array{free:int,pro:int}
	 */
	public static function tier_counts( array $items ) {
		$counts = array(
			'free' => 0,
			'pro'  => 0,
		);

		foreach ( $items as $item ) {
			++$counts[ 'pro' === $item['tier'] ? 'pro' : 'free' ];
		}

		return $counts;
	}

	/**
	 * Group summary line, for example "3 abilities · 2 free, 1 pro".
	 *
	 * The tier split only appears when the catalog has both tiers, so a free-only
	 * catalog reads "3 abilities".
	 *
	 * @param int  $total     Abilities in the group.
	 * @param int  $free      Free abilities in the group.
	 * @param int  $pro       Pro abilities in the group.
	 * @param bool $show_tier Whether to append the tier split.
	 * @return string
	 */
	public static function group_meta( $total, $free, $pro, $show_tier ) {
		/* translators: %d: number of abilities */
		$text = sprintf( _n( '%d ability', '%d abilities', (int) $total, 'specflux-marketing-analytics-chat' ), (int) $total );

		if ( $show_tier ) {
			/* translators: 1: number of free abilities, 2: number of pro abilities */
			$text .= ' · ' . sprintf( __( '%1$d free, %2$d pro', 'specflux-marketing-analytics-chat' ), (int) $free, (int) $pro );
		}

		return $text;
	}

	/**
	 * The built-in list, used while the Abilities API does not list ours.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function builtin() {
		return array(
			// GA4 abilities.
			array(
				'name'        => __( 'Get GA4 Metrics', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-ga4-metrics',
				'description' => __( 'Retrieve traffic, user, and conversion metrics from Google Analytics 4.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'ga4',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get GA4 Realtime', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-ga4-realtime',
				'description' => __( 'Get real-time active users and pageviews from Google Analytics 4.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'ga4',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get GA4 Audience', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-ga4-audience',
				'description' => __( 'Analyze audience demographics, devices, and geographic distribution.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'ga4',
				'tier'        => 'free',
			),

			// GSC abilities.
			array(
				'name'        => __( 'Get Search Performance', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-search-performance',
				'description' => __( 'Query search analytics for clicks, impressions, CTR, and average position.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'gsc',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Top Queries', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-top-queries',
				'description' => __( 'Retrieve top-performing search queries by clicks or impressions.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'gsc',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Page Performance', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-page-performance',
				'description' => __( 'Analyze individual page search performance and keyword rankings.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'gsc',
				'tier'        => 'free',
			),

			// Bing Webmaster Tools abilities.
			array(
				'name'        => __( 'Get Bing Search Performance', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-bing-search-performance',
				'description' => __( 'Daily clicks and impressions from Bing and Microsoft Copilot search.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'bing',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Bing Top Queries', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-bing-top-queries',
				'description' => __( 'Top Bing search queries with clicks, impressions, CTR, and position.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'bing',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Bing Top Pages', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-bing-top-pages',
				'description' => __( 'Top pages by Bing clicks and impressions.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'bing',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Bing Crawl Stats', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-bing-crawl-stats',
				'description' => __( 'Bing crawl errors, indexed pages, and HTTP status counts.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'bing',
				'tier'        => 'free',
			),

			// Matomo abilities.
			array(
				'name'        => __( 'Get Matomo Traffic', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-matomo-traffic',
				'description' => __( 'Matomo visits, actions, bounce rate and time on site with change vs the previous period.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'matomo',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Matomo Top Pages', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-matomo-top-pages',
				'description' => __( 'Most viewed pages in Matomo with time on page, bounce and exit rates.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'matomo',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Matomo Traffic Sources', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-matomo-sources',
				'description' => __( 'Matomo referrer types, websites, search engines and campaigns.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'matomo',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Matomo Goals', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-matomo-goals',
				'description' => __( 'Matomo goal conversions, revenue and conversion rates.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'matomo',
				'tier'        => 'free',
			),

			// PageSpeed abilities.
			array(
				'name'        => __( 'Get Page Speed', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-page-speed',
				'description' => __( 'Lighthouse score, lab metrics, field data and top fixes for a page.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'pagespeed',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Core Web Vitals', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-core-web-vitals',
				'description' => __( 'Real-user LCP, INP and CLS for a page and its origin with pass/fail.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'pagespeed',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Audit Top Pages Speed', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/audit-top-pages-speed',
				'description' => __( 'Rank your most-visited pages by speed, slowest first.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'pagespeed',
				'tier'        => 'free',
			),

			// Clarity abilities.
			array(
				'name'        => __( 'Get Clarity Insights', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-clarity-insights',
				'description' => __( 'Retrieve session recordings, heatmap data, and user behavior insights.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'clarity',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Clarity Metrics', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-clarity-metrics',
				'description' => __( 'Get dead clicks, rage clicks, scroll depth, and engagement metrics.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'clarity',
				'tier'        => 'free',
			),

			// Cross-platform abilities.
			array(
				'name'        => __( 'Cross-Platform Summary', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/cross-platform-summary',
				'description' => __( 'Get a unified summary combining data from all connected platforms.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'cross-platform',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Compare Platforms', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/compare-platforms',
				'description' => __( 'Compare metrics across platforms to identify correlations and discrepancies.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'cross-platform',
				'tier'        => 'free',
			),

			// WordPress content and SEO abilities.
			array(
				'name'        => __( 'Get Content Inventory', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-content-inventory',
				'description' => __( 'List published posts and pages with dates, author, categories, word count, and comment count.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'wpcontent',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Content Performance', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-content-performance',
				'description' => __( 'Join content with 28-day GA4 views and Search Console clicks, impressions, CTR, and position; find stale content to refresh.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'wpcontent',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get SEO Metadata', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-seo-metadata',
				'description' => __( 'Read SEO titles, meta descriptions, focus keywords, noindex, and canonicals from Yoast SEO, Rank Math, All in One SEO, or SEOPress.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'wpcontent',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Find SEO Issues', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/find-seo-issues',
				'description' => __( 'Ranked list of SEO problems: missing or duplicate titles and descriptions, noindex on pages with traffic, and low CTR on high-impression pages.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'wpcontent',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Form Entries', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-form-entries',
				'description' => __( 'Count form submissions per form and day or week with the change versus the previous period. Counts only, never names, emails or messages.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'forms',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Leads by Page', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-leads-by-page',
				'description' => __( 'Which pages produce leads: form entries per submission page joined with GA4 views to give a lead conversion rate.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'forms',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Lead Sources', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-lead-sources',
				'description' => __( 'Form entries by UTM source, medium and campaign found on the submission page URL.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'forms',
				'tier'        => 'free',
			),

			// Store abilities (registered only when WooCommerce or Easy Digital Downloads is active).
			array(
				'name'        => __( 'Get Sales by Source', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-sales-by-source',
				'description' => __( 'WooCommerce or Easy Digital Downloads paid orders and revenue by channel, source, campaign, device or landing page, with the change versus the previous period (and top downloads for Easy Digital Downloads). Totals only, never customer details.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'store',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Store Funnel', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-store-funnel',
				'description' => __( 'Product views to purchase from GA4 shop events, or sessions to paid orders, or paid orders alone, whichever your data supports.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'store',
				'tier'        => 'free',
			),

			// GiveWP abilities (registered only when GiveWP is active).
			array(
				'name'        => __( 'Get Donations by Source', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-donations-by-source',
				'description' => __( 'GiveWP completed donations, donors, amount and recurring share by channel, source, campaign or form, per currency, with the change versus the previous period. Totals only, never donor details.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'store',
				'tier'        => 'free',
			),

			// Site analytics plugin abilities (registered only when Burst, Independent Analytics, Koko Analytics, or a WordPress.com-connected Jetpack Stats has data).
			array(
				'name'        => __( 'Get Site Traffic', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-site-traffic',
				'description' => __( 'Visitors, pageviews, change vs the previous period and a daily series from Burst Statistics, Independent Analytics, Koko Analytics, or Jetpack Stats, for sites without GA4.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'localstats',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Site Top Pages', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-site-top-pages',
				'description' => __( 'Most viewed pages with pageviews and visitors from the analytics plugin installed on this site.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'localstats',
				'tier'        => 'free',
			),
			array(
				'name'        => __( 'Get Site Referrers', 'specflux-marketing-analytics-chat' ),
				'slug'        => 'marketing-analytics/get-site-referrers',
				'description' => __( 'Top traffic sources from the analytics plugin installed on this site.', 'specflux-marketing-analytics-chat' ),
				'category'    => 'localstats',
				'tier'        => 'free',
			),

		);
	}
}
