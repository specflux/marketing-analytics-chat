<?php
/**
 * Bing Webmaster Tools API Client
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\API_Clients;

use Specflux_Marketing_Analytics\Cache\Cache_Manager;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Utils\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Client for the Bing Webmaster Tools JSON API.
 *
 * API Documentation: https://learn.microsoft.com/en-us/bingwebmaster/getting-started
 * Endpoint form:     GET https://ssl.bing.com/webmaster/api.svc/json/{Method}?siteUrl={url}&apikey={key}
 * Success responses wrap the payload as {"d": ...}; errors are HTTP 400 with
 * {"ErrorCode": 3, "Message": "InvalidApiKey"}. Dates arrive as "/Date(ms-0700)/".
 *
 * Bing returns fixed history per method (traffic is daily, query and page
 * stats are weekly buckets, crawl stats cover about six months), so rows are
 * cached whole and filtered to the requested window afterwards.
 */
class Bing_Webmaster_Client {

	/**
	 * API base URL
	 */
	const API_BASE_URL = 'https://ssl.bing.com/webmaster/api.svc/json/';

	/**
	 * Cache/platform identifier
	 */
	const PLATFORM = 'bing';

	/**
	 * Request timeout in seconds
	 */
	const TIMEOUT = 30;

	/**
	 * API key
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Verified site URL as registered in Bing Webmaster Tools
	 *
	 * @var string
	 */
	private $site_url;

	/**
	 * Cache manager
	 *
	 * @var Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Constructor
	 *
	 * Resolves stored credentials when none are supplied, matching the other
	 * clients. Explicit values exist for a pre-save connection test.
	 *
	 * @param string|null $api_key  API key, or null to use stored credentials.
	 * @param string|null $site_url Site URL, or null to use stored credentials.
	 * @throws \Exception If no credentials are supplied and none are stored.
	 */
	public function __construct( $api_key = null, $site_url = null ) {
		if ( null === $api_key || null === $site_url ) {
			$credential_manager = new Credential_Manager();
			$credentials        = $credential_manager->get_credentials( self::PLATFORM );

			if ( empty( $credentials['api_key'] ) || empty( $credentials['site_url'] ) ) {
				throw new \Exception( 'Bing Webmaster Tools is not connected. Go to Connections and add your Bing Webmaster API key and site URL.' );
			}

			$api_key  = $credentials['api_key'];
			$site_url = $credentials['site_url'];
		}

		$this->api_key       = (string) $api_key;
		$this->site_url      = (string) $site_url;
		$this->cache_manager = new Cache_Manager();
	}

	/**
	 * Get the configured site URL
	 *
	 * @return string
	 */
	public function get_site_url() {
		return $this->site_url;
	}

	/**
	 * Test the connection
	 *
	 * Succeeds only when the key is valid and the configured site is one of
	 * the account's verified sites.
	 *
	 * @return array Array with 'success' and 'message'.
	 */
	public function test_connection() {
		try {
			$sites = $this->request( 'GetUserSites' );
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message from Bing. */
					__( 'Bing Webmaster Tools connection failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}

		$sites    = is_array( $sites ) ? $sites : array();
		$wanted   = self::normalize_site( $this->site_url );
		$verified = array();
		$other    = array();

		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) || empty( $site['Url'] ) ) {
				continue;
			}
			if ( ! empty( $site['IsVerified'] ) ) {
				$verified[] = $site['Url'];
			} else {
				$other[] = $site['Url'];
			}
		}

		foreach ( $verified as $url ) {
			if ( self::normalize_site( $url ) === $wanted ) {
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: %s: site URL. */
						__( 'Connected to Bing Webmaster Tools for %s.', 'specflux-marketing-analytics-chat' ),
						$url
					),
				);
			}
		}

		foreach ( $other as $url ) {
			if ( self::normalize_site( $url ) === $wanted ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: site URL. */
						__( 'The site %s is in your Bing Webmaster Tools account but is not verified yet. Verify it in Bing Webmaster Tools and try again.', 'specflux-marketing-analytics-chat' ),
						$this->site_url
					),
				);
			}
		}

		if ( empty( $verified ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: site URL. */
					__( 'The API key is valid, but no verified sites were found in this Bing Webmaster Tools account. Add and verify %s first.', 'specflux-marketing-analytics-chat' ),
					$this->site_url
				),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: 1: configured site URL, 2: comma-separated list of verified sites. */
				__( 'The API key is valid, but %1$s is not one of your verified sites. Use the exact URL from Bing Webmaster Tools. Verified sites: %2$s', 'specflux-marketing-analytics-chat' ),
				$this->site_url,
				implode( ', ', $verified )
			),
		);
	}

	/**
	 * Get daily clicks and impressions
	 *
	 * @param string|null $start_date Inclusive Y-m-d start, or null for all history.
	 * @param string|null $end_date   Inclusive Y-m-d end, or null for all history.
	 * @return array List of array( date, clicks, impressions ) sorted by date.
	 */
	public function get_rank_and_traffic_stats( $start_date = null, $end_date = null ) {
		$rows = $this->cached_rows( 'GetRankAndTrafficStats' );
		$rows = self::filter_by_period( $rows, $start_date, $end_date );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'date'        => $row['Date'],
				'clicks'      => (int) ( $row['Clicks'] ?? 0 ),
				'impressions' => (int) ( $row['Impressions'] ?? 0 ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $out;
	}

	/**
	 * Get queries aggregated over a period
	 *
	 * @param string|null $start_date Inclusive Y-m-d start.
	 * @param string|null $end_date   Inclusive Y-m-d end.
	 * @param int         $limit      Maximum rows.
	 * @return array Rows sorted by clicks, then impressions.
	 */
	public function get_query_stats( $start_date = null, $end_date = null, $limit = 25 ) {
		return $this->aggregate_stats( 'GetQueryStats', 'query', $start_date, $end_date, $limit );
	}

	/**
	 * Get pages aggregated over a period
	 *
	 * @param string|null $start_date Inclusive Y-m-d start.
	 * @param string|null $end_date   Inclusive Y-m-d end.
	 * @param int         $limit      Maximum rows.
	 * @return array Rows sorted by clicks, then impressions.
	 */
	public function get_page_stats( $start_date = null, $end_date = null, $limit = 25 ) {
		return $this->aggregate_stats( 'GetPageStats', 'page', $start_date, $end_date, $limit );
	}

	/**
	 * Get crawl statistics
	 *
	 * @param string|null $start_date Inclusive Y-m-d start.
	 * @param string|null $end_date   Inclusive Y-m-d end.
	 * @return array Daily crawl rows sorted by date.
	 */
	public function get_crawl_stats( $start_date = null, $end_date = null ) {
		$rows = $this->cached_rows( 'GetCrawlStats' );
		$rows = self::filter_by_period( $rows, $start_date, $end_date );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'date'                  => $row['Date'],
				'crawled_pages'         => (int) ( $row['CrawledPages'] ?? 0 ),
				'crawl_errors'          => (int) ( $row['CrawlErrors'] ?? 0 ),
				'in_index'              => (int) ( $row['InIndex'] ?? 0 ),
				'in_links'              => (int) ( $row['InLinks'] ?? 0 ),
				'blocked_by_robots_txt' => (int) ( $row['BlockedByRobotsTxt'] ?? 0 ),
				'contains_malware'      => (int) ( $row['ContainsMalware'] ?? 0 ),
				'http_2xx'              => (int) ( $row['Code2xx'] ?? 0 ),
				'http_301'              => (int) ( $row['Code301'] ?? 0 ),
				'http_302'              => (int) ( $row['Code302'] ?? 0 ),
				'http_4xx'              => (int) ( $row['Code4xx'] ?? 0 ),
				'http_5xx'              => (int) ( $row['Code5xx'] ?? 0 ),
				'other_status_codes'    => (int) ( $row['AllOtherCodes'] ?? 0 ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $out;
	}

	/**
	 * Convert a Bing date to Y-m-d
	 *
	 * Handles "/Date(1316156400000-0700)/" (the offset is applied so the result
	 * is the site's local calendar day, as shown in Bing Webmaster Tools) and
	 * ISO 8601 strings.
	 *
	 * @param mixed $value Raw date value.
	 * @return string|null Y-m-d, or null when unparseable.
	 */
	public static function parse_date( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		if ( preg_match( '#^/Date\((-?\d+)(?:([+-])(\d{2})(\d{2}))?\)/$#', $value, $m ) ) {
			$seconds = intdiv( (int) $m[1], 1000 );
			if ( isset( $m[2] ) ) {
				$offset   = ( (int) $m[3] * 3600 ) + ( (int) $m[4] * 60 );
				$seconds += ( '-' === $m[2] ) ? -$offset : $offset;
			}
			return gmdate( 'Y-m-d', $seconds );
		}

		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $value, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Normalise a site URL for comparison
	 *
	 * @param string $url Site URL.
	 * @return string Lower-cased URL without trailing slash.
	 */
	public static function normalize_site( $url ) {
		return strtolower( rtrim( trim( (string) $url ), '/' ) );
	}

	/**
	 * Keep rows whose date falls inside an inclusive window
	 *
	 * @param array       $rows       Rows with a normalised 'Date' key.
	 * @param string|null $start_date Y-m-d or null.
	 * @param string|null $end_date   Y-m-d or null.
	 * @return array Filtered rows.
	 */
	public static function filter_by_period( array $rows, $start_date, $end_date ) {
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $start_date, $end_date ) {
					if ( empty( $row['Date'] ) ) {
						return false;
					}
					if ( null !== $start_date && $row['Date'] < $start_date ) {
						return false;
					}
					return null === $end_date || $row['Date'] <= $end_date;
				}
			)
		);
	}

	/**
	 * Aggregate weekly query/page rows over a window
	 *
	 * @param string      $method     API method.
	 * @param string      $label      Output key for the dimension ('query' or 'page').
	 * @param string|null $start_date Y-m-d or null.
	 * @param string|null $end_date   Y-m-d or null.
	 * @param int         $limit      Maximum rows.
	 * @return array Aggregated rows.
	 */
	private function aggregate_stats( $method, $label, $start_date, $end_date, $limit ) {
		$rows = self::filter_by_period( $this->cached_rows( $method ), $start_date, $end_date );

		$groups = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['Query'] ) ? (string) $row['Query'] : '';
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'clicks'      => 0,
					'impressions' => 0,
					'imp_pos'     => 0.0,
					'click_pos'   => 0.0,
				);
			}
			$clicks      = (int) ( $row['Clicks'] ?? 0 );
			$impressions = (int) ( $row['Impressions'] ?? 0 );

			$groups[ $key ]['clicks']      += $clicks;
			$groups[ $key ]['impressions'] += $impressions;
			$groups[ $key ]['imp_pos']     += (float) ( $row['AvgImpressionPosition'] ?? 0 ) * $impressions;
			$groups[ $key ]['click_pos']   += (float) ( $row['AvgClickPosition'] ?? 0 ) * $clicks;
		}

		$out = array();
		foreach ( $groups as $key => $g ) {
			$out[] = array(
				$label                  => (string) $key,
				'clicks'                => $g['clicks'],
				'impressions'           => $g['impressions'],
				'ctr'                   => $g['impressions'] > 0 ? round( $g['clicks'] / $g['impressions'], 4 ) : 0,
				'avg_impression_position' => $g['impressions'] > 0 ? round( $g['imp_pos'] / $g['impressions'], 1 ) : null,
				'avg_click_position'    => $g['clicks'] > 0 ? round( $g['click_pos'] / $g['clicks'], 1 ) : null,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] );
			}
		);

		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Fetch (and cache) a site-scoped method's rows with dates normalised
	 *
	 * @param string $method API method.
	 * @return array Rows with 'Date' converted to Y-m-d.
	 * @throws \Exception On API failure.
	 */
	private function cached_rows( $method ) {
		return $this->cache_manager->remember(
			self::PLATFORM,
			$method,
			array( 'site' => self::normalize_site( $this->site_url ) ),
			function () use ( $method ) {
				$data = $this->request( $method, array( 'siteUrl' => $this->site_url ) );
				$rows = array();

				foreach ( is_array( $data ) ? $data : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$row['Date'] = self::parse_date( $row['Date'] ?? null );
					if ( null !== $row['Date'] ) {
						$rows[] = $row;
					}
				}

				return $rows;
			}
		);
	}

	/**
	 * Perform a GET request and unwrap the {"d": ...} envelope
	 *
	 * @param string $method API method name.
	 * @param array  $params Query parameters (the API key is added here).
	 * @return mixed The unwrapped payload.
	 * @throws \Exception On transport, HTTP or API errors.
	 */
	private function request( $method, array $params = array() ) {
		$url = self::API_BASE_URL . rawurlencode( $method ) . '?' . http_build_query(
			array_merge( $params, array( 'apikey' => $this->api_key ) ),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		Logger::debug( sprintf( 'Bing: GET %s', $method ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Could not reach Bing Webmaster Tools: ' . $this->scrub( $response->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ( is_array( $body ) && isset( $body['ErrorCode'] ) ) ) {
			$message = is_array( $body ) && ! empty( $body['Message'] ) ? (string) $body['Message'] : 'HTTP ' . $code;
			// The live API prefixes messages with "ERROR!!! " (e.g. "ERROR!!! InvalidApiKey"); the docs omit it.
			$message = trim( preg_replace( '/^ERROR!+\s*/', '', $message ) );
			if ( 'InvalidApiKey' === $message ) {
				$message = 'Bing rejected the API key. Generate a new API key in Bing Webmaster Tools and save it again.';
			}
			throw new \Exception( $this->scrub( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! is_array( $body ) || ! array_key_exists( 'd', $body ) ) {
			throw new \Exception( 'Unexpected response from Bing Webmaster Tools.' );
		}

		return $body['d'];
	}

	/**
	 * Remove the API key from text that may be surfaced to users or logs
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function scrub( $text ) {
		return '' === $this->api_key ? $text : str_replace( $this->api_key, '[redacted]', $text );
	}
}
