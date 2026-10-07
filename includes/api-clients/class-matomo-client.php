<?php
/**
 * Matomo Reporting API Client
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
 * Client for the Matomo Reporting HTTP API (self-hosted or Matomo Cloud).
 *
 * API documentation:
 * - Request format, token_auth, period=range: https://developer.matomo.org/api-reference/reporting-api-legacy
 * - Modules: https://developer.matomo.org/api-reference/api/visits-summary (and /actions, /referrers, /goals, /sites-manager, /api)
 *
 * Requests are POSTed to {matomo-url}/?module=API&method=Module.method&format=JSON. The auth
 * token and all other parameters travel in the POST body, as Matomo recommends for
 * token_auth ("make your API calls using the POST method with the token_auth in the body").
 * Matomo reports failures as HTTP 200 with {"result":"error","message":"..."}.
 */
class Matomo_Client {

	/**
	 * Cache/platform identifier
	 */
	const PLATFORM = 'matomo';

	/**
	 * Request timeout in seconds
	 */
	const TIMEOUT = 30;

	/**
	 * Matomo base URL (normalised, trailing slash)
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Matomo site ID
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Auth token
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Cache manager
	 *
	 * @var Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Constructor
	 *
	 * Resolves stored credentials when none are supplied. Explicit values exist for a pre-save test.
	 *
	 * @param string|null $url      Matomo URL, or null to use stored credentials.
	 * @param string|null $site_id  Site ID, or null to use stored credentials.
	 * @param string|null $token    Auth token, or null to use stored credentials.
	 * @throws \Exception If credentials are missing or the URL is not allowed.
	 */
	public function __construct( $url = null, $site_id = null, $token = null ) {
		if ( null === $url || null === $site_id || null === $token ) {
			$credential_manager = new Credential_Manager();
			$credentials        = $credential_manager->get_credentials( self::PLATFORM );

			if ( empty( $credentials['url'] ) || empty( $credentials['site_id'] ) || empty( $credentials['token_auth'] ) ) {
				throw new \Exception( 'Matomo is not connected. Go to Connections and add your Matomo URL, Site ID and Auth token.' );
			}

			$url     = $credentials['url'];
			$site_id = $credentials['site_id'];
			$token   = $credentials['token_auth'];
		}

		$normalized = self::normalize_url( (string) $url );
		if ( null === $normalized ) {
			throw new \Exception( 'The Matomo URL must start with https:// (http:// is only allowed for localhost).' );
		}

		$site_id = trim( (string) $site_id );
		if ( ! ctype_digit( $site_id ) || (int) $site_id < 1 ) {
			throw new \Exception( 'The Matomo Site ID must be a positive number.' );
		}

		$this->url           = $normalized;
		$this->site_id       = (int) $site_id;
		$this->token         = trim( (string) $token );
		$this->cache_manager = new Cache_Manager();
	}

	/**
	 * Validate and normalise a Matomo base URL
	 *
	 * Only https is accepted, plus http for localhost. Query strings, fragments and
	 * credentials in the URL are rejected so a token cannot be pasted into it.
	 *
	 * @param string $url Raw URL.
	 * @return string|null Normalised URL ending in a slash, or null when not allowed.
	 */
	public static function normalize_url( $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );

		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local ) ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$path = preg_replace( '#/index\.php$#', '', $path );

		return $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . rtrim( $path, '/' ) . '/';
	}

	/**
	 * Test the connection
	 *
	 * Calls API.getMatomoVersion (reachable and a Matomo) then SitesManager.getSiteFromId
	 * (token valid and has view access to the site).
	 *
	 * @return array Array with 'success' and 'message'.
	 */
	public function test_connection() {
		try {
			$version = $this->request( 'API.getMatomoVersion', array(), false );
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach Matomo: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}

		$version = is_array( $version ) && isset( $version['value'] ) ? (string) $version['value'] : '';

		try {
			$site = $this->request( 'SitesManager.getSiteFromId', array(), true );
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: Matomo version, 2: error message. */
					__( 'Matomo %1$s answered, but the Site ID or Auth token was not accepted: %2$s', 'specflux-marketing-analytics-chat' ),
					$version,
					$e->getMessage()
				),
			);
		}

		if ( is_array( $site ) && isset( $site[0] ) && is_array( $site[0] ) ) {
			$site = $site[0];
		}
		$name = is_array( $site ) && ! empty( $site['name'] ) ? (string) $site['name'] : '';

		if ( '' === $name ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: site ID. */
					__( 'Matomo answered, but no site with ID %d was returned. Check the Site ID.', 'specflux-marketing-analytics-chat' ),
					$this->site_id
				),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: site name, 2: site ID, 3: Matomo version. */
				__( 'Connected to Matomo: %1$s (site ID %2$d, Matomo %3$s).', 'specflux-marketing-analytics-chat' ),
				$name,
				$this->site_id,
				$version
			),
		);
	}

	/**
	 * Get visit totals for a range with change against the previous period
	 *
	 * The previous period is the range of equal length ending the day before start_date.
	 *
	 * @param string $start_date Inclusive Y-m-d.
	 * @param string $end_date   Inclusive Y-m-d.
	 * @return array Array with 'current', 'previous' and 'change'.
	 * @throws \Exception On API failure.
	 */
	public function get_traffic( $start_date, $end_date ) {
		$current = self::parse_visits_summary( $this->report( 'VisitsSummary.get', $start_date, $end_date ) );

		$days       = (int) ( new \DateTimeImmutable( $start_date ) )->diff( new \DateTimeImmutable( $end_date ) )->days + 1;
		$prev_end   = ( new \DateTimeImmutable( $start_date ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$prev_start = ( new \DateTimeImmutable( $prev_end ) )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$previous   = self::parse_visits_summary( $this->report( 'VisitsSummary.get', $prev_start, $prev_end ) );

		return array(
			'current'  => $current,
			'previous' => array_merge(
				array(
					'start_date' => $prev_start,
					'end_date'   => $prev_end,
				),
				$previous
			),
			'change'   => self::compute_change( $current, $previous ),
		);
	}

	/**
	 * Get top pages by page views (full URLs, not grouped by folder)
	 *
	 * Uses Actions.getPageUrls with flat=1.
	 *
	 * @param string $start_date Inclusive Y-m-d.
	 * @param string $end_date   Inclusive Y-m-d.
	 * @param int    $limit      Maximum rows.
	 * @return array Rows.
	 * @throws \Exception On API failure.
	 */
	public function get_top_pages( $start_date, $end_date, $limit ) {
		$data = $this->report(
			'Actions.getPageUrls',
			$start_date,
			$end_date,
			array(
				'flat'               => 1,
				'filter_limit'       => (int) $limit,
				'filter_sort_column' => 'nb_hits',
				'filter_sort_order'  => 'desc',
				'showColumns'        => 'nb_hits,nb_visits,entry_nb_visits,avg_time_on_page,bounce_rate,exit_rate',
			),
			(int) $limit
		);

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'page'                 => (string) ( $row['label'] ?? '' ),
				'url'                  => (string) ( $row['url'] ?? '' ),
				'pageviews'            => (int) ( $row['nb_hits'] ?? 0 ),
				'visits'               => (int) ( $row['nb_visits'] ?? 0 ),
				'entrances'            => (int) ( $row['entry_nb_visits'] ?? 0 ),
				'avg_time_on_page_sec' => (int) ( $row['avg_time_on_page'] ?? 0 ),
				'bounce_rate'          => self::parse_percent( $row['bounce_rate'] ?? null ),
				'exit_rate'            => self::parse_percent( $row['exit_rate'] ?? null ),
			);
		}

		return $rows;
	}

	/**
	 * Get traffic sources: referrer types, websites, search engines and campaigns
	 *
	 * Referrer types are required; the other breakdowns are best-effort and reported in
	 * 'warnings' when Matomo refuses them (for example a restricted token).
	 *
	 * @param string $start_date Inclusive Y-m-d.
	 * @param string $end_date   Inclusive Y-m-d.
	 * @param int    $limit      Maximum rows per breakdown.
	 * @return array Array with 'referrer_types', 'websites', 'search_engines', 'campaigns', 'warnings'.
	 * @throws \Exception If the referrer type report fails.
	 */
	public function get_sources( $start_date, $end_date, $limit ) {
		$columns = array( 'showColumns' => 'nb_visits,nb_actions,bounce_count,sum_visit_length,nb_visits_converted' );

		$out = array(
			'referrer_types' => self::parse_referrer_rows( $this->report( 'Referrers.getReferrerType', $start_date, $end_date, $columns ) ),
			'warnings'       => array(),
		);

		$optional = array(
			'websites'       => array( 'Referrers.getWebsites', array( 'flat' => 0 ) ),
			'search_engines' => array( 'Referrers.getSearchEngines', array() ),
			'campaigns'      => array( 'Referrers.getCampaigns', array() ),
		);

		foreach ( $optional as $key => $spec ) {
			try {
				$params = array_merge( $columns, $spec[1], array( 'filter_limit' => (int) $limit ) );
				$data   = $this->report( $spec[0], $start_date, $end_date, $params, (int) $limit );
				$out[ $key ] = self::parse_referrer_rows( $data );
			} catch ( \Exception $e ) {
				$out[ $key ]         = array();
				$out['warnings'][] = $key . ': ' . $e->getMessage();
			}
		}

		return $out;
	}

	/**
	 * Get goal conversions: overall and per goal
	 *
	 * @param string $start_date Inclusive Y-m-d.
	 * @param string $end_date   Inclusive Y-m-d.
	 * @return array Array with 'totals' and 'goals'.
	 * @throws \Exception On API failure.
	 */
	public function get_goals( $start_date, $end_date ) {
		$data = $this->report( 'Goals.get', $start_date, $end_date, array( 'showAllGoalSpecificMetrics' => 1 ) );
		$data = is_array( $data ) ? $data : array();

		$names = array();
		try {
			$defs = $this->request( 'Goals.getGoals', array(), true, 'goal-defs', HOUR_IN_SECONDS );
			foreach ( is_array( $defs ) ? $defs : array() as $def ) {
				if ( is_array( $def ) && isset( $def['idgoal'] ) ) {
					$names[ (int) $def['idgoal'] ] = (string) ( $def['name'] ?? '' );
				}
			}
		} catch ( \Exception $e ) {
			$names = array();
		}

		$visits = (int) ( $data['nb_visits'] ?? 0 );
		$goals  = array();
		foreach ( $data as $column => $value ) {
			if ( preg_match( '/^goal_(\d+)_nb_conversions$/', (string) $column, $m ) ) {
				$id      = (int) $m[1];
				$visited = (int) ( $data[ 'goal_' . $id . '_nb_visits_converted' ] ?? 0 );

				$goals[] = array(
					'goal_id'         => $id,
					'name'            => $names[ $id ] ?? '',
					'conversions'     => (int) $value,
					'revenue'         => round( (float) ( $data[ 'goal_' . $id . '_revenue' ] ?? 0 ), 2 ),
					'conversion_rate' => $visits > 0 ? round( $visited / $visits, 4 ) : 0,
				);
			}
		}

		usort(
			$goals,
			static function ( $a, $b ) {
				return $b['conversions'] <=> $a['conversions'];
			}
		);

		return array(
			'totals' => array(
				'visits'          => $visits,
				'conversions'     => (int) ( $data['nb_conversions'] ?? 0 ),
				'visits_converted' => (int) ( $data['nb_visits_converted'] ?? 0 ),
				'revenue'         => round( (float) ( $data['revenue'] ?? 0 ), 2 ),
				'conversion_rate' => self::parse_percent( $data['conversion_rate'] ?? null ),
			),
			'goals'  => $goals,
		);
	}

	/**
	 * Normalise a VisitsSummary.get payload
	 *
	 * Unique visitors are null when Matomo does not return them: for period=range
	 * Matomo only computes them when enabled in its config.
	 *
	 * @param mixed $data Decoded payload.
	 * @return array Normalised metrics.
	 */
	public static function parse_visits_summary( $data ) {
		$data = is_array( $data ) ? $data : array();

		return array(
			'visits'              => (int) ( $data['nb_visits'] ?? 0 ),
			'unique_visitors'     => isset( $data['nb_uniq_visitors'] ) ? (int) $data['nb_uniq_visitors'] : null,
			'actions'             => (int) ( $data['nb_actions'] ?? 0 ),
			'bounce_rate'         => self::parse_percent( $data['bounce_rate'] ?? null ),
			'avg_time_on_site_sec' => (int) ( $data['avg_time_on_site'] ?? 0 ),
			'actions_per_visit'   => round( (float) ( $data['nb_actions_per_visit'] ?? 0 ), 2 ),
			'visits_converted'    => (int) ( $data['nb_visits_converted'] ?? 0 ),
		);
	}

	/**
	 * Convert Matomo's "67%" strings (or plain numbers) to a 0-1 ratio
	 *
	 * @param mixed $value Value.
	 * @return float|null Ratio, or null when absent.
	 */
	public static function parse_percent( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$number = (float) str_replace( '%', '', (string) $value );
		return round( $number / 100, 4 );
	}

	/**
	 * Compute absolute and percentage change between two metric sets
	 *
	 * @param array $current  Current metrics.
	 * @param array $previous Previous metrics.
	 * @return array Metric => array( absolute, percent ).
	 */
	public static function compute_change( array $current, array $previous ) {
		$change = array();
		foreach ( array( 'visits', 'unique_visitors', 'actions', 'bounce_rate', 'avg_time_on_site_sec', 'visits_converted' ) as $metric ) {
			if ( null === ( $current[ $metric ] ?? null ) || null === ( $previous[ $metric ] ?? null ) ) {
				$change[ $metric ] = null;
				continue;
			}
			$delta             = $current[ $metric ] - $previous[ $metric ];
			$change[ $metric ] = array(
				'absolute' => is_float( $delta ) ? round( $delta, 4 ) : $delta,
				'percent'  => 0 != $previous[ $metric ] ? round( $delta / $previous[ $metric ] * 100, 1 ) : null, // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			);
		}
		return $change;
	}

	/**
	 * Normalise referrer rows (referrer types, websites, search engines, campaigns)
	 *
	 * @param mixed $data Decoded payload.
	 * @return array Rows.
	 */
	public static function parse_referrer_rows( $data ) {
		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$visits = (int) ( $row['nb_visits'] ?? 0 );
			$rows[] = array(
				'name'                 => (string) ( $row['label'] ?? '' ),
				'visits'               => $visits,
				'actions'              => (int) ( $row['nb_actions'] ?? 0 ),
				'bounce_rate'          => $visits > 0 && isset( $row['bounce_count'] ) ? round( (int) $row['bounce_count'] / $visits, 4 ) : null,
				'avg_time_on_site_sec' => $visits > 0 && isset( $row['sum_visit_length'] ) ? (int) round( (int) $row['sum_visit_length'] / $visits ) : null,
				'visits_converted'     => (int) ( $row['nb_visits_converted'] ?? 0 ),
			);
		}
		return $rows;
	}

	/**
	 * Fetch (and cache) a date-ranged report
	 *
	 * @param string   $method     API method.
	 * @param string   $start_date Y-m-d.
	 * @param string   $end_date   Y-m-d.
	 * @param array    $extra      Extra request parameters.
	 * @param int|null $limit      Row limit, only used to key the cache.
	 * @return mixed Decoded payload.
	 * @throws \Exception On failure.
	 */
	private function report( $method, $start_date, $end_date, array $extra = array(), $limit = null ) {
		$params = array_merge(
			$extra,
			array(
				'period' => 'range',
				'date'   => $start_date . ',' . $end_date,
			)
		);

		return $this->request( $method, $params, true, md5( wp_json_encode( $extra ) ) . ':' . $start_date . ':' . $end_date . ':' . (int) $limit, null );
	}

	/**
	 * Perform a POST request against the Reporting API
	 *
	 * @param string      $method    API method (Module.method).
	 * @param array       $params    Body parameters.
	 * @param bool        $with_site Whether to send idSite.
	 * @param string|null $cache_key Cache discriminator, or null to skip caching.
	 * @param int|null    $ttl       Cache TTL override.
	 * @return mixed Decoded payload.
	 * @throws \Exception On transport, HTTP or API errors.
	 */
	private function request( $method, array $params = array(), $with_site = true, $cache_key = null, $ttl = null ) {
		if ( null === $cache_key ) {
			return $this->do_request( $method, $params, $with_site );
		}

		return $this->cache_manager->remember(
			self::PLATFORM,
			$method,
			array(
				'site' => $this->site_id,
				'host' => md5( $this->url ),
				'key'  => $cache_key,
			),
			function () use ( $method, $params, $with_site ) {
				return $this->do_request( $method, $params, $with_site );
			},
			$ttl
		);
	}

	/**
	 * Send the HTTP request
	 *
	 * @param string $method    API method.
	 * @param array  $params    Body parameters.
	 * @param bool   $with_site Whether to send idSite.
	 * @return mixed Decoded payload.
	 * @throws \Exception On failure.
	 */
	private function do_request( $method, array $params, $with_site ) {
		$url = $this->url . '?' . http_build_query(
			array(
				'module' => 'API',
				'method' => $method,
				'format' => 'JSON',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$body = array_merge( $params, array( 'token_auth' => $this->token ) );
		if ( $with_site ) {
			$body['idSite'] = $this->site_id;
		}

		Logger::debug( sprintf( 'Matomo: POST %s', $method ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Could not reach Matomo: ' . $this->scrub( $response->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// Matomo reports API errors as HTTP 200 with {"result":"error","message":"..."}.
		if ( is_array( $decoded ) && isset( $decoded['result'] ) && 'error' === $decoded['result'] ) {
			$message = ! empty( $decoded['message'] ) ? (string) $decoded['message'] : 'Unknown Matomo error';
			throw new \Exception( $this->scrub( wp_strip_all_tags( $message ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( $code >= 400 ) {
			throw new \Exception( 'Matomo returned HTTP ' . (int) $code . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! is_array( $decoded ) ) {
			throw new \Exception( 'Unexpected response from Matomo. Check that the Matomo URL points at your Matomo installation.' );
		}

		return $decoded;
	}

	/**
	 * Remove the token from text that may be surfaced to users or logs
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function scrub( $text ) {
		$text = '' === $this->token ? $text : str_replace( $this->token, '[redacted]', $text );
		return preg_replace( '/(token_auth=)[^&\s]+/i', '$1[redacted]', $text );
	}
}
