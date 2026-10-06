<?php
/**
 * Connection Tester
 *
 * Tests API connections for all analytics platforms.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Credentials;

use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Tests connections to analytics platforms
 */
class Connection_Tester {

	/**
	 * Credential Manager instance
	 *
	 * @var Credential_Manager
	 */
	private $credential_manager;

	/**
	 * OAuth Handler instance
	 *
	 * @var OAuth_Handler
	 */
	private $oauth_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->credential_manager = new Credential_Manager();
		$this->oauth_handler      = new OAuth_Handler();
	}

	/**
	 * Test connection to Microsoft Clarity
	 *
	 * @return array Test result with success status and message.
	 */
	public function test_clarity_connection() {
		$credentials = $this->credential_manager->get_credentials( 'clarity' );

		if ( empty( $credentials ) ) {
			return array(
				'success' => false,
				'message' => __( 'No Clarity credentials found.', 'specflux-marketing-analytics-chat' ),
			);
		}

		if ( empty( $credentials['api_token'] ) || empty( $credentials['project_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Clarity API token or project ID is missing.', 'specflux-marketing-analytics-chat' ),
			);
		}

		try {
			$client = new Clarity_Client();

			// Reads are cached for an hour to protect the 10-requests-per-day quota,
			// so the test uses the client's uncached path — a cached hit would report
			// a revoked token as working.
			$result = $client->test_connection();

			if ( ! empty( $result['success'] ) ) {
				return array(
					'success' => true,
					'message' => __( 'Successfully connected to Microsoft Clarity.', 'specflux-marketing-analytics-chat' ),
					'data'    => array(
						'project_id' => $credentials['project_id'],
					),
				);
			}

			if ( empty( $result['message'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'Clarity API returned empty response.', 'specflux-marketing-analytics-chat' ),
				);
			}

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Clarity connection failed: %s', 'specflux-marketing-analytics-chat' ),
					$result['message']
				),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Clarity connection failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Test connection to Google Analytics 4
	 *
	 * @return array Test result with success status and message.
	 */
	public function test_ga4_connection() {
		$access_token = $this->oauth_handler->get_access_token( 'ga4' );

		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'message' => __( 'No GA4 access token found. Please authorize Google Analytics.', 'specflux-marketing-analytics-chat' ),
			);
		}

		try {
			$client = new \Google\Client();
			$client->setAccessToken( $access_token );

			// Initialize Analytics Admin API to list properties.
			$analytics = new \Google\Service\GoogleAnalyticsAdmin( $client );

			// Try to list account summaries (lightweight test).
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SDK property name.
			$account_summaries = $analytics->accountSummaries->listAccountSummaries();

			if ( ! empty( $account_summaries ) ) {
				$summary_list   = $account_summaries->getAccountSummaries();
				$property_count = 0;

				if ( ! empty( $summary_list ) ) {
					foreach ( $summary_list as $summary ) {
						$property_summaries = $summary->getPropertySummaries();
						if ( ! empty( $property_summaries ) ) {
							$property_count += count( $property_summaries );
						}
					}
				}

				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: %d: number of properties */
						_n(
							'Successfully connected to Google Analytics. Found %d property.',
							'Successfully connected to Google Analytics. Found %d properties.',
							$property_count,
							'specflux-marketing-analytics-chat'
						),
						$property_count
					),
					'data'    => array(
						'property_count' => $property_count,
					),
				);
			}

			return array(
				'success' => false,
				'message' => __( 'GA4 API returned empty response.', 'specflux-marketing-analytics-chat' ),
			);
		} catch ( \Exception $e ) {
			// Check if token expired.
			if ( false !== strpos( $e->getMessage(), 'Invalid Credentials' ) ) {
				// Try to refresh token.
				if ( $this->oauth_handler->refresh_token( 'ga4' ) ) {
					return $this->test_ga4_connection(); // Retry once.
				}
			}

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'GA4 connection failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Test connection to Google Search Console
	 *
	 * @return array Test result with success status and message.
	 */
	public function test_gsc_connection() {
		$access_token = $this->oauth_handler->get_access_token( 'gsc' );

		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'message' => __( 'No GSC access token found. Please authorize Google Search Console.', 'specflux-marketing-analytics-chat' ),
			);
		}

		try {
			$client = new \Google\Client();
			$client->setAccessToken( $access_token );

			// Initialize Search Console API.
			$search_console = new \Google\Service\SearchConsole( $client );

			// Try to list sites (lightweight test).
			$sites_list = $search_console->sites->listSites();

			if ( ! empty( $sites_list ) ) {
				$sites      = $sites_list->getSiteEntry();
				$site_count = ! empty( $sites ) ? count( $sites ) : 0;

				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: %d: number of sites */
						_n(
							'Successfully connected to Google Search Console. Found %d site.',
							'Successfully connected to Google Search Console. Found %d sites.',
							$site_count,
							'specflux-marketing-analytics-chat'
						),
						$site_count
					),
					'data'    => array(
						'site_count' => $site_count,
					),
				);
			}

			return array(
				'success' => false,
				'message' => __( 'GSC API returned empty response.', 'specflux-marketing-analytics-chat' ),
			);
		} catch ( \Exception $e ) {
			// Check if token expired.
			if ( false !== strpos( $e->getMessage(), 'Invalid Credentials' ) ) {
				// Try to refresh token.
				if ( $this->oauth_handler->refresh_token( 'gsc' ) ) {
					return $this->test_gsc_connection(); // Retry once.
				}
			}

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'GSC connection failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Option holding the last connection test result for a platform.
	 *
	 * @param string $platform Platform slug.
	 * @return string Option name.
	 */
	private static function last_result_option( $platform ) {
		return 'specflux_mac_last_test_' . $platform;
	}

	/**
	 * Store a connection test result so status badges and the chat prompt can tell a saved-but-rejected key from a working one.
	 *
	 * @param string $platform Platform slug.
	 * @param array  $result   Result with 'success' and 'message'.
	 * @return array The same result.
	 */
	private static function remember_result( $platform, $result ) {
		$result = is_array( $result ) ? $result : array( 'success' => false );
		update_option(
			self::last_result_option( $platform ),
			array(
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
				'time'    => time(),
			),
			false
		);
		return $result;
	}

	/**
	 * Last stored connection test result for a platform.
	 *
	 * @param string $platform Platform slug.
	 * @return array|null Array with 'success', 'message' and 'time', or null if never tested.
	 */
	public static function get_last_result( $platform ) {
		$result = get_option( self::last_result_option( $platform ), null );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Forget the stored result, e.g. when the platform is disconnected.
	 *
	 * @param string $platform Platform slug.
	 */
	public static function forget_result( $platform ) {
		delete_option( self::last_result_option( $platform ) );
	}

	/**
	 * Run the connection test for a registered platform
	 *
	 * Uses the definition's `connection_test` callable when present, otherwise
	 * the built-in `test_{slug}_connection()` method (core platforms).
	 *
	 * @param string $platform Platform identifier.
	 * @return array Test result with success status and message.
	 */
	public function test_connection( $platform ) {
		$definition = Platform_Registry::get( $platform );

		if ( null === $definition ) {
			return array(
				'success' => false,
				'message' => __( 'Unsupported platform.', 'specflux-marketing-analytics-chat' ),
			);
		}

		if ( is_callable( $definition['connection_test'] ) ) {
			return self::remember_result( $platform, call_user_func( $definition['connection_test'] ) );
		}

		$method = 'test_' . $platform . '_connection';
		if ( method_exists( $this, $method ) ) {
			return self::remember_result( $platform, $this->$method() );
		}

		return array(
			'success' => false,
			'message' => __( 'This platform does not provide a connection test.', 'specflux-marketing-analytics-chat' ),
		);
	}

	/**
	 * Test all platform connections
	 *
	 * @return array Array of platform => test_result.
	 */
	public function test_all_connections() {
		$results = array();

		foreach ( Platform_Registry::slugs() as $platform ) {
			$results[ $platform ] = $this->test_connection( $platform );
		}

		return $results;
	}

	/**
	 * Get connection status for a platform
	 *
	 * @param string $platform Platform identifier.
	 * @return string Status: 'connected', 'not_configured', 'error'.
	 */
	public function get_connection_status( $platform ) {
		$definition = Platform_Registry::get( $platform );

		if ( null === $definition ) {
			return 'error';
		}

		if ( 'google_oauth' === $definition['auth'] ) {
			if ( ! $this->oauth_handler->get_access_token( $platform ) ) {
				return 'not_configured';
			}
		} elseif ( 'api_key' === $definition['auth'] && ! $this->credential_manager->has_credentials( $platform ) ) {
			return 'not_configured';
		}

		$result = $this->test_connection( $platform );
		return $result['success'] ? 'connected' : 'error';
	}

	/**
	 * Get connection statuses for all platforms
	 *
	 * @return array Array of platform => status.
	 */
	public function get_all_statuses() {
		$statuses = array();

		foreach ( Platform_Registry::slugs() as $platform ) {
			$statuses[ $platform ] = $this->get_connection_status( $platform );
		}

		return $statuses;
	}
}
