<?php
/**
 * WooCommerce-Aware Abilities
 *
 * Registers MCP abilities for WooCommerce e-commerce analytics via GA4.
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Abilities;

use Marketing_Analytics_MCP\API_Clients\GA4_Client;
use Marketing_Analytics_MCP\Credentials\Credential_Manager;
use Marketing_Analytics_MCP\Utils\Logger;
use Marketing_Analytics_MCP\Utils\Permission_Manager;

/**
 * Registers WooCommerce-related MCP abilities
 */
class WooCommerce_Abilities {

	/**
	 * Credential Manager instance
	 *
	 * @var Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->credential_manager = new Credential_Manager();
	}

	/**
	 * Register WooCommerce abilities
	 */
	public function register() {
		// Only register if GA4 credentials exist.
		if ( ! $this->credential_manager->has_credentials( 'ga4' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_ecommerce_metrics();
		$this->register_product_performance();
		$this->register_checkout_funnel();
	}

	/**
	 * Register get-ecommerce-metrics tool
	 */
	private function register_ecommerce_metrics() {
		wp_register_ability(
			'marketing-analytics/get-ecommerce-metrics',
			array(
				'type'        => 'tool',
				'label'       => __( 'Get E-Commerce Metrics', 'marketing-analytics-chat' ),
				'description' => __( 'Get e-commerce metrics from GA4 including purchases, revenue, and transactions.', 'marketing-analytics-chat' ),
				'category'    => 'marketing-analytics',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'date_range' => array(
							'type'        => 'string',
							'description' => 'Date range (default: "7daysAgo")',
						),
						'dimensions' => array(
							'type'        => 'array',
							'description' => 'Dimensions to group by (e.g., "date", "deviceCategory", "sessionSource")',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'callback'    => array( $this, 'handle_ecommerce_metrics' ),
			)
		);
	}

	/**
	 * Register get-product-performance tool
	 */
	private function register_product_performance() {
		wp_register_ability(
			'marketing-analytics/get-product-performance',
			array(
				'type'        => 'tool',
				'label'       => __( 'Get Product Performance', 'marketing-analytics-chat' ),
				'description' => __( 'Get product-level analytics including revenue, purchases, views, and add-to-cart data.', 'marketing-analytics-chat' ),
				'category'    => 'marketing-analytics',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'date_range' => array(
							'type'        => 'string',
							'description' => 'Date range (default: "7daysAgo")',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to return (default: 20)',
						),
					),
				),
				'callback'    => array( $this, 'handle_product_performance' ),
			)
		);
	}

	/**
	 * Register get-checkout-funnel tool
	 */
	private function register_checkout_funnel() {
		wp_register_ability(
			'marketing-analytics/get-checkout-funnel',
			array(
				'type'        => 'tool',
				'label'       => __( 'Get Checkout Funnel', 'marketing-analytics-chat' ),
				'description' => __( 'Analyze the checkout funnel steps with drop-off rates between view_item, add_to_cart, begin_checkout, add_payment_info, and purchase.', 'marketing-analytics-chat' ),
				'category'    => 'marketing-analytics',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'date_range' => array(
							'type'        => 'string',
							'description' => 'Date range (default: "7daysAgo")',
						),
					),
				),
				'callback'    => array( $this, 'handle_checkout_funnel' ),
			)
		);
	}

	/**
	 * Handle get-ecommerce-metrics tool call
	 *
	 * @param array $args Tool arguments.
	 * @return array Result data.
	 */
	public function handle_ecommerce_metrics( $args ) {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return array( 'error' => __( 'Insufficient permissions.', 'marketing-analytics-chat' ) );
		}

		$date_range = isset( $args['date_range'] ) ? $args['date_range'] : '7daysAgo';
		$dimensions = isset( $args['dimensions'] ) ? $args['dimensions'] : array( 'date' );

		try {
			$client = new GA4_Client();
			return $client->run_report(
				array( 'ecommercePurchases', 'purchaseRevenue', 'averagePurchaseRevenue', 'transactions' ),
				$dimensions,
				$date_range
			);
		} catch ( \Exception $e ) {
			Logger::debug( 'E-commerce metrics error: ' . $e->getMessage() );
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Handle get-product-performance tool call
	 *
	 * @param array $args Tool arguments.
	 * @return array Result data.
	 */
	public function handle_product_performance( $args ) {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return array( 'error' => __( 'Insufficient permissions.', 'marketing-analytics-chat' ) );
		}

		$date_range = isset( $args['date_range'] ) ? $args['date_range'] : '7daysAgo';
		$limit      = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;

		try {
			$client = new GA4_Client();
			return $client->run_report(
				array( 'itemRevenue', 'itemsPurchased', 'itemsViewed', 'itemsAddedToCart' ),
				array( 'itemName', 'itemCategory' ),
				$date_range,
				array( 'limit' => $limit )
			);
		} catch ( \Exception $e ) {
			Logger::debug( 'Product performance error: ' . $e->getMessage() );
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Handle get-checkout-funnel tool call
	 *
	 * @param array $args Tool arguments.
	 * @return array Result data.
	 */
	public function handle_checkout_funnel( $args ) {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return array( 'error' => __( 'Insufficient permissions.', 'marketing-analytics-chat' ) );
		}

		$date_range = isset( $args['date_range'] ) ? $args['date_range'] : '7daysAgo';

		$funnel_steps = array( 'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase' );

		$client = new GA4_Client();
		$funnel = array();

		foreach ( $funnel_steps as $step ) {
			try {
				$data  = $client->get_event_data( $step, $date_range, 1 );
				$count = 0;

				if ( ! empty( $data['rows'] ) ) {
					foreach ( $data['rows'] as $row ) {
						if ( isset( $row['eventCount'] ) ) {
							$count += (int) $row['eventCount'];
						}
					}
				}

				$funnel[] = array(
					'step'  => $step,
					'count' => $count,
				);
			} catch ( \Exception $e ) {
				Logger::debug( 'Checkout funnel error for ' . $step . ': ' . $e->getMessage() );
				$funnel[] = array(
					'step'  => $step,
					'count' => 0,
					'error' => $e->getMessage(),
				);
			}
		}

		// Calculate drop-off rates.
		$funnel = $this->calculate_funnel_dropoff( $funnel );

		return array(
			'date_range' => $date_range,
			'funnel'     => $funnel,
		);
	}

	/**
	 * Calculate drop-off percentages between funnel steps
	 *
	 * @param array $funnel Funnel steps with counts.
	 * @return array Funnel with drop-off data.
	 */
	public function calculate_funnel_dropoff( $funnel ) {
		$count = count( $funnel );

		for ( $step_index = 0; $step_index < $count; $step_index++ ) {
			if ( 0 === $step_index ) {
				$funnel[ $step_index ]['dropoff_rate'] = 0;
				continue;
			}

			$prev_count    = $funnel[ $step_index - 1 ]['count'];
			$current_count = $funnel[ $step_index ]['count'];

			if ( $prev_count > 0 ) {
				$funnel[ $step_index ]['dropoff_rate'] = round( ( 1 - ( $current_count / $prev_count ) ) * 100, 2 );
			} else {
				$funnel[ $step_index ]['dropoff_rate'] = 0;
			}
		}

		return $funnel;
	}
}
