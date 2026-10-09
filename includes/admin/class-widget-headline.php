<?php
/**
 * Dashboard widget headline numbers
 *
 * Builds and renders the "This week" row of the WordPress dashboard widget from
 * the weekly summary collector, so the widget and the weekly email agree.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\Content\Seo_Issue_Summary;
use Specflux_Marketing_Analytics\Reports\Weekly_Prompt_Builder;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Headline numbers for the dashboard widget.
 *
 * Each item looks like:
 *
 *     array(
 *         'platform'   => 'ga4|gsc|clarity',
 *         'label'      => string,
 *         'value'      => float|null,  // null renders as a dash.
 *         'change'     => float|null,  // percent vs previous 7 days, null when unknown.
 *         'has_change' => bool,        // false when the source has no previous window.
 *         'period'     => string,      // optional note such as "Last 3 days".
 *     )
 */
class Widget_Headline {

	/**
	 * Transient holding the widget payload.
	 */
	const TRANSIENT = 'specflux_mac_widget_data';

	/**
	 * Transient holding the WooCommerce sales row (a "none" marker when the week has no sales).
	 */
	const SALES_TRANSIENT = 'specflux_mac_widget_sales';

	/**
	 * Transient lifetime in seconds (a glance view; the API clients cache too).
	 *
	 * @return int
	 */
	public static function ttl() {
		return 3 * HOUR_IN_SECONDS;
	}

	/**
	 * Build the headline items for every connected platform.
	 *
	 * Failures are caught per platform: a failed platform keeps its slot with a
	 * null value so the widget can show a dash.
	 *
	 * @param Weekly_Summary|null $summary Collector (defaults to live clients).
	 * @return array[] Items in weekly email order.
	 */
	public static function build( ?Weekly_Summary $summary = null ) {
		$summary   = $summary ?? new Weekly_Summary();
		$connected = array();
		$context   = array();

		try {
			$connected = $summary->get_connected_platforms();
		} catch ( \Throwable $e ) {
			$connected = array();
		}

		if ( ! empty( $connected ) ) {
			try {
				$context = $summary->collect();
			} catch ( \Throwable $e ) {
				$context = array();
			}
		}

		$items = array();

		foreach ( $connected as $platform ) {
			$entry = $context['platforms'][ $platform ] ?? array();
			$ok    = is_array( $entry ) && 'ok' === ( $entry['status'] ?? '' ) && is_array( $entry['data'] ?? null );
			$item  = self::item( $platform, $ok ? $entry['data'] : array() );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Rebuild the widget payload, store it and return the AJAX response data.
	 *
	 * The SEO issue summary rides along: it reuses its own 12 hour cache and only
	 * scans when that cache is cold, and a failed scan never fails the refresh.
	 *
	 * @param Weekly_Summary|null    $summary Collector (defaults to live clients).
	 * @param Seo_Issue_Summary|null $seo     SEO summary service (defaults to the real scan).
	 * @return array
	 */
	public static function refresh( ?Weekly_Summary $summary = null, ?Seo_Issue_Summary $seo = null ) {
		$summary = $summary ?? new Weekly_Summary();
		$headline = self::build( $summary );
		$payload  = array(
			'headline'     => $headline,
			'generated_at' => time(),
		);

		set_transient( self::TRANSIENT, $payload, self::ttl() );

		try {
			$seo_summary = ( $seo ?? new Seo_Issue_Summary() )->get_or_refresh();
		} catch ( \Throwable $e ) {
			$seo_summary = null;
		}

		$sales = self::refresh_sales( $summary );

		return array(
			'message'       => 'Widget data refreshed successfully.',
			'headline'      => $headline,
			'headline_html' => self::render( $headline ),
			'seo_html'      => self::render_seo( $seo_summary ),
			'sales_html'    => self::render_sales( $sales ),
		);
	}

	/**
	 * Rebuild and store the WooCommerce sales row. Failures leave the row empty.
	 *
	 * @param Weekly_Summary $summary Collector.
	 * @return array|null Sales data, or null when WooCommerce is off or there are no paid orders.
	 */
	public static function refresh_sales( Weekly_Summary $summary ) {
		try {
			$ranges = $summary->get_ranges();
			$sales  = $summary->collect_sales( $ranges['ga4'] );
		} catch ( \Throwable $e ) {
			$sales = null;
		}

		set_transient( self::SALES_TRANSIENT, null === $sales ? 'none' : $sales, self::ttl() );

		return $sales;
	}

	/**
	 * Cached sales row: an array, 'none' (nothing to show) or null (not cached yet).
	 *
	 * @return array|string|null
	 */
	public static function cached_sales() {
		$stored = get_transient( self::SALES_TRANSIENT );

		if ( is_array( $stored ) || 'none' === $stored ) {
			return $stored;
		}

		return null;
	}

	/**
	 * The "Sales:" row: revenue this week against last, and the top channel.
	 *
	 * Reads data that was already built; it never queries orders.
	 *
	 * @param array|string|null $sales Sales data from collect_sales(), 'none' or null.
	 * @return string HTML (escaped).
	 */
	public static function render_sales( $sales ) {
		if ( ! is_array( $sales ) ) {
			return '';
		}

		$top = is_array( $sales['channels'][0] ?? null ) ? $sales['channels'][0] : null;

		ob_start();
		?>
		<p class="smac-widget-sales-line">
			<strong>
				<?php
				printf(
					/* translators: %s: revenue with currency code, e.g. "1,234.50 USD" */
					esc_html__( 'Sales: %s', 'specflux-marketing-analytics-chat' ),
					esc_html( number_format_i18n( (float) $sales['revenue'], 2 ) . ' ' . $sales['currency'] )
				);
				?>
			</strong>
			<?php echo self::format_change( $sales['change'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_change(). ?>
			<span class="smac-widget-stat-period"><?php esc_html_e( 'vs last week', 'specflux-marketing-analytics-chat' ); ?></span>
		</p>
		<?php if ( null !== $top ) : ?>
			<p class="smac-widget-sales-top">
				<?php
				printf(
					/* translators: 1: channel such as "Organic Search", 2: share of revenue such as "42.5" */
					esc_html__( 'Top channel: %1$s (%2$s%% of revenue)', 'specflux-marketing-analytics-chat' ),
					esc_html( (string) $top['label'] ),
					esc_html( number_format_i18n( (float) $top['share'], 1 ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cached headline items, or null when nothing usable is stored.
	 *
	 * @return array[]|null
	 */
	public static function cached() {
		$stored = get_transient( self::TRANSIENT );

		if ( is_array( $stored ) && isset( $stored['headline'] ) && is_array( $stored['headline'] ) ) {
			return $stored['headline'];
		}

		return null;
	}

	/**
	 * Turn one platform's weekly summary data into a headline item.
	 *
	 * @param string $platform Platform key.
	 * @param array  $data     Platform data from Weekly_Summary::collect() (empty on failure).
	 * @return array|null Item, or null for an unknown platform.
	 */
	private static function item( $platform, array $data ) {
		switch ( $platform ) {
			case 'ga4':
				$item = array(
					'platform'   => 'ga4',
					'label'      => __( 'GA4 sessions', 'specflux-marketing-analytics-chat' ),
					'has_change' => true,
					'period'     => '',
				);
				$row  = self::find_metric( $data, 'sessions' );
				break;
			case 'gsc':
				$item = array(
					'platform'   => 'gsc',
					'label'      => __( 'Search clicks', 'specflux-marketing-analytics-chat' ),
					'has_change' => true,
					'period'     => '',
				);
				$row  = self::find_metric( $data, 'clicks' );
				break;
			case 'clarity':
				// Clarity's export window is 3 days with no previous window, so no change.
				$item = array(
					'platform'   => 'clarity',
					'label'      => __( 'Clarity sessions', 'specflux-marketing-analytics-chat' ),
					'has_change' => false,
					'period'     => __( 'Last 3 days', 'specflux-marketing-analytics-chat' ),
				);
				$row  = null;
				foreach ( (array) ( $data['stats'] ?? array() ) as $stat ) {
					if ( is_array( $stat ) && 'sessions' === ( $stat['key'] ?? '' ) && isset( $stat['raw'] ) ) {
						$row = array(
							'current' => (float) $stat['raw'],
							'change'  => null,
						);
						break;
					}
				}
				break;
			default:
				return null;
		}

		$item['value']  = null === $row ? null : (float) $row['current'];
		$item['change'] = ( null === $row || ! $item['has_change'] || null === ( $row['change'] ?? null ) ) ? null : (float) $row['change'];

		return $item;
	}

	/**
	 * Find a metric row by key.
	 *
	 * @param array  $data Platform data containing a metrics list.
	 * @param string $key  Metric key.
	 * @return array|null Row with current and change, or null when absent.
	 */
	private static function find_metric( array $data, $key ) {
		foreach ( (array) ( $data['metrics'] ?? array() ) as $metric ) {
			if ( is_array( $metric ) && ( $metric['key'] ?? '' ) === $key && isset( $metric['current'] ) ) {
				return array(
					'current' => (float) $metric['current'],
					'change'  => $metric['change'] ?? null,
				);
			}
		}

		return null;
	}

	/**
	 * Arrow and percentage markup for a change.
	 *
	 * @param float|null $change Percent change, null when there is no baseline.
	 * @return string HTML (escaped).
	 */
	public static function format_change( $change ) {
		if ( null === $change ) {
			return '<span class="smac-delta is-flat">&mdash;</span>';
		}

		if ( abs( $change ) < 0.05 ) {
			return '<span class="smac-delta is-flat">&ndash; 0%</span>';
		}

		$is_up = $change > 0;

		return sprintf(
			'<span class="smac-delta %1$s">%2$s %3$s%%</span>',
			$is_up ? 'is-up' : 'is-down',
			$is_up ? '&#9650;' : '&#9660;',
			esc_html( number_format_i18n( abs( $change ), 1 ) )
		);
	}

	/**
	 * Render the "This week" headline row (or the empty state when nothing is connected).
	 *
	 * @param array[] $items Headline items.
	 * @return string HTML (escaped).
	 */
	public static function render( array $items ) {
		if ( empty( $items ) ) {
			return self::render_empty_state();
		}

		ob_start();
		?>
		<h4 class="smac-widget-heading"><?php esc_html_e( 'This week', 'specflux-marketing-analytics-chat' ); ?></h4>
		<div class="smac-widget-stats">
			<?php foreach ( $items as $item ) : ?>
				<div class="smac-widget-stat">
					<span class="smac-widget-stat-label"><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
					<?php if ( isset( $item['value'] ) && null !== $item['value'] ) : ?>
						<span class="smac-widget-stat-value"><?php echo esc_html( number_format_i18n( (float) $item['value'] ) ); ?></span>
						<?php if ( ! empty( $item['has_change'] ) ) : ?>
							<?php echo self::format_change( $item['change'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_change(). ?>
						<?php endif; ?>
					<?php else : ?>
						<span class="smac-widget-stat-value">&mdash;</span>
					<?php endif; ?>
					<?php if ( ! empty( $item['period'] ) ) : ?>
						<span class="smac-widget-stat-period"><?php echo esc_html( $item['period'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The "SEO: N issues to fix" row.
	 *
	 * Reads a summary that was already built; it never scans. Returns an empty
	 * string when there is nothing to show (cold cache or no pages scanned).
	 *
	 * @param array|null $summary Seo_Issue_Summary data, or null when not cached.
	 * @return string HTML (escaped).
	 */
	public static function render_seo( ?array $summary ) {
		if ( null === $summary || (int) ( $summary['pages_scanned'] ?? 0 ) < 1 ) {
			return '';
		}

		$total  = (int) ( $summary['total'] ?? 0 );
		$top    = is_array( $summary['top'][0] ?? null ) ? $summary['top'][0] : null;
		$plugin = ! empty( $summary['seo_plugin'] );

		ob_start();
		?>
		<p class="smac-widget-seo-line">
			<?php if ( $total > 0 ) : ?>
				<strong>
					<?php
					printf(
						/* translators: %s: number of SEO issues */
						esc_html( _n( 'SEO: %s issue to fix', 'SEO: %s issues to fix', $total, 'specflux-marketing-analytics-chat' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</strong>
			<?php else : ?>
				<strong><?php esc_html_e( 'SEO: no issues found', 'specflux-marketing-analytics-chat' ); ?></strong>
			<?php endif; ?>
		</p>
		<?php if ( $total > 0 && null !== $top ) : ?>
			<p class="smac-widget-seo-top">
				<?php
				printf(
					/* translators: 1: issue such as "Missing meta description", 2: page title */
					esc_html__( 'Top issue: %1$s on %2$s', 'specflux-marketing-analytics-chat' ),
					esc_html( (string) ( $top['label'] ?? '' ) ),
					'<a href="' . esc_url( (string) ( $top['url'] ?? '' ) ) . '">' . esc_html( (string) ( $top['title'] ?? '' ) ) . '</a>'
				);
				?>
			</p>
			<p class="smac-widget-seo-action">
				<a class="button button-small" href="<?php echo esc_url( Weekly_Prompt_Builder::assistant_url( Weekly_Prompt_Builder::seo_summary_prompt( $summary ) ) ); ?>">
					<?php esc_html_e( 'Ask AI what to fix first', 'specflux-marketing-analytics-chat' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php if ( ! $plugin ) : ?>
			<p class="smac-widget-seo-note"><?php esc_html_e( 'Install Yoast SEO, Rank Math, All in One SEO or SEOPress for title and description checks', 'specflux-marketing-analytics-chat' ); ?></p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Light loading state shown until the first automatic refresh returns.
	 *
	 * @return string HTML (escaped).
	 */
	public static function render_loading() {
		ob_start();
		?>
		<p class="smac-widget-loading">
			<span class="spinner is-active"></span>
			<?php esc_html_e( 'Loading your numbers…', 'specflux-marketing-analytics-chat' ); ?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Empty state for sites with no platform connected.
	 *
	 * @return string HTML (escaped).
	 */
	public static function render_empty_state() {
		ob_start();
		?>
		<div class="smac-widget-empty">
			<p><?php esc_html_e( 'Connect Google Analytics, Search Console or Clarity to see your numbers here.', 'specflux-marketing-analytics-chat' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-connections' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Connect a platform', 'specflux-marketing-analytics-chat' ); ?>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Footer link to the weekly email settings.
	 *
	 * @param bool $enabled Whether the weekly email is switched on.
	 * @return string HTML (escaped).
	 */
	public static function render_email_link( $enabled ) {
		ob_start();
		?>
		<p class="smac-widget-email-link">
			<?php
			printf(
				/* translators: %s: "on" or "off" */
				esc_html__( 'Weekly email summary: %s', 'specflux-marketing-analytics-chat' ),
				esc_html( $enabled ? __( 'on', 'specflux-marketing-analytics-chat' ) : __( 'off', 'specflux-marketing-analytics-chat' ) )
			);
			?>
			&mdash;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-settings&tab=email' ) ); ?>"><?php esc_html_e( 'change', 'specflux-marketing-analytics-chat' ); ?></a>
		</p>
		<?php
		return (string) ob_get_clean();
	}
}
