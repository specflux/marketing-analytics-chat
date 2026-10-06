<?php
/**
 * Weekly Summary data assembly
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Collects the numbers behind the weekly summary email.
 *
 * Each connected platform is fetched independently: a failure in one is
 * recorded as an error status for that platform and never stops the others.
 *
 * The returned context looks like:
 *
 *     array(
 *         'generated_at' => int,
 *         'ranges'       => array( 'ga4' => array( 'current' => array( start, end ), 'previous' => ... ), 'gsc' => ... ),
 *         'platforms'    => array( 'ga4' => array( 'status' => 'ok|error', 'data' => array() ), ... ),
 *     )
 */
class Weekly_Summary {

	/**
	 * Days in each comparison window.
	 */
	const WINDOW_DAYS = 7;

	/**
	 * Search Console data lags by roughly this many days.
	 */
	const GSC_LAG_DAYS = 3;

	/**
	 * Platforms in the order they appear in the email.
	 *
	 * @var string[]
	 */
	const PLATFORMS = array( 'ga4', 'gsc', 'clarity' );

	/**
	 * Injected API clients keyed by platform (tests pass doubles).
	 *
	 * @var array
	 */
	private $clients;

	/**
	 * Injected list of connected platforms, or null to read stored credentials.
	 *
	 * @var string[]|null
	 */
	private $connected;

	/**
	 * Constructor.
	 *
	 * @param array         $clients   Optional platform => client object overrides.
	 * @param string[]|null $connected Optional connected-platform override.
	 */
	public function __construct( array $clients = array(), ?array $connected = null ) {
		$this->clients   = $clients;
		$this->connected = $connected;
	}

	/**
	 * Platforms that have stored credentials, in email order.
	 *
	 * @return string[]
	 */
	public function get_connected_platforms() {
		if ( null !== $this->connected ) {
			return array_values( array_intersect( self::PLATFORMS, $this->connected ) );
		}

		$credential_manager = new Credential_Manager();
		$connected          = array();

		foreach ( self::PLATFORMS as $platform ) {
			if ( $credential_manager->has_credentials( $platform ) ) {
				$connected[] = $platform;
			}
		}

		return $connected;
	}

	/**
	 * Percentage change from the previous value to the current one.
	 *
	 * @param float|int $current  Current value.
	 * @param float|int $previous Previous value.
	 * @return float|null Percent change, 0.0 when both are zero, or null when
	 *                    there is no baseline to compare against.
	 */
	public static function percent_change( $current, $previous ) {
		$current  = (float) $current;
		$previous = (float) $previous;

		if ( 0.0 === $previous ) {
			return 0.0 === $current ? 0.0 : null;
		}

		return ( ( $current - $previous ) / $previous ) * 100;
	}

	/**
	 * Build a "start,end" pair of dates for a window ending on a given day.
	 *
	 * @param \DateTimeImmutable $end_day Last day of the window (inclusive).
	 * @param int                $offset  Whole windows to shift back (0 = current, 1 = previous).
	 * @return array{0:string,1:string} Start and end as Y-m-d.
	 */
	private function window( \DateTimeImmutable $end_day, $offset ) {
		$shift = $offset * self::WINDOW_DAYS;
		$end   = $end_day->modify( '-' . $shift . ' days' );
		$start = $end->modify( '-' . ( self::WINDOW_DAYS - 1 ) . ' days' );

		return array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
	}

	/**
	 * Date windows used for each platform.
	 *
	 * GA4 covers the seven full days ending yesterday; Search Console ends
	 * three days ago because its data lags.
	 *
	 * @param int|null $now Unix timestamp to treat as "now".
	 * @return array Windows keyed by platform, each with current and previous.
	 */
	public function get_ranges( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );

		$ga4_end = $today->modify( '-1 day' );
		$gsc_end = $today->modify( '-' . self::GSC_LAG_DAYS . ' days' );

		return array(
			'ga4' => array(
				'current'  => $this->window( $ga4_end, 0 ),
				'previous' => $this->window( $ga4_end, 1 ),
			),
			'gsc' => array(
				'current'  => $this->window( $gsc_end, 0 ),
				'previous' => $this->window( $gsc_end, 1 ),
			),
		);
	}

	/**
	 * Collect data for every connected platform.
	 *
	 * @param int|null $now Unix timestamp to treat as "now".
	 * @return array Context described in the class docblock.
	 */
	public function collect( $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$ranges    = $this->get_ranges( $now );
		$platforms = array();

		foreach ( $this->get_connected_platforms() as $platform ) {
			try {
				switch ( $platform ) {
					case 'ga4':
						$data = $this->collect_ga4( $ranges['ga4'] );
						break;
					case 'gsc':
						$data = $this->collect_gsc( $ranges['gsc'] );
						break;
					default:
						$data = $this->collect_clarity();
						break;
				}

				$platforms[ $platform ] = array(
					'status' => 'ok',
					'data'   => $data,
				);
			} catch ( \Throwable $e ) {
				$platforms[ $platform ] = array(
					'status' => 'error',
					'data'   => array(),
				);
			}
		}

		return array(
			'generated_at' => $now,
			'ranges'       => $ranges,
			'platforms'    => $platforms,
		);
	}

	/**
	 * Build a metric row for the headline table.
	 *
	 * @param string    $key             Metric key.
	 * @param string    $label           Display label.
	 * @param float|int $current         Current value.
	 * @param float|int $previous        Previous value.
	 * @param string    $format          One of int, percent, position.
	 * @param bool      $lower_is_better Whether a decrease is an improvement.
	 * @return array
	 */
	private function metric( $key, $label, $current, $previous, $format = 'int', $lower_is_better = false ) {
		return array(
			'key'             => $key,
			'label'           => $label,
			'current'         => $current,
			'previous'        => $previous,
			'change'          => self::percent_change( $current, $previous ),
			'format'          => $format,
			'lower_is_better' => $lower_is_better,
		);
	}

	/**
	 * Get a GA4 client.
	 *
	 * @return GA4_Client|object
	 */
	private function ga4_client() {
		return $this->clients['ga4'] ?? new GA4_Client();
	}

	/**
	 * Get a Search Console client.
	 *
	 * @return GSC_Client|object
	 */
	private function gsc_client() {
		return $this->clients['gsc'] ?? new GSC_Client();
	}

	/**
	 * Get a Clarity client.
	 *
	 * @return Clarity_Client|object
	 */
	private function clarity_client() {
		return $this->clients['clarity'] ?? new Clarity_Client();
	}

	/**
	 * Collect Google Analytics data.
	 *
	 * @param array $range Current and previous windows.
	 * @return array
	 * @throws \Exception When the headline report cannot be fetched.
	 */
	private function collect_ga4( array $range ) {
		$client  = $this->ga4_client();
		$metrics = array( 'sessions', 'totalUsers', 'screenPageViews' );
		$current = $this->first_row( $client->run_report( $metrics, array(), implode( ',', $range['current'] ) ) );
		$prior   = $this->first_row( $client->run_report( $metrics, array(), implode( ',', $range['previous'] ) ) );

		$labels = array(
			'sessions'        => __( 'Sessions', 'specflux-marketing-analytics-chat' ),
			'totalUsers'      => __( 'Users', 'specflux-marketing-analytics-chat' ),
			'screenPageViews' => __( 'Page views', 'specflux-marketing-analytics-chat' ),
		);

		$rows = array();
		foreach ( $metrics as $metric ) {
			$rows[] = $this->metric( $metric, $labels[ $metric ], (float) ( $current[ $metric ] ?? 0 ), (float) ( $prior[ $metric ] ?? 0 ) );
		}

		// Detail lists are best-effort: the headline numbers still send without them.
		$pages   = array();
		$sources = array();

		try {
			$report = $client->run_report(
				array( 'screenPageViews' ),
				array( 'pagePath' ),
				implode( ',', $range['current'] ),
				array(
					'limit'           => 5,
					'order_by_metric' => 'screenPageViews',
				)
			);
			foreach ( array_slice( (array) ( $report['rows'] ?? array() ), 0, 5 ) as $row ) {
				$pages[] = array(
					'label' => (string) ( $row['pagePath'] ?? '' ),
					'value' => (float) ( $row['screenPageViews'] ?? 0 ),
				);
			}
		} catch ( \Throwable $e ) {
			$pages = array();
		}

		try {
			$report = $client->run_report(
				array( 'sessions' ),
				array( 'sessionDefaultChannelGroup' ),
				implode( ',', $range['current'] ),
				array(
					'limit'           => 3,
					'order_by_metric' => 'sessions',
				)
			);
			foreach ( array_slice( (array) ( $report['rows'] ?? array() ), 0, 3 ) as $row ) {
				$sources[] = array(
					'label' => (string) ( $row['sessionDefaultChannelGroup'] ?? '' ),
					'value' => (float) ( $row['sessions'] ?? 0 ),
				);
			}
		} catch ( \Throwable $e ) {
			$sources = array();
		}

		return array(
			'metrics'  => $rows,
			'pages'    => $pages,
			'sources'  => $sources,
			'range'    => $range['current'],
			'previous' => $range['previous'],
		);
	}

	/**
	 * Collect Search Console data.
	 *
	 * @param array $range Current and previous windows.
	 * @return array
	 * @throws \Exception When the headline query cannot be fetched.
	 */
	private function collect_gsc( array $range ) {
		$client  = $this->gsc_client();
		$current = $this->first_row( $client->query_search_analytics( implode( ',', $range['current'] ), array(), array(), array( 'row_limit' => 1 ) ) );
		$prior   = $this->first_row( $client->query_search_analytics( implode( ',', $range['previous'] ), array(), array(), array( 'row_limit' => 1 ) ) );

		$rows = array(
			$this->metric( 'clicks', __( 'Clicks', 'specflux-marketing-analytics-chat' ), (float) ( $current['clicks'] ?? 0 ), (float) ( $prior['clicks'] ?? 0 ) ),
			$this->metric( 'impressions', __( 'Impressions', 'specflux-marketing-analytics-chat' ), (float) ( $current['impressions'] ?? 0 ), (float) ( $prior['impressions'] ?? 0 ) ),
			$this->metric( 'ctr', __( 'Avg. CTR', 'specflux-marketing-analytics-chat' ), (float) ( $current['ctr'] ?? 0 ) * 100, (float) ( $prior['ctr'] ?? 0 ) * 100, 'percent' ),
			$this->metric( 'position', __( 'Avg. position', 'specflux-marketing-analytics-chat' ), (float) ( $current['position'] ?? 0 ), (float) ( $prior['position'] ?? 0 ), 'position', true ),
		);

		$queries = array();

		try {
			$report = $client->query_search_analytics( implode( ',', $range['current'] ), array( 'query' ), array(), array( 'row_limit' => 250 ) );
			$found  = (array) ( $report['rows'] ?? array() );
			// Search Console breaks click ties alphabetically; impressions are the more useful tiebreak on low-traffic sites.
			usort(
				$found,
				function ( $a, $b ) {
					return array( (float) ( $b['clicks'] ?? 0 ), (float) ( $b['impressions'] ?? 0 ) ) <=> array( (float) ( $a['clicks'] ?? 0 ), (float) ( $a['impressions'] ?? 0 ) );
				}
			);
			foreach ( array_slice( $found, 0, 5 ) as $row ) {
				$queries[] = array(
					'label' => (string) ( $row['key'] ?? ( $row['keys'][0] ?? '' ) ),
					'value' => (float) ( $row['clicks'] ?? 0 ),
				);
			}
		} catch ( \Throwable $e ) {
			$queries = array();
		}

		return array(
			'metrics'  => $rows,
			'queries'  => $queries,
			'range'    => $range['current'],
			'previous' => $range['previous'],
		);
	}

	/**
	 * Collect Microsoft Clarity headline numbers (last 3 days).
	 *
	 * @return array
	 * @throws \Exception When Clarity returns nothing usable, so the section is skipped.
	 */
	private function collect_clarity() {
		$insights = $this->clarity_client()->get_insights( 3 );

		if ( ! is_array( $insights ) ) {
			throw new \Exception( 'Clarity unavailable' );
		}

		$by_name = array();
		foreach ( $insights as $item ) {
			if ( is_array( $item ) && isset( $item['metricName'] ) ) {
				$info                            = $item['information'][0] ?? array();
				$by_name[ $item['metricName'] ] = is_array( $info ) ? $info : array();
			}
		}

		$stats = array();

		if ( isset( $by_name['Traffic']['totalSessionCount'] ) ) {
			$stats[] = array(
				'label' => __( 'Sessions', 'specflux-marketing-analytics-chat' ),
				'value' => number_format_i18n( (float) $by_name['Traffic']['totalSessionCount'] ),
			);
		}
		if ( isset( $by_name['Traffic']['distinctUserCount'] ) ) {
			$stats[] = array(
				'label' => __( 'Users', 'specflux-marketing-analytics-chat' ),
				'value' => number_format_i18n( (float) $by_name['Traffic']['distinctUserCount'] ),
			);
		}
		if ( isset( $by_name['Traffic']['pagesPerSessionPercentage'] ) ) {
			$stats[] = array(
				'label' => __( 'Pages per session', 'specflux-marketing-analytics-chat' ),
				'value' => number_format_i18n( (float) $by_name['Traffic']['pagesPerSessionPercentage'], 1 ),
			);
		}
		if ( isset( $by_name['ScrollDepth']['averageScrollDepth'] ) ) {
			$stats[] = array(
				'label' => __( 'Avg. scroll depth', 'specflux-marketing-analytics-chat' ),
				'value' => number_format_i18n( (float) $by_name['ScrollDepth']['averageScrollDepth'], 0 ) . '%',
			);
		}
		if ( isset( $by_name['RageClickCount']['sessionsWithMetricPercentage'] ) ) {
			$stats[] = array(
				'label' => __( 'Sessions with rage clicks', 'specflux-marketing-analytics-chat' ),
				'value' => number_format_i18n( (float) $by_name['RageClickCount']['sessionsWithMetricPercentage'], 1 ) . '%',
			);
		}

		if ( empty( $stats ) ) {
			throw new \Exception( 'Clarity returned no headline numbers' );
		}

		return array( 'stats' => $stats );
	}

	/**
	 * Pull the first result row out of a report response.
	 *
	 * @param mixed $report Report response.
	 * @return array
	 */
	private function first_row( $report ) {
		if ( is_array( $report ) && ! empty( $report['rows'][0] ) && is_array( $report['rows'][0] ) ) {
			return $report['rows'][0];
		}

		if ( is_array( $report ) && ! empty( $report['totals'] ) && is_array( $report['totals'] ) ) {
			return $report['totals'];
		}

		return array();
	}
}
