<?php
/**
 * WooCommerce sales platform
 *
 * Registers the keyless "woocommerce" platform with the platform registry, but
 * only when WooCommerce is active.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Commerce;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce sales platform through `specflux_mac_platforms`.
 */
class Woo_Platform {

	const SLUG = 'woocommerce';

	/**
	 * Sales reader.
	 *
	 * @var Woo_Sales_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Woo_Sales_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Woo_Sales_Reader();
	}

	/**
	 * Hook into the registry.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'specflux_mac_platforms', array( $this, 'register_platform' ) );
	}

	/**
	 * Add the platform definition when WooCommerce is active.
	 *
	 * @param array $platforms Map of slug => definition.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms = is_array( $platforms ) ? $platforms : array();

		if ( ! $this->reader->available() ) {
			return $platforms;
		}

		$platforms[ self::SLUG ] = array(
			'slug'            => self::SLUG,
			'label'           => __( 'WooCommerce sales', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			'description'     => __( 'Shows where your WooCommerce sales came from, using the order attribution WooCommerce records at checkout. Only totals per channel, source, campaign, device and landing page are read, never customer names, emails, addresses or order numbers. No credentials needed.', 'specflux-marketing-analytics-chat' ),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'This store\'s own WooCommerce orders, read from the database (paid orders only, refunds subtracted, store currency). Use get-sales-by-source for any question about sales, revenue, orders or "where did my sales come from", by channel, source, source / medium, campaign, device or landing page, and get-store-funnel for conversion questions (sessions to orders, or product view to purchase when GA4 receives shop events). Always say that attribution is last-click as recorded by WooCommerce\'s own order attribution, not Google Analytics attribution, and mention how many orders are "Not tracked" when the note says so. Never invent figures for funnel stages the tool omitted.',
		);

		return $platforms;
	}

	/**
	 * Connection test: succeeds when WooCommerce is active.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function test_connection() {
		if ( ! $this->reader->available() ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce is not active on this site.', 'specflux-marketing-analytics-chat' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'WooCommerce is active. Sales are read from your store orders.', 'specflux-marketing-analytics-chat' ),
		);
	}
}
