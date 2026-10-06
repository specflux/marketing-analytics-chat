<?php
/**
 * PageSpeed Insights API Client
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\API_Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Specflux_Marketing_Analytics\Cache\Cache_Manager;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Client for the PageSpeed Insights v5 API.
 *
 * API Documentation: https://developers.google.com/speed/docs/insights/v5/get-started
 *
 * Every call runs a full Lighthouse audit (10-40s), so summaries are cached
 * per URL and strategy. The raw Lighthouse JSON is never returned.
 */
class PageSpeed_Client {

	/**
	 * API endpoint.
	 */
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Request timeout in seconds (Lighthouse runs are slow).
	 */
	const TIMEOUT = 60;

	/**
	 * Number of opportunities/diagnostics returned.
	 */
	const MAX_ISSUES = 5;

	/**
	 * Field-data metric keys => compact names.
	 */
	const FIELD_METRICS = array(
		'LARGEST_CONTENTFUL_PAINT_MS'    => 'lcp_ms',
		'INTERACTION_TO_NEXT_PAINT'      => 'inp_ms',
		'CUMULATIVE_LAYOUT_SHIFT_SCORE'  => 'cls',
		'FIRST_CONTENTFUL_PAINT_MS'      => 'fcp_ms',
		'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb_ms',
	);

	/**
	 * Lab audit ids => compact names.
	 */
	const LAB_METRICS = array(
		'largest-contentful-paint' => 'lcp',
		'cumulative-layout-shift'  => 'cls',
		'total-blocking-time'      => 'tbt',
		'first-contentful-paint'   => 'fcp',
		'speed-index'              => 'speed_index',
		'interactive'              => 'tti',
	);

	/**
	 * Saved API key, or empty string.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * HTTP transport.
	 *
	 * @var Client
	 */
	private $http_client;

	/**
	 * Cache manager.
	 *
	 * @var Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Constructor. Never throws; without a saved key requests are sent keyless and will likely hit Google's shared quota.
	 *
	 * @param string|null $api_key     Key override; null reads the stored key.
	 * @param Client|null $http_client Transport; tests pass a Guzzle MockHandler client.
	 */
	public function __construct( $api_key = null, $http_client = null ) {
		if ( null === $api_key ) {
			$credentials = ( new Credential_Manager() )->get_credentials( 'pagespeed' );
			$api_key     = is_array( $credentials ) && ! empty( $credentials['api_key'] ) ? (string) $credentials['api_key'] : '';
		}

		$this->api_key       = trim( (string) $api_key );
		$this->cache_manager = new Cache_Manager();
		$this->http_client   = $http_client instanceof Client ? $http_client : new Client(
			array(
				'timeout'     => self::TIMEOUT,
				'http_errors' => false,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
	}

	/**
	 * Resolve and validate a URL or path against the site's own host.
	 *
	 * @param string $url Empty (home page), a path like /pricing/, or a full URL.
	 * @return string Absolute URL.
	 * @throws \InvalidArgumentException When the URL is not on an allowed host.
	 */
	public function resolve_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return home_url( '/' );
		}

		if ( '/' === $url[0] && ( strlen( $url ) < 2 || '/' !== $url[1] ) ) {
			return home_url( $url );
		}

		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$http  = is_array( $parts ) && isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed   = array( $site_host );

		/**
		 * Filter the hosts PageSpeed checks may target (default: the site's own host).
		 *
		 * @param string[] $allowed Allowed hostnames.
		 */
		$allowed = array_map( 'strtolower', (array) apply_filters( 'specflux_mac_pagespeed_allowed_hosts', $allowed ) );

		if ( ! $http || '' === $host || ! in_array( $host, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Only URLs on this site\'s own host (' . $site_host . ') can be checked.' );
		}

		return $url;
	}

	/**
	 * Normalise the strategy argument.
	 *
	 * @param string $strategy mobile|desktop.
	 * @return string
	 */
	public static function normalize_strategy( $strategy ) {
		return 'desktop' === strtolower( (string) $strategy ) ? 'desktop' : 'mobile';
	}

	/**
	 * Whether a summary for this URL and strategy is already cached.
	 *
	 * @param string $url      URL or path.
	 * @param string $strategy mobile|desktop.
	 * @return bool
	 */
	public function is_cached( $url, $strategy ) {
		$url = $this->resolve_url( $url );

		return $this->cache_manager->has( $this->cache_key( $url, self::normalize_strategy( $strategy ) ) );
	}

	/**
	 * Get a compact performance summary (cached).
	 *
	 * @param string $url      URL or path ('' for the home page).
	 * @param string $strategy mobile|desktop.
	 * @return array Summary.
	 * @throws \Exception On invalid URL, quota or API failure.
	 */
	public function get_summary( $url = '', $strategy = 'mobile' ) {
		$url      = $this->resolve_url( $url );
		$strategy = self::normalize_strategy( $strategy );

		$key  = $this->cache_key( $url, $strategy );
		$data = $this->cache_manager->get( $key );
		if ( false !== $data ) {
			return $data;
		}

		$summary = self::extract_summary( $this->run( $url, $strategy ), $strategy );
		$this->cache_manager->set( $key, $summary, $this->cache_manager->get_default_ttl( 'pagespeed' ) );

		return $summary;
	}

	/**
	 * Cron hook that runs one uncached PageSpeed measurement in the background.
	 */
	const CRON_HOOK = 'specflux_mac_pagespeed_run';

	/**
	 * Seconds a queued or running measurement holds its dedupe lock.
	 */
	const LOCK_TTL = 180;

	/**
	 * Seconds of remaining PHP time needed to run a Lighthouse audit inline.
	 */
	const SYNC_MIN_SECONDS = 50;

	/**
	 * Get a summary, or queue a background run when an inline run is risky.
	 *
	 * Cached results are returned as-is. Never returns a fabricated score.
	 *
	 * @param string $url      URL or path ('' for the home page).
	 * @param string $strategy mobile|desktop.
	 * @return array Summary, or a `status => queued` result.
	 * @throws \Exception On invalid URL, quota or API failure (synchronous runs only).
	 */
	public function get_summary_or_queue( $url = '', $strategy = 'mobile' ) {
		$url      = $this->resolve_url( $url );
		$strategy = self::normalize_strategy( $strategy );

		if ( $this->is_cached( $url, $strategy ) || self::can_run_sync() ) {
			return $this->get_summary( $url, $strategy );
		}

		$this->queue( $url, $strategy );

		return self::queued_result( $url, $strategy );
	}

	/**
	 * Whether an uncached run may happen inside the current request.
	 *
	 * True only with at least 50s of PHP time left (0 = unlimited) and the
	 * `specflux_mac_pagespeed_sync` filter allowing it.
	 *
	 * @return bool
	 */
	public static function can_run_sync() {
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 ) {
			$started   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			$remaining = $max_exec - ( microtime( true ) - $started );
			if ( $remaining < self::SYNC_MIN_SECONDS ) {
				return false;
			}
		}

		/**
		 * Filter whether PageSpeed may run synchronously when time allows.
		 *
		 * @param bool $allow Default true.
		 */
		return (bool) apply_filters( 'specflux_mac_pagespeed_sync', true );
	}

	/**
	 * Structured "queued" tool result.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $strategy Strategy.
	 * @return array
	 */
	public static function queued_result( $url, $strategy ) {
		return array(
			'status'              => 'queued',
			'url'                 => $url,
			'strategy'            => $strategy,
			'message'             => 'Measuring this page now; ask again in about a minute.',
			'retry_after_seconds' => 60,
		);
	}

	/**
	 * Queue one background run for a URL + strategy.
	 *
	 * One event per URL + strategy: skipped when already scheduled or a run holds the lock.
	 *
	 * @param string $url      URL or path.
	 * @param string $strategy mobile|desktop.
	 * @return bool True when a new event was scheduled.
	 */
	public function queue( $url, $strategy ) {
		$url      = $this->resolve_url( $url );
		$strategy = self::normalize_strategy( $strategy );
		$args     = array( $url, $strategy );
		$lock     = self::lock_key( $url, $strategy );

		if ( false !== get_transient( $lock ) || false !== wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return false;
		}

		set_transient( $lock, 1, self::LOCK_TTL );
		if ( false === wp_schedule_single_event( time(), self::CRON_HOOK, $args ) ) {
			delete_transient( $lock );
			return false;
		}
		spawn_cron();

		return true;
	}

	/**
	 * Cron callback: measure one URL + strategy and store it in the cache.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $strategy mobile|desktop.
	 * @param self   $client   Client override (tests); cron passes only two arguments.
	 */
	public static function run_background( $url, $strategy = 'mobile', $client = null ) {
		$url      = (string) $url;
		$strategy = self::normalize_strategy( $strategy );

		set_transient( self::lock_key( $url, $strategy ), 1, self::LOCK_TTL );
		try {
			( $client instanceof self ? $client : new self() )->get_summary( $url, $strategy );
		} catch ( \Exception $e ) {
			// Failures are not cached; the next chat request queues a fresh run.
			unset( $e );
		}
		delete_transient( self::lock_key( $url, $strategy ) );
	}

	/**
	 * Lock transient name for a URL + strategy.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $strategy Strategy.
	 * @return string
	 */
	private static function lock_key( $url, $strategy ) {
		return 'specflux_mac_psi_lock_' . md5( $url . '|' . $strategy );
	}

	/**
	 * Build the request URL (key appended only when saved).
	 *
	 * @param string $url      Absolute page URL.
	 * @param string $strategy mobile|desktop.
	 * @return string
	 */
	public function build_request_url( $url, $strategy ) {
		$params = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		if ( '' !== $this->api_key ) {
			$params['key'] = $this->api_key;
		}

		return self::ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Cache key for a URL + strategy.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $strategy Strategy.
	 * @return string
	 */
	private function cache_key( $url, $strategy ) {
		return $this->cache_manager->generate_key(
			'pagespeed',
			'summary',
			array(
				'url'      => $url,
				'strategy' => $strategy,
			)
		);
	}

	/**
	 * Call the API.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $strategy Strategy.
	 * @return array Decoded response.
	 * @throws \Exception On transport or API errors.
	 */
	private function run( $url, $strategy ) {
		try {
			$response = $this->http_client->get( $this->build_request_url( $url, $strategy ), array( 'timeout' => self::TIMEOUT ) );
		} catch ( GuzzleException $e ) {
			throw new \Exception( 'PageSpeed Insights request failed or timed out: ' . $e->getMessage(), 0, $e );
		}

		$status = $response->getStatusCode();
		$body   = json_decode( (string) $response->getBody(), true );

		if ( $status >= 400 || ! is_array( $body ) || isset( $body['error'] ) ) {
			$api_message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';

			if ( 429 === $status ) {
				throw new \Exception( 'PageSpeed Insights quota used up. Check your API key under Connections > PageSpeed Insights (a key of your own has its own daily quota), or try again tomorrow.' );
			}
			if ( 403 === $status || false !== stripos( $api_message, 'API key not valid' ) || false !== stripos( (string) wp_json_encode( $body ), 'API_KEY_INVALID' ) ) {
				throw new \Exception( 'PageSpeed Insights rejected the API key. Check the key and that the PageSpeed Insights API is enabled for its Google Cloud project.' );
			}
			if ( 400 === $status ) {
				throw new \Exception( 'PageSpeed Insights could not analyze this URL (is it public and reachable?): ' . $api_message );
			}

			throw new \Exception( sprintf( 'PageSpeed Insights API error (HTTP %d): %s', $status, $api_message ) );
		}

		return $body;
	}

	/**
	 * Reduce a PSI response to a compact summary.
	 *
	 * @param array  $body     Decoded runPagespeed response.
	 * @param string $strategy Strategy used.
	 * @return array
	 */
	public static function extract_summary( array $body, $strategy = 'mobile' ) {
		$lh     = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] ) ? $body['lighthouseResult'] : array();
		$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();
		$score  = $lh['categories']['performance']['score'] ?? null;

		$lab = array();
		foreach ( self::LAB_METRICS as $audit_id => $name ) {
			if ( ! isset( $audits[ $audit_id ] ) ) {
				continue;
			}
			$lab[ $name ] = array(
				'value'         => $audits[ $audit_id ]['numericValue'] ?? null,
				'display_value' => $audits[ $audit_id ]['displayValue'] ?? null,
			);
		}

		return array(
			'url'        => $body['id'] ?? ( $lh['finalUrl'] ?? '' ),
			'strategy'   => $strategy,
			'fetched_at' => $body['analysisUTCTimestamp'] ?? null,
			'score'      => null === $score ? null : (int) round( $score * 100 ),
			'lab'        => $lab,
			'field'      => self::extract_field( $body['loadingExperience'] ?? null ),
			'origin'     => self::extract_field( $body['originLoadingExperience'] ?? null ),
			'issues'     => self::extract_issues( $lh ),
		);
	}

	/**
	 * Extract CrUX field data from a loadingExperience object.
	 *
	 * @param array|null $experience loadingExperience / originLoadingExperience.
	 * @return array|null Null when no field data exists.
	 */
	public static function extract_field( $experience ) {
		if ( ! is_array( $experience ) || empty( $experience['metrics'] ) || ! is_array( $experience['metrics'] ) ) {
			return null;
		}

		$metrics = array();
		foreach ( self::FIELD_METRICS as $key => $name ) {
			if ( ! isset( $experience['metrics'][ $key ]['percentile'] ) ) {
				continue;
			}
			$percentile = $experience['metrics'][ $key ]['percentile'];
			// PSI reports CLS multiplied by 100.
			$metrics[ $name ] = array(
				'percentile' => 'cls' === $name ? round( $percentile / 100, 2 ) : $percentile,
				'category'   => $experience['metrics'][ $key ]['category'] ?? null,
			);
		}

		if ( empty( $metrics ) ) {
			return null;
		}

		return array(
			'overall_category' => $experience['overall_category'] ?? null,
			'origin_fallback'  => ! empty( $experience['origin_fallback'] ),
			'metrics'          => $metrics,
		);
	}

	/**
	 * Core Web Vitals assessment from field data.
	 *
	 * @param array|null $field Result of extract_field().
	 * @return string passed|failed|no_data
	 */
	public static function assess( $field ) {
		if ( ! is_array( $field ) ) {
			return 'no_data';
		}

		$seen = 0;
		foreach ( array( 'lcp_ms', 'inp_ms', 'cls' ) as $name ) {
			if ( ! isset( $field['metrics'][ $name ]['category'] ) ) {
				continue;
			}
			++$seen;
			if ( 'FAST' !== $field['metrics'][ $name ]['category'] ) {
				return 'failed';
			}
		}

		return $seen > 0 ? 'passed' : 'no_data';
	}

	/**
	 * Top opportunities and diagnostics.
	 *
	 * @param array $lh lighthouseResult.
	 * @return array[]
	 */
	private static function extract_issues( array $lh ) {
		$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();
		$refs   = $lh['categories']['performance']['auditRefs'] ?? array();
		$issues = array();

		foreach ( (array) $refs as $ref ) {
			$group = $ref['group'] ?? '';
			$id    = $ref['id'] ?? '';
			if ( ! in_array( $group, array( 'load-opportunities', 'diagnostics' ), true ) || ! isset( $audits[ $id ] ) ) {
				continue;
			}

			$audit = $audits[ $id ];
			$score = $audit['score'] ?? null;
			if ( null === $score || $score >= 0.9 ) {
				continue;
			}

			$issues[] = array(
				'id'            => $id,
				'title'         => $audit['title'] ?? $id,
				'display_value' => $audit['displayValue'] ?? null,
				'savings_ms'    => isset( $audit['details']['overallSavingsMs'] ) ? (int) round( $audit['details']['overallSavingsMs'] ) : null,
				'savings_bytes' => isset( $audit['details']['overallSavingsBytes'] ) ? (int) round( $audit['details']['overallSavingsBytes'] ) : null,
				'score'         => $score,
			);
		}

		usort(
			$issues,
			static function ( $a, $b ) {
				return array( $b['savings_ms'] ?? 0, $b['savings_bytes'] ?? 0, $a['score'] )
					<=> array( $a['savings_ms'] ?? 0, $a['savings_bytes'] ?? 0, $b['score'] );
			}
		);

		$issues = array_slice( $issues, 0, self::MAX_ISSUES );
		foreach ( $issues as &$issue ) {
			unset( $issue['score'] );
		}
		unset( $issue );

		return $issues;
	}
}
