<?php
/**
 * Local Analytics Reader
 *
 * Reads traffic numbers from a privacy-first analytics plugin installed on the
 * same site, so sites without GA4 still get answers. Each supported plugin has a
 * small adapter that returns one common shape; no IPs, user agents or other
 * personal data are read.
 *
 * Storage was verified against the plugin sources on wordpress.org
 * (downloads.wordpress.org/plugin/<slug>.latest-stable.zip):
 * - Koko Analytics 2.5.3: `{prefix}koko_analytics_site_stats` (date, visitors, pageviews),
 *   `_post_stats` (date, path_id, post_id, visitors, pageviews) + `_paths` (id, path),
 *   `_referrer_stats` (date, id, unique_hits, hits) + `_referrer_labels` (id, value);
 *   migrations/001, 007, 017 and src/Pageview_Aggregator.php (commit_* methods).
 * - Burst Statistics 3.7.2: `{prefix}burst_statistics` (page_url path, page_type, time unix, uid_id,
 *   session_id) and `{prefix}burst_sessions` (start_time unix, uid_id, referrer);
 *   includes/Admin/Statistics/class-statistics.php (create_tables) and
 *   includes/Admin/Statistics/Metrics/class-pageviews-metric.php / class-visitors-metric.php.
 * - Independent Analytics 2.15.5: `{prefix}independent_analytics_views` (id, session_id, resource_id,
 *   viewed_at UTC), `_sessions` (session_id, visitor_id, referrer_id, created_at UTC), `_resources`
 *   (cached_url, cached_title), `_referrers` (domain, referrer_type_id), `_referrer_types`;
 *   IAWP/Tables.php, IAWP/Public_API/Analytics.php, IAWP/Migrations/Migration_45.php.
 * - Jetpack Stats (Jetpack 16.3.1 / standalone Jetpack Stats 1.0.0): data lives on WordPress.com and is
 *   read through Automattic\Jetpack\Stats\WPCOM_Stats (get_visits, get_top_posts, get_referrers; arrays or
 *   WP_Error), so it needs a WordPress.com connection. See .scratch/integrations-roadmap/research/verify-a4.md.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names below are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Picks the active adapter and shapes the common output.
 */
class Local_Analytics_Reader {

	const DEFAULT_DAYS = 28;
	const MAX_DAYS     = 366;

	/**
	 * Adapters in priority order.
	 *
	 * @var Local_Analytics_Adapter[]
	 */
	private $adapters;

	/**
	 * Memoised active adapter (false = none).
	 *
	 * @var Local_Analytics_Adapter|false|null
	 */
	private $active = null;

	/**
	 * Constructor.
	 *
	 * @param Local_Analytics_Adapter[]|null $adapters Optional adapters (tests).
	 */
	public function __construct( $adapters = null ) {
		$this->adapters = null === $adapters
			? array(
				new Burst_Statistics_Adapter(),
				new Independent_Analytics_Adapter(),
				new Koko_Analytics_Adapter(),
				new Jetpack_Stats_Adapter(),
			)
			: $adapters;
	}

	/**
	 * The first supported, active adapter that holds data.
	 *
	 * @return Local_Analytics_Adapter|null
	 */
	public function get_active_adapter() {
		if ( null === $this->active ) {
			$this->active = false;
			foreach ( $this->adapters as $adapter ) {
				if ( $adapter->is_supported() && $adapter->is_active() && $adapter->has_data() ) {
					$this->active = $adapter;
					break;
				}
			}
		}

		return $this->active ? $this->active : null;
	}

	/**
	 * Slug of the active adapter, or ''.
	 *
	 * @return string
	 */
	public function detect() {
		$adapter = $this->get_active_adapter();

		return $adapter ? $adapter->slug() : '';
	}

	/**
	 * Label of the active adapter, or ''.
	 *
	 * @return string
	 */
	public function get_label() {
		$adapter = $this->get_active_adapter();

		return $adapter ? $adapter->label() : '';
	}

	/**
	 * Labels of active plugins that cannot be read yet, with the reason.
	 *
	 * @return array<string,string> Map of label => reason.
	 */
	public function get_unsupported() {
		$out = array();
		foreach ( $this->adapters as $adapter ) {
			if ( ! $adapter->is_supported() && $adapter->is_active() ) {
				$out[ $adapter->label() ] = $adapter->unsupported_reason();
			}
		}

		return $out;
	}

	/**
	 * Active readable plugins that cannot answer right now, with the reason (e.g. Jetpack not connected).
	 *
	 * @return array<string,string> Map of label => reason.
	 */
	public function get_unreadable() {
		$out = $this->get_unsupported();
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_supported() && $adapter->is_active() && ! $adapter->has_data() && '' !== $adapter->last_error() ) {
				$out[ $adapter->label() ] = $adapter->last_error();
			}
		}

		return $out;
	}

	/**
	 * Provider (which plugin answered) of the active adapter, or ''.
	 *
	 * @return string
	 */
	public function get_provider() {
		$adapter = $this->get_active_adapter();

		return $adapter ? $adapter->provider() : '';
	}

	/**
	 * Resolve ability arguments into a validated range (default: last 28 days ending yesterday).
	 *
	 * @param array $args start_date / end_date (Y-m-d, site time zone).
	 * @return array
	 * @throws \InvalidArgumentException On bad dates.
	 */
	public static function build_range( array $args ) {
		$tz    = wp_timezone();
		$start = isset( $args['start_date'] ) ? trim( (string) $args['start_date'] ) : '';
		$end   = isset( $args['end_date'] ) ? trim( (string) $args['end_date'] ) : '';

		foreach ( array(
			'start_date' => $start,
			'end_date'   => $end,
		) as $name => $value ) {
			if ( '' !== $value ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
				if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
					throw new \InvalidArgumentException( esc_html( $name . ' must be a real date in YYYY-MM-DD format.' ) );
				}
			}
		}

		$today = new \DateTimeImmutable( 'today', $tz );
		if ( '' === $end ) {
			$end = $today->modify( '-1 day' )->format( 'Y-m-d' );
		}
		if ( '' === $start ) {
			$start = ( new \DateTimeImmutable( $end, $tz ) )->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		}
		if ( $start > $end ) {
			throw new \InvalidArgumentException( esc_html( 'start_date must not be after end_date.' ) );
		}

		$from = new \DateTimeImmutable( $start . ' 00:00:00', $tz );
		$to   = new \DateTimeImmutable( $end . ' 00:00:00', $tz );
		if ( $from->diff( $to )->days + 1 > self::MAX_DAYS ) {
			throw new \InvalidArgumentException( esc_html( 'The period must not be longer than ' . self::MAX_DAYS . ' days.' ) );
		}
		$after = $to->modify( '+1 day' );
		$utc   = new \DateTimeZone( 'UTC' );

		return array(
			'start'     => $start,
			'end'       => $end,
			'days'      => $from->diff( $to )->days + 1,
			'offset'    => $tz->getOffset( $from ),
			'start_ts'  => $from->getTimestamp(),
			'end_ts'    => $after->getTimestamp(),
			'utc_start' => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'utc_end'   => $after->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * The period of equal length immediately before a range.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public static function previous_range( array $range ) {
		$tz = wp_timezone();
		$to = ( new \DateTimeImmutable( $range['start'], $tz ) )->modify( '-1 day' );

		return self::build_range(
			array(
				'start_date' => $to->modify( '-' . ( $range['days'] - 1 ) . ' days' )->format( 'Y-m-d' ),
				'end_date'   => $to->format( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Traffic totals, change vs the previous period and a zero-filled daily series.
	 *
	 * @param array $range Range from build_range().
	 * @return array
	 * @throws \RuntimeException When no analytics plugin is readable.
	 */
	public function get_traffic( array $range ) {
		$adapter = $this->require_adapter();
		$totals  = $adapter->totals( $range );
		$prev_r  = self::previous_range( $range );
		$prev    = $adapter->totals( $prev_r );

		$by_date = array();
		foreach ( $adapter->daily( $range ) as $row ) {
			$by_date[ $row['date'] ] = $row;
		}
		$series = array();
		$cursor = new \DateTimeImmutable( $range['start'], wp_timezone() );
		for ( $i = 0; $i < $range['days']; $i++ ) {
			$key      = $cursor->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = isset( $by_date[ $key ] )
				? $by_date[ $key ]
				: array(
					'date'      => $key,
					'visitors'  => 0,
					'pageviews' => 0,
					'sessions'  => null === $totals['sessions'] ? null : 0,
				);
		}

		return array(
			'source'          => $this->source_block( $adapter ),
			'period'          => $range['start'] . ' to ' . $range['end'],
			'previous_period' => $prev_r['start'] . ' to ' . $prev_r['end'],
			'totals'          => $totals,
			'previous_totals' => $prev,
			'change_percent'  => array(
				'visitors'  => self::percent_change( $prev['visitors'], $totals['visitors'] ),
				'pageviews' => self::percent_change( $prev['pageviews'], $totals['pageviews'] ),
				'sessions'  => null === $totals['sessions'] ? null : self::percent_change( (int) $prev['sessions'], (int) $totals['sessions'] ),
			),
			'daily'           => $series,
		);
	}

	/**
	 * Top pages in the common shape.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function get_top_pages( array $range, $limit ) {
		$adapter = $this->require_adapter();

		return array(
			'source' => $this->source_block( $adapter ),
			'period' => $range['start'] . ' to ' . $range['end'],
			'items'  => $adapter->top_pages( $range, $limit ),
		);
	}

	/**
	 * Referrers in the common shape.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function get_referrers( array $range, $limit ) {
		$adapter = $this->require_adapter();

		return array(
			'source' => $this->source_block( $adapter ),
			'period' => $range['start'] . ' to ' . $range['end'],
			'items'  => $adapter->referrers( $range, $limit ),
		);
	}

	/**
	 * Percentage change, null when the previous value is zero.
	 *
	 * @param int $previous Previous value.
	 * @param int $current  Current value.
	 * @return float|null
	 */
	public static function percent_change( $previous, $current ) {
		if ( $previous <= 0 ) {
			return null;
		}

		return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
	}

	/**
	 * The adapter, or an exception when none is readable.
	 *
	 * @return Local_Analytics_Adapter
	 * @throws \RuntimeException When no analytics plugin is readable.
	 */
	private function require_adapter() {
		$adapter = $this->get_active_adapter();
		if ( ! $adapter ) {
			throw new \RuntimeException( esc_html( 'No supported site analytics plugin with recorded data was found (Burst Statistics, Independent Analytics, Koko Analytics and a WordPress.com-connected Jetpack Stats are supported).' ) );
		}

		return $adapter;
	}

	/**
	 * Provenance block included in every response.
	 *
	 * @param Local_Analytics_Adapter $adapter Adapter.
	 * @return array
	 */
	private function source_block( Local_Analytics_Adapter $adapter ) {
		$provider = $adapter->provider();
		$block    = array(
			'plugin' => $adapter->label(),
			'slug'   => $adapter->slug(),
			'note'   => 'First-party numbers recorded on this site by the ' . $adapter->label() . ' plugin (no personal data). They are not GA4 numbers and usually differ from them.',
		);
		if ( '' !== $provider ) {
			$block['answered_by'] = $provider;
		}

		return $block;
	}
}
