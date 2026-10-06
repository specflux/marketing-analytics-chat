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
 * A Google API key is required: keyless requests share Google's own project
 * quota, which is routinely exhausted.
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
			'description'     => __( 'Core Web Vitals and Lighthouse performance data for your pages. A free Google API key is required (no billing needed; the default quota is generous for a single site). Get one in the Google Cloud console: enable the PageSpeed Insights API, then Credentials > Create credentials > API key, ideally restricted to the PageSpeed Insights API.', 'specflux-marketing-analytics-chat' ),
			'help_url'        => 'https://developers.google.com/speed/docs/insights/v5/get-started',
			'prompt_hint'     => __( 'Ask about page speed scores, Core Web Vitals field data and the biggest fixes, for this site\'s own URLs.', 'specflux-marketing-analytics-chat' ),
			'cache_ttl'       => DAY_IN_SECONDS,
			'connection_test' => array( $this, 'test_connection' ),
		);

		return $platforms;
	}

	/**
	 * Run a real check against the site's home page (needs a saved key).
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
