<?php
/**
 * Jetpack Stats Adapter
 *
 * Reads views, visitors, top posts and referrers that Jetpack Stats keeps on
 * WordPress.com, through Automattic\Jetpack\Stats\WPCOM_Stats (shipped by both the
 * Jetpack plugin and the standalone Jetpack Stats plugin). Nothing is read locally.
 * See .scratch/integrations-roadmap/research/verify-a4.md for the verified signatures.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Jetpack Stats adapter (remote read, transient cached).
 */
class Jetpack_Stats_Adapter extends Local_Analytics_Adapter {

	const STATS_CLASS      = '\\Automattic\\Jetpack\\Stats\\WPCOM_Stats';
	const CONNECTION_CLASS = '\\Automattic\\Jetpack\\Connection\\Manager';
	const CACHE_PREFIX     = 'specflux_mac_jpstats_';
	const CACHE_TTL        = 1800;
	const CHUNK_DAYS       = 90;

	/**
	 * Last problem met while reading, for the connection test.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Per-request memo of visits keyed by range.
	 *
	 * @var array
	 */
	private $visits_memo = array();

	/**
	 * Per-request memo of has_data().
	 *
	 * @var bool|null
	 */
	private $has_data_memo = null;

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'jetpack-stats';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Jetpack Stats';
	}

	/**
	 * Marker constant (jetpack.php).
	 *
	 * @return string
	 */
	protected function marker_constant() {
		return 'JETPACK__VERSION';
	}

	/**
	 * Active when Jetpack or the standalone Jetpack Stats plugin is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->has_constant( 'JETPACK__VERSION' ) || $this->has_constant( 'JETPACK_STATS_PLUGIN__VERSION' );
	}

	/**
	 * The plugin that answers: Jetpack, or the standalone Jetpack Stats plugin.
	 *
	 * @return string
	 */
	public function provider() {
		if ( $this->has_constant( 'JETPACK__VERSION' ) ) {
			return 'Jetpack plugin';
		}

		return $this->has_constant( 'JETPACK_STATS_PLUGIN__VERSION' ) ? 'Jetpack Stats plugin' : '';
	}

	/**
	 * Readable only when the WPCOM_Stats class exists.
	 *
	 * @return bool
	 */
	public function is_supported() {
		return $this->stats_class_exists();
	}

	/**
	 * Explanation when the stats class is missing.
	 *
	 * @return string
	 */
	public function unsupported_reason() {
		return 'The Jetpack Stats code (Automattic\Jetpack\Stats\WPCOM_Stats) is not available. Update Jetpack or install the Jetpack Stats plugin.';
	}

	/**
	 * Last read problem, or an explanation when not connected.
	 *
	 * @return string
	 */
	public function last_error() {
		if ( '' !== $this->last_error ) {
			return $this->last_error;
		}

		return $this->is_connected() ? '' : 'This site is not connected to WordPress.com, so Jetpack Stats has no data to read.';
	}

	/**
	 * Whether the site is connected to WordPress.com.
	 *
	 * @return bool
	 */
	public function is_connected() {
		$class = self::CONNECTION_CLASS;
		if ( ! class_exists( $class ) ) {
			return false;
		}
		try {
			return (bool) ( new $class() )->is_connected();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether the connected site has recorded any views in the last 30 days.
	 *
	 * @return bool
	 */
	public function has_data() {
		if ( null !== $this->has_data_memo ) {
			return $this->has_data_memo;
		}
		$this->has_data_memo = false;
		if ( ! $this->stats_class_exists() || ! $this->is_connected() ) {
			return false;
		}
		$range = Local_Analytics_Reader::build_range( array() );
		$rows  = $this->visits( array_merge( $range, array( 'days' => 30 ) ) );
		foreach ( $rows as $row ) {
			if ( $row['pageviews'] > 0 ) {
				$this->has_data_memo = true;
				break;
			}
		}

		return $this->has_data_memo;
	}

	/**
	 * Totals. Visitors are the sum of daily visitors (Jetpack gives no unique-in-period figure).
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function totals( array $range ) {
		$out = array(
			'visitors'       => 0,
			'pageviews'      => 0,
			'sessions'       => null,
			'visitors_basis' => 'sum_of_daily',
		);
		foreach ( $this->visits( $range ) as $row ) {
			$out['visitors']  += $row['visitors'];
			$out['pageviews'] += $row['pageviews'];
		}

		return $out;
	}

	/**
	 * Per-day rows.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function daily( array $range ) {
		$out = array();
		foreach ( $this->visits( $range ) as $row ) {
			$out[] = array(
				'date'      => $row['date'],
				'visitors'  => $row['visitors'],
				'pageviews' => $row['pageviews'],
				'sessions'  => null,
			);
		}

		return $out;
	}

	/**
	 * Top posts and pages as paths.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function top_pages( array $range, $limit ) {
		$raw = $this->remote( 'get_top_posts', $this->content_args( $range, $limit ) );
		if ( null === $raw ) {
			return array();
		}
		$paths = array();
		foreach ( $this->day_lists( $raw, 'postviews' ) as $row ) {
			$href = isset( $row['href'] ) ? (string) $row['href'] : '';
			$path = '' === $href ? '' : wp_parse_url( $href, PHP_URL_PATH );
			$path = ( is_string( $path ) && '' !== $path ) ? $path : '/';
			$paths[ $path ] = (int) ( isset( $paths[ $path ] ) ? $paths[ $path ] : 0 ) + (int) ( isset( $row['views'] ) ? $row['views'] : 0 );
		}
		arsort( $paths );
		$out = array();
		foreach ( array_slice( $paths, 0, max( 1, (int) $limit ), true ) as $path => $views ) {
			$out[] = array(
				'path'      => (string) $path,
				'pageviews' => (int) $views,
				'visitors'  => null,
			);
		}

		return $out;
	}

	/**
	 * Site-wide referrers (groups) by views.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function referrers( array $range, $limit ) {
		$raw = $this->remote( 'get_referrers', $this->content_args( $range, $limit ) );
		if ( null === $raw ) {
			return array();
		}
		$merged = array();
		foreach ( $this->day_lists( $raw, 'groups' ) as $group ) {
			$name = isset( $group['name'] ) ? trim( (string) $group['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			// Hosts are merged without www; labels such as "Google Search" keep their casing.
			$label = false !== strpos( $name, '.' ) ? $this->normalize_source( $name ) : $name;
			$merged[ $label ] = (int) ( isset( $merged[ $label ] ) ? $merged[ $label ] : 0 ) + (int) ( isset( $group['total'] ) ? $group['total'] : 0 );
		}
		arsort( $merged );
		$out = array();
		foreach ( array_slice( $merged, 0, max( 1, (int) $limit ), true ) as $label => $views ) {
			$out[] = array(
				'source'    => (string) $label,
				'pageviews' => (int) $views,
				'visitors'  => null,
				'sessions'  => null,
			);
		}

		return $out;
	}

	/**
	 * Whether the WPCOM_Stats class can be loaded (overridable in tests).
	 *
	 * @return bool
	 */
	protected function stats_class_exists() {
		return class_exists( self::STATS_CLASS );
	}

	/**
	 * A WPCOM_Stats instance (overridable in tests).
	 *
	 * @return object
	 */
	protected function stats_client() {
		$class = self::STATS_CLASS;

		return new $class();
	}

	/**
	 * Visit rows (date, pageviews, visitors) inside a range, cached.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	private function visits( array $range ) {
		$key = $range['start'] . '|' . $range['end'];
		if ( isset( $this->visits_memo[ $key ] ) ) {
			return $this->visits_memo[ $key ];
		}
		$rows      = array();
		$cursor    = new \DateTimeImmutable( $range['end'], wp_timezone() );
		$remaining = (int) $range['days'];
		while ( $remaining > 0 ) {
			$chunk = min( self::CHUNK_DAYS, $remaining );
			$raw   = $this->remote(
				'get_visits',
				array(
					'unit'        => 'day',
					'quantity'    => $chunk,
					'date'        => $cursor->format( 'Y-m-d' ),
					'stat_fields' => 'views,visitors',
				)
			);
			if ( null === $raw ) {
				$rows = array();
				break;
			}
			foreach ( $this->visit_rows( $raw ) as $row ) {
				if ( $row['date'] >= $range['start'] && $row['date'] <= $range['end'] ) {
					$rows[ $row['date'] ] = $row;
				}
			}
			$cursor    = $cursor->modify( '-' . $chunk . ' days' );
			$remaining -= $chunk;
		}
		ksort( $rows );
		$this->visits_memo[ $key ] = array_values( $rows );

		return $this->visits_memo[ $key ];
	}

	/**
	 * Map a get_visits response ({fields:[period,views,visitors], data:[[...]]}) to rows.
	 *
	 * @param array $raw Response.
	 * @return array
	 */
	private function visit_rows( array $raw ) {
		$fields = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? array_values( $raw['fields'] ) : array();
		$idx    = array_flip( array_map( 'strval', $fields ) );
		if ( ! isset( $idx['period'] ) ) {
			return array();
		}
		$out = array();
		foreach ( isset( $raw['data'] ) && is_array( $raw['data'] ) ? $raw['data'] : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[ $idx['period'] ] ) ) {
				continue;
			}
			$out[] = array(
				'date'      => (string) $row[ $idx['period'] ],
				'pageviews' => isset( $idx['views'], $row[ $idx['views'] ] ) ? (int) $row[ $idx['views'] ] : 0,
				'visitors'  => isset( $idx['visitors'], $row[ $idx['visitors'] ] ) ? (int) $row[ $idx['visitors'] ] : 0,
			);
		}

		return $out;
	}

	/**
	 * Query args for top-posts / referrers over a range.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function content_args( array $range, $limit ) {
		return array(
			'period'    => 'day',
			'date'      => $range['end'],
			'num'       => min( 90, (int) $range['days'] ),
			'max'       => min( 100, max( 1, (int) $limit ) * 3 ),
			'summarize' => 1,
		);
	}

	/**
	 * Rows of one list from a days-keyed response, preferring a `summary` block.
	 *
	 * @param array  $raw  Response.
	 * @param string $list_key List key (postviews or groups).
	 * @return array
	 */
	private function day_lists( array $raw, $list_key ) {
		if ( isset( $raw['summary'][ $list_key ] ) && is_array( $raw['summary'][ $list_key ] ) ) {
			return array_values( array_filter( $raw['summary'][ $list_key ], 'is_array' ) );
		}
		$out = array();
		foreach ( isset( $raw['days'] ) && is_array( $raw['days'] ) ? $raw['days'] : array() as $day ) {
			if ( is_array( $day ) && isset( $day[ $list_key ] ) && is_array( $day[ $list_key ] ) ) {
				foreach ( $day[ $list_key ] as $row ) {
					if ( is_array( $row ) ) {
						$out[] = $row;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Call a WPCOM_Stats method with transient caching. Errors are not cached.
	 *
	 * @param string $method WPCOM_Stats method.
	 * @param array  $args   Query args.
	 * @return array|null Response, or null on any failure (reason kept in last_error()).
	 */
	private function remote( $method, array $args ) {
		$this->last_error = '';
		if ( ! $this->stats_class_exists() ) {
			$this->last_error = $this->unsupported_reason();
			return null;
		}
		if ( ! $this->is_connected() ) {
			$this->last_error = 'This site is not connected to WordPress.com, so Jetpack Stats has no data to read.';
			return null;
		}
		$cache_key = self::CACHE_PREFIX . md5( $method . wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$raw = $this->stats_client()->$method( $args );
		} catch ( \Throwable $e ) {
			$this->last_error = 'Jetpack Stats request failed: ' . $e->getMessage();
			return null;
		}
		if ( is_wp_error( $raw ) ) {
			$this->last_error = 'Jetpack Stats request failed: ' . $raw->get_error_message();
			return null;
		}
		if ( ! is_array( $raw ) ) {
			$this->last_error = 'Jetpack Stats returned an unexpected response.';
			return null;
		}
		set_transient( $cache_key, $raw, self::CACHE_TTL );

		return $raw;
	}
}
