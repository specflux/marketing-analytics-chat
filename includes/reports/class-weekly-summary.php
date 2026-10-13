<?php
/**
 * Weekly Summary data assembly
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

use Specflux_Marketing_Analytics\API_Clients\Bing_Webmaster_Client;
use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;
use Specflux_Marketing_Analytics\Content\Local_Analytics_Reader;
use Specflux_Marketing_Analytics\Donations\Givewp_Donations_Reader;
use Specflux_Marketing_Analytics\Content\Seo_Issue_Summary;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
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
	 * Pseudo platform for the on-site analytics plugin fallback (non-GA4 traffic headline).
	 */
	const LOCAL_PLATFORM = 'localstats';

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
	 * Injected SEO summary service (tests pass doubles).
	 *
	 * @var Seo_Issue_Summary|null
	 */
	private $seo;

	/**
	 * Injected WooCommerce sales reader (tests pass doubles).
	 *
	 * @var Woo_Sales_Reader|null
	 */
	private $woo;

	/**
	 * Injected GiveWP donations reader (tests pass doubles).
	 *
	 * @var Givewp_Donations_Reader|null
	 */
	private $give;

	/**
	 * Constructor.
	 *
	 * @param array                        $clients   Optional platform => client object overrides (ga4, gsc, clarity, bing).
	 * @param string[]|null                $connected Optional connected-platform override (may include bing).
	 * @param Seo_Issue_Summary|null       $seo       Optional SEO summary service.
	 * @param Woo_Sales_Reader|null        $woo       Optional WooCommerce sales reader.
	 * @param Givewp_Donations_Reader|null $give Optional GiveWP donations reader.
	 */
	public function __construct( array $clients = array(), ?array $connected = null, ?Seo_Issue_Summary $seo = null, ?Woo_Sales_Reader $woo = null, ?Givewp_Donations_Reader $give = null ) {
		$this->clients   = $clients;
		$this->connected = $connected;
		$this->seo       = $seo;
		$this->woo       = $woo;
		$this->give      = $give;
	}

	/**
	 * Platforms that have stored credentials, in email order.
	 *
	 * @return string[]
	 */
	public function get_connected_platforms() {
		if ( null !== $this->connected ) {
			return array_values( array_intersect( array_merge( self::PLATFORMS, array( self::LOCAL_PLATFORM ) ), $this->connected ) );
		}

		$credential_manager = new Credential_Manager();
		$connected          = array();

		foreach ( self::PLATFORMS as $platform ) {
			if ( $credential_manager->has_credentials( $platform ) ) {
				$connected[] = $platform;
			}
		}

		// Without GA4, an on-site analytics plugin (Burst, Koko, Independent Analytics, Jetpack Stats) supplies the traffic headline.
		if ( ! in_array( 'ga4', $connected, true ) && '' !== $this->local_reader()->get_label() ) {
			$connected[] = self::LOCAL_PLATFORM;
		}

		return $connected;
	}

	/**
	 * Whether there is anything to summarise: a connected analytics platform, or a
	 * WooCommerce store with paid orders this week.
	 *
	 * @param int|null $now Unix timestamp to treat as "now".
	 * @return bool
	 */
	public function has_data_source( $now = null ) {
		if ( ! empty( $this->get_connected_platforms() ) ) {
			return true;
		}

		try {
			$ranges = $this->get_ranges( $now )['ga4'];

			return null !== $this->collect_sales( $ranges ) || null !== $this->collect_donations( $ranges );
		} catch ( \Throwable $e ) {
			return false;
		}
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
					case self::LOCAL_PLATFORM:
						$data = $this->collect_localstats( $ranges['ga4'] );
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
	 * Collect everything the weekly email shows: the platform numbers plus the
	 * Bing search and SEO extras.
	 *
	 * The extras are added under their own keys (`bing`, `seo`) so the platform
	 * list that the dashboard widget reads stays unchanged. Each extra fails
	 * soft: an error leaves it out and never stops the email.
	 *
	 * @param int|null $now Unix timestamp to treat as "now".
	 * @return array Context from collect() plus optional `bing` and `seo` keys.
	 */
	public function collect_report( $now = null ) {
		$context = $this->collect( $now );

		try {
			if ( $this->bing_ready() ) {
				$ranges = $context['ranges']['gsc'] ?? $this->get_ranges( $now )['gsc'];
				try {
					$context['bing'] = array(
						'status' => 'ok',
						'data'   => $this->collect_bing( $ranges ),
					);
				} catch ( \Throwable $e ) {
					$context['bing'] = array(
						'status' => 'error',
						'data'   => array(),
					);
				}
			}
		} catch ( \Throwable $e ) {
			unset( $context['bing'] );
		}

		try {
			$context['seo'] = ( $this->seo ?? new Seo_Issue_Summary() )->get_or_refresh();
		} catch ( \Throwable $e ) {
			unset( $context['seo'] );
		}

		try {
			$sales = $this->collect_sales( $context['ranges']['ga4'] ?? $this->get_ranges( $now )['ga4'] );
			if ( null !== $sales ) {
				$context['sales'] = array(
					'status' => 'ok',
					'data'   => $sales,
				);
			}
		} catch ( \Throwable $e ) {
			unset( $context['sales'] );
		}

		try {
			$donations = $this->collect_donations( $context['ranges']['ga4'] ?? $this->get_ranges( $now )['ga4'] );
			if ( null !== $donations ) {
				$context['donations'] = array(
					'status' => 'ok',
					'data'   => $donations,
				);
			}
		} catch ( \Throwable $e ) {
			unset( $context['donations'] );
		}

		return $context;
	}

	/**
	 * GiveWP donations for a week against the week before, one entry per currency.
	 *
	 * Returns null when GiveWP is not active or the week has no completed donations,
	 * so the email and the dashboard widget leave the section out.
	 *
	 * @param array $range Window with `current` and `previous` (each start, end Y-m-d).
	 * @return array|null Keys: range, currencies (each: currency, amount, previous_amount, change, donations, donors).
	 */
	public function collect_donations( array $range ) {
		$reader = $this->give ?? new Givewp_Donations_Reader();

		if ( ! $reader->available() ) {
			return null;
		}

		$current = $reader->get_donations_by_source( $range['current'][0], $range['current'][1], 'channel', 1, false );
		if ( (int) $current['total_donations'] < 1 ) {
			return null;
		}

		$previous = $reader->get_donations_by_source( $range['previous'][0], $range['previous'][1], 'channel', 1, false );
		$prior    = array();
		foreach ( $previous['currencies'] as $row ) {
			$prior[ $row['currency'] ] = (float) $row['amount'];
		}

		$currencies = array();
		foreach ( $current['currencies'] as $row ) {
			$before       = $prior[ $row['currency'] ] ?? 0.0;
			$currencies[] = array(
				'currency'        => (string) $row['currency'],
				'amount'          => (float) $row['amount'],
				'previous_amount' => $before,
				'change'          => $before > 0 ? round( ( $row['amount'] - $before ) / $before * 100, 1 ) : null,
				'donations'       => (int) $row['donations'],
				'donors'          => (int) $row['donors'],
			);
		}

		return array(
			'range'      => $range['current'],
			'currencies' => $currencies,
		);
	}

	/**
	 * WooCommerce revenue for a week against the week before, with the top channels.
	 *
	 * Returns null when WooCommerce is not active or the week has no paid orders,
	 * so the email and the dashboard widget simply leave the section out.
	 *
	 * @param array $range Window with `current` and `previous` (each start, end Y-m-d).
	 * @return array|null Keys: currency, revenue, previous_revenue, change, orders, range, channels.
	 */
	public function collect_sales( array $range ) {
		$reader = $this->woo ?? new Woo_Sales_Reader();

		if ( ! $reader->available() ) {
			return null;
		}

		$current = $reader->get_sales_by_source( $range['current'][0], $range['current'][1], 'channel', 10, false );
		if ( (int) $current['total_orders'] < 1 ) {
			return null;
		}

		$previous = $reader->get_sales_by_source( $range['previous'][0], $range['previous'][1], 'channel', 1, false );
		$prior    = (float) $previous['total_revenue'];
		$revenue  = (float) $current['total_revenue'];

		$channels = array();
		foreach ( array_slice( $current['rows'], 0, 3 ) as $row ) {
			$channels[] = array(
				'label'   => (string) $row['label'],
				'revenue' => (float) $row['revenue'],
				'share'   => (float) $row['share'],
			);
		}

		return array(
			'currency'         => (string) $current['currency'],
			'revenue'          => $revenue,
			'previous_revenue' => $prior,
			'change'           => $prior > 0 ? round( ( $revenue - $prior ) / $prior * 100, 1 ) : null,
			'orders'           => (int) $current['total_orders'],
			'range'            => $range['current'],
			'channels'         => $channels,
		);
	}

	/**
	 * Whether Bing is connected and its last connection test passed.
	 *
	 * @return bool
	 */
	private function bing_ready() {
		if ( null !== $this->connected ) {
			$connected = in_array( 'bing', $this->connected, true );
		} else {
			$connected = ( new Credential_Manager() )->has_credentials( 'bing' );
		}

		if ( ! $connected ) {
			return false;
		}

		$last = Connection_Tester::get_last_result( 'bing' );

		return is_array( $last ) && ! empty( $last['success'] );
	}

	/**
	 * Collect Bing Webmaster Tools numbers (same windows as Search Console).
	 *
	 * @param array $range Current and previous windows.
	 * @return array
	 * @throws \Exception When the traffic report cannot be fetched.
	 */
	private function collect_bing( array $range ) {
		$client  = $this->clients['bing'] ?? new Bing_Webmaster_Client();
		$current = $this->sum_bing( $client->get_rank_and_traffic_stats( $range['current'][0], $range['current'][1] ) );
		$prior   = $this->sum_bing( $client->get_rank_and_traffic_stats( $range['previous'][0], $range['previous'][1] ) );

		$rows = array(
			$this->metric( 'clicks', __( 'Clicks', 'specflux-marketing-analytics-chat' ), $current['clicks'], $prior['clicks'] ),
			$this->metric( 'impressions', __( 'Impressions', 'specflux-marketing-analytics-chat' ), $current['impressions'], $prior['impressions'] ),
		);

		// Top queries are best-effort: the headline numbers still send without them.
		$queries = array();
		try {
			foreach ( array_slice( (array) $client->get_query_stats( $range['current'][0], $range['current'][1], 3 ), 0, 3 ) as $row ) {
				$queries[] = array(
					'label' => (string) ( $row['query'] ?? '' ),
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
	 * Total clicks and impressions of Bing daily rows.
	 *
	 * @param mixed $rows Rows from get_rank_and_traffic_stats().
	 * @return array{clicks:float,impressions:float}
	 * @throws \Exception When the response is not a list of rows.
	 */
	private function sum_bing( $rows ) {
		if ( ! is_array( $rows ) ) {
			throw new \Exception( 'Bing unavailable' );
		}

		$total = array(
			'clicks'      => 0.0,
			'impressions' => 0.0,
		);
		foreach ( $rows as $row ) {
			$total['clicks']      += (float) ( $row['clicks'] ?? 0 );
			$total['impressions'] += (float) ( $row['impressions'] ?? 0 );
		}

		return $total;
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
	 * Average position metric. With no impressions there is no position, so the
	 * value (and the change) is null instead of a misleading 0.
	 *
	 * @param array $current Current window headline row.
	 * @param array $prior   Previous window headline row.
	 * @return array Metric row.
	 */
	private function position_metric( array $current, array $prior ) {
		$current_position = ( (float) ( $current['impressions'] ?? 0 ) > 0 ) ? (float) ( $current['position'] ?? 0 ) : null;
		$prior_position   = ( (float) ( $prior['impressions'] ?? 0 ) > 0 ) ? (float) ( $prior['position'] ?? 0 ) : null;

		$metric = $this->metric( 'position', __( 'Avg. position', 'specflux-marketing-analytics-chat' ), (float) $current_position, (float) $prior_position, 'position', true );

		$metric['current']  = $current_position;
		$metric['previous'] = $prior_position;
		$metric['change']   = ( null === $current_position || null === $prior_position ) ? null : $metric['change'];

		return $metric;
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
	 * The on-site analytics plugin reader (tests pass a double under the `localstats` client key).
	 *
	 * @return Local_Analytics_Reader
	 */
	private function local_reader() {
		return $this->clients[ self::LOCAL_PLATFORM ] ?? new Local_Analytics_Reader();
	}

	/**
	 * Collect the traffic headline from the on-site analytics plugin (GA4 fallback).
	 *
	 * @param array $range Current and previous windows (same as GA4).
	 * @return array Same shape as collect_ga4(), plus `source_label` and `answered_by`.
	 */
	private function collect_localstats( array $range ) {
		$reader  = $this->local_reader();
		$bounds  = Local_Analytics_Reader::build_range(
			array(
				'start_date' => $range['current'][0],
				'end_date'   => $range['current'][1],
			)
		);
		$traffic = $reader->get_traffic( $bounds );
		$totals  = $traffic['totals'];
		$prev    = $traffic['previous_totals'];

		$rows   = array();
		$rows[] = $this->metric( 'visitors', __( 'Visitors', 'specflux-marketing-analytics-chat' ), (float) $totals['visitors'], (float) $prev['visitors'] );
		$rows[] = $this->metric( 'pageviews', __( 'Page views', 'specflux-marketing-analytics-chat' ), (float) $totals['pageviews'], (float) $prev['pageviews'] );

		$pages   = array();
		$sources = array();
		try {
			foreach ( $reader->get_top_pages( $bounds, 5 )['items'] as $item ) {
				$pages[] = array(
					'label' => (string) $item['path'],
					'value' => (float) $item['pageviews'],
				);
			}
			foreach ( $reader->get_referrers( $bounds, 3 )['items'] as $item ) {
				$sources[] = array(
					'label' => (string) $item['source'],
					'value' => (float) ( null !== $item['pageviews'] ? $item['pageviews'] : $item['sessions'] ),
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Detail lists are best-effort.
		}

		return array(
			'metrics'      => $rows,
			'pages'        => $pages,
			'sources'      => $sources,
			'range'        => $range['current'],
			'previous'     => $range['previous'],
			'source_label' => $traffic['source']['plugin'],
			'answered_by'  => $traffic['source']['answered_by'] ?? '',
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
			$this->position_metric( $current, $prior ),
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
				'key'   => 'sessions',
				'raw'   => (float) $by_name['Traffic']['totalSessionCount'],
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
