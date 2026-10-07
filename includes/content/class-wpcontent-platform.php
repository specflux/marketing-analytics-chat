<?php
/**
 * WordPress Content & SEO platform
 *
 * Registers the keyless "wpcontent" platform with the platform registry.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the WordPress Content & SEO platform through `specflux_mac_platforms`.
 */
class Content_Platform {

	const SLUG = 'wpcontent';

	/**
	 * SEO plugin reader.
	 *
	 * @var Seo_Plugin_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Seo_Plugin_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Seo_Plugin_Reader();
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
	 * Add the platform definition.
	 *
	 * @param array $platforms Map of slug => definition.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms                = is_array( $platforms ) ? $platforms : array();
		$platforms[ self::SLUG ] = array(
			'slug'            => self::SLUG,
			'label'           => __( 'WordPress Content & SEO', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			'description'     => $this->get_description(),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'This site\'s published posts and pages joined with their GA4 and Search Console numbers, plus SEO titles, meta descriptions, focus keywords and noindex flags from the active SEO plugin. Use find-seo-issues for "what should I fix" questions.',
		);

		return $platforms;
	}

	/**
	 * Panel description naming the detected SEO plugin.
	 *
	 * @return string
	 */
	public function get_description() {
		$label = $this->reader->get_label();

		if ( '' === $label ) {
			return __( 'Reads this site\'s published posts and pages. No supported SEO plugin was detected (Yoast SEO, Rank Math, All in One SEO and SEOPress are supported), so SEO titles and descriptions are unavailable.', 'specflux-marketing-analytics-chat' );
		}

		/* translators: %s: SEO plugin name, e.g. Yoast SEO. */
		return sprintf( __( 'Reads this site\'s published posts and pages, plus SEO titles, descriptions, focus keywords and indexing settings from %s. No credentials needed.', 'specflux-marketing-analytics-chat' ), $label );
	}

	/**
	 * Connection test: always succeeds, reporting the detected SEO plugin.
	 *
	 * @return array{success:bool,message:string,seo_plugin:string}
	 */
	public function test_connection() {
		$plugin = $this->reader->detect();
		$label  = $this->reader->get_label( $plugin );

		if ( '' === $label ) {
			return array(
				'success'    => true,
				'message'    => __( 'Reading site content. No supported SEO plugin detected.', 'specflux-marketing-analytics-chat' ),
				'seo_plugin' => '',
			);
		}

		return array(
			'success'    => true,
			/* translators: %s: SEO plugin name. */
			'message'    => sprintf( __( 'Reading site content. SEO plugin detected: %s.', 'specflux-marketing-analytics-chat' ), $label ),
			'seo_plugin' => $plugin,
		);
	}
}
