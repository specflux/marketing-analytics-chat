<?php
/**
 * Connection Promoter
 *
 * After connecting one platform, suggests connecting others to unlock
 * cross-platform features.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Promotes connecting additional analytics platforms.
 */
class Connection_Promoter {

	/**
	 * Extra copy for the platforms that ship with the plugin.
	 *
	 * The platform list itself comes from Platform_Registry, so add-on platforms
	 * are counted too; this only supplies the friendlier benefit line and icon.
	 *
	 * @var array
	 */
	private const EXTRAS = array(
		'clarity' => array(
			'icon'    => 'chart-area',
			'benefit' => 'session recordings and heatmap insights',
		),
		'ga4'     => array(
			'icon'    => 'chart-line',
			'benefit' => 'traffic trends and user behavior metrics',
		),
		'gsc'     => array(
			'icon'    => 'search',
			'benefit' => 'search performance and keyword rankings',
		),
	);

	/**
	 * Recommendation order: the easiest setup first, then the rest of the registry.
	 *
	 * @var string[]
	 */
	private const PRIORITY = array( 'clarity', 'ga4', 'gsc' );

	/**
	 * Every registered platform with name, icon, benefit and Connections tab.
	 *
	 * @return array Map of slug => data.
	 */
	private function platforms() {
		$platforms = array();

		foreach ( Platform_Registry::all() as $slug => $definition ) {
			$extra       = self::EXTRAS[ $slug ] ?? array();
			$description = (string) $definition['description'];

			$platforms[ $slug ] = array(
				'name'    => (string) $definition['label'],
				'icon'    => $extra['icon'] ?? 'admin-plugins',
				'benefit' => $extra['benefit'] ?? ( '' !== $description ? lcfirst( $description ) : 'more of your marketing data' ),
				'tab'     => ! empty( $definition['tab'] ) ? $slug : '',
			);
		}

		return $platforms;
	}

	/**
	 * Get the appropriate connection prompt based on current connections.
	 *
	 * @return array {
	 *     @type int    $connected_count Number of connected platforms.
	 *     @type string $message         Prompt message.
	 *     @type array  $next            Next recommended platform data, or empty.
	 * }
	 */
	public function get_connection_prompt() {
		$connected = $this->get_connected_platforms();
		$count     = count( $connected );
		$total     = $this->get_platform_total();
		$next      = $this->get_next_recommendation( $connected );

		if ( 0 === $count ) {
			return array(
				'connected_count' => 0,
				'message'         => __( 'Connect your first platform to get started with marketing analytics.', 'specflux-marketing-analytics-chat' ),
				'next'            => $next,
				'cta'             => empty( $next ) ? '' : sprintf(
					/* translators: %s: platform name */
					__( 'Connect %s', 'specflux-marketing-analytics-chat' ),
					$next['name']
				),
			);
		}

		if ( $count < $total && ! empty( $next ) ) {
			if ( 1 === $count ) {
				return array(
					'connected_count' => 1,
					'message'         => sprintf(
						/* translators: 1: next platform name, 2: benefit description */
						__( 'Connect %1$s to unlock cross-platform insights with %2$s.', 'specflux-marketing-analytics-chat' ),
						$next['name'],
						$next['benefit']
					),
					'next'            => $next,
					'cta'             => sprintf(
						/* translators: %s: platform name */
						__( 'Connect %s', 'specflux-marketing-analytics-chat' ),
						$next['name']
					),
				);
			}

			return array(
				'connected_count' => $count,
				'message'         => sprintf(
					/* translators: %s: last platform name */
					__( 'Add %s for the complete picture across all your marketing data.', 'specflux-marketing-analytics-chat' ),
					$next['name']
				),
				'next'            => $next,
				'cta'             => sprintf(
					/* translators: %s: platform name */
					__( 'Connect %s', 'specflux-marketing-analytics-chat' ),
					$next['name']
				),
			);
		}

		// All connected.
		return array(
			'connected_count' => $total,
			'message'         => __( 'All platforms connected! You have full cross-platform analytics.', 'specflux-marketing-analytics-chat' ),
			'next'            => array(),
			'cta'             => '',
		);
	}

	/**
	 * Check which platforms are currently connected.
	 *
	 * @return array Array of connected platform keys.
	 */
	public function get_connected_platforms() {
		$credential_manager = new Credential_Manager();
		$connected          = array();

		foreach ( array_keys( $this->platforms() ) as $platform ) {
			if ( $credential_manager->has_credentials( $platform ) ) {
				$connected[] = $platform;
			}
		}

		return $connected;
	}

	/**
	 * Total number of platforms that can be connected.
	 *
	 * @return int
	 */
	public function get_platform_total() {
		return count( $this->platforms() );
	}

	/**
	 * Suggest the best next platform to connect.
	 *
	 * Clarity (easiest setup) first, then Analytics, Search Console, then any other
	 * registered platform in registry order.
	 *
	 * @param array $connected Already-connected platform keys.
	 * @return array Platform data or empty array if all connected.
	 */
	public function get_next_recommendation( $connected = null ) {
		if ( null === $connected ) {
			$connected = $this->get_connected_platforms();
		}

		$platforms = $this->platforms();
		$order     = array_values( array_unique( array_merge( array_intersect( self::PRIORITY, array_keys( $platforms ) ), array_keys( $platforms ) ) ) );

		foreach ( $order as $platform ) {
			if ( ! in_array( $platform, $connected, true ) ) {
				$data        = $platforms[ $platform ];
				$data['key'] = $platform;
				return $data;
			}
		}

		return array();
	}

	/**
	 * Fire the all-connected hook once every registered platform is connected.
	 *
	 * @return bool Whether every platform is connected.
	 */
	public function maybe_fire_all_connected() {
		$total = $this->get_platform_total();

		if ( $total < 1 || count( $this->get_connected_platforms() ) < $total ) {
			return false;
		}

		/**
		 * Fires when all free platforms are connected.
		 *
		 * Premium add-on can hook here to suggest Meta + DataForSEO.
		 */
		do_action( 'specflux_mac_all_platforms_connected' );

		return true;
	}
}
