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
	 * @param Weekly_Summary|null $summary Collector (defaults to live clients).
	 * @return array
	 */
	public static function refresh( ?Weekly_Summary $summary = null ) {
		$headline = self::build( $summary );
		$payload  = array(
			'headline'     => $headline,
			'generated_at' => time(),
		);

		set_transient( self::TRANSIENT, $payload, self::ttl() );

		return array(
			'message'       => 'Widget data refreshed successfully.',
			'headline'      => $headline,
			'headline_html' => self::render( $headline ),
		);
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
