<?php
/**
 * Tests that exercise the API clients through their injected transport.
 *
 * These run entirely offline: Clarity is driven by a Guzzle MockHandler, GA4 and
 * GSC by stand-in service objects. Without the transport seam none of this is
 * reachable without live network access.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use PHPUnit\Framework\TestCase;

/**
 * API client transport test class.
 */
class ApiClientTransportTest extends TestCase {

	/**
	 * Reset stored options and cached transients between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_transients;
		$mock_options    = array();
		$mock_transients = array();
	}

	/**
	 * Build a Guzzle client backed by a queue of canned responses.
	 *
	 * @param array $responses Responses to return in order.
	 * @return Client Guzzle client wired to the mock handler.
	 */
	private function guzzle_returning( array $responses ): Client {
		return new Client( array( 'handler' => HandlerStack::create( new MockHandler( $responses ) ) ) );
	}

	/**
	 * Clarity insights are parsed from the HTTP response.
	 */
	public function test_clarity_parses_insights_from_transport(): void {
		$payload = array(
			array(
				'metricName'  => 'Traffic',
				'information' => array( array( 'totalSessionCount' => '128' ) ),
			),
		);

		$client = new Clarity_Client(
			'token',
			'project',
			$this->guzzle_returning( array( new Response( 200, array(), wp_json_encode( $payload ) ) ) )
		);

		$this->assertEquals( $payload, $client->get_insights( 1 ) );
	}

	/**
	 * A second Clarity read is served from cache rather than the transport.
	 *
	 * Only one response is queued: if the cache were bypassed, the MockHandler
	 * would run dry and the second call would throw.
	 */
	public function test_clarity_second_read_does_not_touch_transport(): void {
		$payload = array( array( 'metricName' => 'Traffic' ) );

		$client = new Clarity_Client(
			'token',
			'project',
			$this->guzzle_returning( array( new Response( 200, array(), wp_json_encode( $payload ) ) ) )
		);

		$first  = $client->get_insights( 2 );
		$second = $client->get_insights( 2 );

		$this->assertEquals( $payload, $first );
		$this->assertEquals( $first, $second );
	}

	/**
	 * The connection test reaches the transport even when a read is cached.
	 *
	 * The first response caches a successful read; the second is the revoked
	 * token. A connection test served from cache would report success.
	 */
	public function test_clarity_connection_test_bypasses_the_read_cache(): void {
		$client = new Clarity_Client(
			'token',
			'project',
			$this->guzzle_returning(
				array(
					new Response( 200, array(), wp_json_encode( array( array( 'metricName' => 'Traffic' ) ) ) ),
					new Response( 401, array(), 'Unauthorized' ),
				)
			)
		);

		$client->get_insights( 1 );
		$result = $client->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '401', $result['message'] );
	}

	/**
	 * Clarity surfaces a transport failure as false rather than raising.
	 */
	public function test_clarity_returns_false_on_transport_error(): void {
		$client = new Clarity_Client(
			'token',
			'project',
			$this->guzzle_returning( array( new Response( 429, array(), 'Too Many Requests' ) ) )
		);

		$this->assertFalse( $client->get_insights( 1 ) );
	}

	/**
	 * An invalid day count is rejected before the transport is reached.
	 */
	public function test_clarity_rejects_unsupported_day_count(): void {
		$client = new Clarity_Client( 'token', 'project', $this->guzzle_returning( array() ) );

		$this->assertFalse( $client->get_insights( 7 ) );
	}

	/**
	 * GA4 rows and headers are flattened into the reporting shape.
	 */
	public function test_ga4_parses_report_from_service(): void {
		update_option( 'specflux_mac_ga4_property_id', '123456' );

		$client = new GA4_Client( $this->ga4_service_returning( array( 'date' ), array( 'sessions' ), array( array( array( '20260801' ), array( '42' ) ) ) ) );

		$report = $client->run_report( array( 'sessions' ), array( 'date' ) );

		$this->assertSame(
			array( array( 'date' => '20260801', 'sessions' => '42' ) ),
			$report['rows']
		);
	}

	/**
	 * A GA4 report is cached, so a repeat call does not re-enter the service.
	 */
	public function test_ga4_report_is_cached(): void {
		update_option( 'specflux_mac_ga4_property_id', '123456' );

		$service = $this->ga4_service_returning( array( 'date' ), array( 'sessions' ), array( array( array( '20260801' ), array( '42' ) ) ) );
		$client  = new GA4_Client( $service );

		$client->run_report( array( 'sessions' ), array( 'date' ) );
		$client->run_report( array( 'sessions' ), array( 'date' ) );

		$this->assertSame( 1, $service->properties->calls, 'Second report should be served from cache.' );
	}

	/**
	 * GSC rows are flattened into the search analytics shape.
	 */
	public function test_gsc_parses_search_analytics_from_service(): void {
		update_option( 'specflux_mac_gsc_site_url', 'https://example.com/' );

		$client = new GSC_Client( $this->gsc_service_returning( array( array( 5, 100, 0.05, 7.5, array( '/pricing' ) ) ) ) );

		$data = $client->query_search_analytics( '7daysAgo', array( 'page' ) );

		$this->assertSame( 1, $data['row_count'] );
		$this->assertSame( 5, $data['rows'][0]['clicks'] );
		$this->assertSame( '/pricing', $data['rows'][0]['key'] );
	}

	/**
	 * Build a stand-in Analytics Data service.
	 *
	 * @param array $dimension_names Dimension header names.
	 * @param array $metric_names    Metric header names.
	 * @param array $rows            Rows as [dimension values, metric values] pairs.
	 * @return object Service double exposing a properties resource.
	 */
	private function ga4_service_returning( array $dimension_names, array $metric_names, array $rows ) {
		$header = fn( $name ) => new class( $name ) {
			/**
			 * Header name.
			 *
			 * @var string
			 */
			public $name;

			/**
			 * Constructor.
			 *
			 * @param string $name Header name.
			 */
			public function __construct( $name ) {
				$this->name = $name;
			}

			/**
			 * Get the header name.
			 *
			 * @return string Header name.
			 */
			public function getName() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->name;
			}
		};

		$value = fn( $val ) => new class( $val ) {
			/**
			 * Cell value.
			 *
			 * @var string
			 */
			public $value;

			/**
			 * Constructor.
			 *
			 * @param string $value Cell value.
			 */
			public function __construct( $value ) {
				$this->value = $value;
			}

			/**
			 * Get the cell value.
			 *
			 * @return string Cell value.
			 */
			public function getValue() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->value;
			}
		};

		$row_objects = array();
		foreach ( $rows as $row ) {
			$row_objects[] = new class( array_map( $value, $row[0] ), array_map( $value, $row[1] ) ) {
				/**
				 * Dimension values.
				 *
				 * @var array
				 */
				public $dimensions;

				/**
				 * Metric values.
				 *
				 * @var array
				 */
				public $metrics;

				/**
				 * Constructor.
				 *
				 * @param array $dimensions Dimension values.
				 * @param array $metrics    Metric values.
				 */
				public function __construct( $dimensions, $metrics ) {
					$this->dimensions = $dimensions;
					$this->metrics    = $metrics;
				}

				/**
				 * Get dimension values.
				 *
				 * @return array Dimension values.
				 */
				public function getDimensionValues() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->dimensions;
				}

				/**
				 * Get metric values.
				 *
				 * @return array Metric values.
				 */
				public function getMetricValues() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->metrics;
				}
			};
		}

		$response = new class( array_map( $header, $dimension_names ), array_map( $header, $metric_names ), $row_objects ) {
			/**
			 * Dimension headers.
			 *
			 * @var array
			 */
			public $dimension_headers;

			/**
			 * Metric headers.
			 *
			 * @var array
			 */
			public $metric_headers;

			/**
			 * Report rows.
			 *
			 * @var array
			 */
			public $rows;

			/**
			 * Constructor.
			 *
			 * @param array $dimension_headers Dimension headers.
			 * @param array $metric_headers    Metric headers.
			 * @param array $rows              Report rows.
			 */
			public function __construct( $dimension_headers, $metric_headers, $rows ) {
				$this->dimension_headers = $dimension_headers;
				$this->metric_headers    = $metric_headers;
				$this->rows              = $rows;
			}

			/**
			 * Get dimension headers.
			 *
			 * @return array Dimension headers.
			 */
			public function getDimensionHeaders() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->dimension_headers;
			}

			/**
			 * Get metric headers.
			 *
			 * @return array Metric headers.
			 */
			public function getMetricHeaders() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->metric_headers;
			}

			/**
			 * Get report rows.
			 *
			 * @return array Report rows.
			 */
			public function getRows() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->rows;
			}

			/**
			 * Get report totals.
			 *
			 * @return array Report totals.
			 */
			public function getTotals() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return array();
			}

			/**
			 * Get the total row count.
			 *
			 * @return int Row count.
			 */
			public function getRowCount() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return count( $this->rows );
			}
		};

		return new class( $response ) {
			/**
			 * Properties resource.
			 *
			 * @var object
			 */
			public $properties;

			/**
			 * Constructor.
			 *
			 * @param object $response Canned report response.
			 */
			public function __construct( $response ) {
				$this->properties = new class( $response ) {
					/**
					 * Number of report calls made.
					 *
					 * @var int
					 */
					public $calls = 0;

					/**
					 * Canned response.
					 *
					 * @var object
					 */
					private $response;

					/**
					 * Constructor.
					 *
					 * @param object $response Canned report response.
					 */
					public function __construct( $response ) {
						$this->response = $response;
					}

					/**
					 * Run a report.
					 *
					 * @param string $property Property resource name.
					 * @param object $request  Report request.
					 * @return object Canned response.
					 */
					public function runReport( $property, $request ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
						++$this->calls;
						return $this->response;
					}
				};
			}
		};
	}

	/**
	 * Build a stand-in Search Console service.
	 *
	 * @param array $rows Rows as [clicks, impressions, ctr, position, keys].
	 * @return object Service double exposing a searchanalytics resource.
	 */
	private function gsc_service_returning( array $rows ) {
		$row_objects = array();
		foreach ( $rows as $row ) {
			$row_objects[] = new class( $row ) {
				/**
				 * Row values.
				 *
				 * @var array
				 */
				private $row;

				/**
				 * Constructor.
				 *
				 * @param array $row Row values.
				 */
				public function __construct( $row ) {
					$this->row = $row;
				}

				/**
				 * Get click count.
				 *
				 * @return int Clicks.
				 */
				public function getClicks() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->row[0];
				}

				/**
				 * Get impression count.
				 *
				 * @return int Impressions.
				 */
				public function getImpressions() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->row[1];
				}

				/**
				 * Get click-through rate.
				 *
				 * @return float CTR.
				 */
				public function getCtr() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->row[2];
				}

				/**
				 * Get average position.
				 *
				 * @return float Position.
				 */
				public function getPosition() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->row[3];
				}

				/**
				 * Get dimension keys.
				 *
				 * @return array Keys.
				 */
				public function getKeys() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
					return $this->row[4];
				}
			};
		}

		$response = new class( $row_objects ) {
			/**
			 * Response rows.
			 *
			 * @var array
			 */
			private $rows;

			/**
			 * Constructor.
			 *
			 * @param array $rows Response rows.
			 */
			public function __construct( $rows ) {
				$this->rows = $rows;
			}

			/**
			 * Get response rows.
			 *
			 * @return array Rows.
			 */
			public function getRows() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Google client API.
				return $this->rows;
			}
		};

		return new class( $response ) {
			/**
			 * Search analytics resource.
			 *
			 * @var object
			 */
			public $searchanalytics;

			/**
			 * Constructor.
			 *
			 * @param object $response Canned query response.
			 */
			public function __construct( $response ) {
				$this->searchanalytics = new class( $response ) {
					/**
					 * Canned response.
					 *
					 * @var object
					 */
					private $response;

					/**
					 * Constructor.
					 *
					 * @param object $response Canned query response.
					 */
					public function __construct( $response ) {
						$this->response = $response;
					}

					/**
					 * Run a search analytics query.
					 *
					 * @param string $site_url Site URL.
					 * @param object $request  Query request.
					 * @return object Canned response.
					 */
					public function query( $site_url, $request ) {
						return $this->response;
					}
				};
			}
		};
	}
}
