<?php
/**
 * Matomo platform registration
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Integrations;

use Specflux_Marketing_Analytics\API_Clients\Matomo_Client;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers Matomo with the platform registry so the generic Connections panel,
 * save, test and disconnect handlers work without extra admin code.
 */
class Matomo_Integration {

	/**
	 * Hook into the platform registry
	 */
	public function register_hooks() {
		add_filter( 'specflux_mac_platforms', array( $this, 'register_platform' ) );
	}

	/**
	 * Add the Matomo platform definition
	 *
	 * @param array $platforms Registered platforms.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms = is_array( $platforms ) ? $platforms : array();

		$platforms['matomo'] = array(
			'slug'            => 'matomo',
			'label'           => __( 'Matomo', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'api_key',
			'fields'          => array( 'url', 'site_id', 'token_auth' ),
			'field_labels'    => array(
				'url'        => __( 'Matomo URL', 'specflux-marketing-analytics-chat' ),
				'site_id'    => __( 'Site ID', 'specflux-marketing-analytics-chat' ),
				'token_auth' => __( 'Auth token', 'specflux-marketing-analytics-chat' ),
			),
			'cache_ttl'       => HOUR_IN_SECONDS,
			'description'     => __( 'See visits, top pages, traffic sources and goals from your self-hosted Matomo or Matomo Cloud. Enter the address of your Matomo (for example https://analytics.example.com/), the Site ID, and an Auth token created in Matomo under Administration > Personal > Security. Use a token with view access only.', 'specflux-marketing-analytics-chat' ),
			'help_url'        => 'https://matomo.org/faq/general/faq_114/',
			'prompt_hint'     => 'Matomo web analytics (a Google Analytics alternative): visits, unique visitors, actions, bounce rate, top pages, traffic sources and goal conversions.',
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
			$client = new Matomo_Client();
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		return $client->test_connection();
	}
}
