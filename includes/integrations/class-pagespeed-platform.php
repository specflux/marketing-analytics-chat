<?php
/**
 * PageSpeed Insights platform registration
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Integrations;

use Specflux_Marketing_Analytics\API_Clients\PageSpeed_Client;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers PageSpeed Insights with the platform registry.
 *
 * The API works without a key, so the single field is optional and the
 * platform counts as connected as soon as it is registered.
 */
class PageSpeed_Platform {

	/**
	 * Hook into the platform registry.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'specflux_mac_platforms', array( $this, 'register_platform' ) );
	}

	/**
	 * Add the PageSpeed Insights definition.
	 *
	 * @param array $platforms Map of slug => definition.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms                  = is_array( $platforms ) ? $platforms : array();
		$platforms['pagespeed']     = array(
			'slug'            => 'pagespeed',
			'label'           => __( 'PageSpeed Insights', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'api_key',
			'fields'          => array( 'api_key' ),
			'optional_fields' => array( 'api_key' ),
			'description'     => __( 'Core Web Vitals and Lighthouse performance data for your pages. PageSpeed Insights works without a key; adding a free Google API key raises the daily quota.', 'specflux-marketing-analytics-chat' ),
			'help_url'        => 'https://developers.google.com/speed/docs/insights/v5/get-started#APIKey',
			'cache_ttl'       => DAY_IN_SECONDS,
			'connection_test' => array( $this, 'test_connection' ),
		);

		return $platforms;
	}

	/**
	 * Run a real check against the site's home page.
	 *
	 * The result is cached by the client, so repeat checks are free.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function test_connection() {
		try {
			$client  = new PageSpeed_Client();
			$summary = $client->get_summary( '', 'mobile' );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: Lighthouse performance score. */
					__( 'PageSpeed Insights is working. Home page mobile score: %d.', 'specflux-marketing-analytics-chat' ),
					(int) $summary['score']
				),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message. */
					__( 'PageSpeed Insights check failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}
	}
}
