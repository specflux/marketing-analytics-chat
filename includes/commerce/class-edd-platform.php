<?php
/**
 * Easy Digital Downloads sales platform
 *
 * Registers the keyless "easy-digital-downloads" platform with the platform
 * registry, but only when Easy Digital Downloads 3.x is active.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Commerce;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the Easy Digital Downloads sales platform through `specflux_mac_platforms`.
 */
class Edd_Platform {

	const SLUG = 'easy-digital-downloads';

	/**
	 * Sales reader.
	 *
	 * @var Edd_Sales_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Edd_Sales_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Edd_Sales_Reader();
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
	 * Add the platform definition when Easy Digital Downloads 3.x is active.
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
			'label'           => __( 'Easy Digital Downloads sales', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			'description'     => __( 'Shows where your Easy Digital Downloads sales came from, using the attribution recorded on each order. Only totals per channel, source, campaign and landing page, and your top downloads, are read, never customer names, emails, IP addresses or order numbers. Test-mode orders are skipped. No credentials needed.', 'specflux-marketing-analytics-chat' ),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'This store\'s own Easy Digital Downloads orders, read from the database (live-mode paid orders only, refunds subtracted, each currency reported separately and never added together). Use get-sales-by-source for any question about sales, revenue, orders, top downloads or "where did my sales come from", by channel, source, source / medium, campaign or landing page. Always say that attribution is last-click as recorded by this plugin, not Google Analytics attribution, and mention how many orders are "Not tracked" when the note says so. Say which store answered (the "store" field).',
		);

		return $platforms;
	}

	/**
	 * Connection test: succeeds when Easy Digital Downloads 3.x is active.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function test_connection() {
		if ( ! $this->reader->available() ) {
			return array(
				'success' => false,
				'message' => __( 'Easy Digital Downloads 3.0 or newer is not active on this site.', 'specflux-marketing-analytics-chat' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Easy Digital Downloads is active. Sales are read from your store orders.', 'specflux-marketing-analytics-chat' ),
		);
	}
}
