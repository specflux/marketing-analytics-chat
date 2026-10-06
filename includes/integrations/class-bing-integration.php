<?php
/**
 * Bing Webmaster Tools platform registration
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Integrations;

use Specflux_Marketing_Analytics\API_Clients\Bing_Webmaster_Client;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers Bing Webmaster Tools with the platform registry so the generic
 * Connections panel, save and disconnect handlers work without extra admin code.
 */
class Bing_Integration {

	/**
	 * Hook into the platform registry
	 */
	public function register_hooks() {
		add_filter( 'specflux_mac_platforms', array( $this, 'register_platform' ) );
	}

	/**
	 * Add the Bing platform definition
	 *
	 * @param array $platforms Registered platforms.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms = is_array( $platforms ) ? $platforms : array();

		$platforms['bing'] = array(
			'slug'            => 'bing',
			'label'           => __( 'Bing Webmaster Tools', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'api_key',
			'fields'          => array( 'api_key', 'site_url' ),
			'cache_ttl'       => 6 * HOUR_IN_SECONDS, // Bing refreshes traffic daily and query/page stats weekly.
			'description'     => __( 'See Bing and Microsoft Copilot search traffic: clicks, impressions, top queries, top pages and crawl health. Enter your Webmaster API key and the site URL exactly as it appears in Bing Webmaster Tools (for example https://example.com/).', 'specflux-marketing-analytics-chat' ),
			'help_url'        => 'https://learn.microsoft.com/en-us/bingwebmaster/getting-started',
			'connection_test' => array( __CLASS__, 'test_connection' ),
		);

		return $platforms;
	}

	/**
	 * Run the connection test with the stored credentials
	 *
	 * @return array Array with 'success' and 'message'.
	 */
	public static function test_connection() {
		try {
			$client = new Bing_Webmaster_Client();
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		return $client->test_connection();
	}
}
