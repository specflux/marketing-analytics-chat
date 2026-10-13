<?php
/**
 * Site analytics plugin platform
 *
 * Registers the keyless "localstats" platform with the platform registry, but
 * only when a supported privacy-first analytics plugin with recorded data is
 * active on this site.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the site analytics plugin platform through `specflux_mac_platforms`.
 */
class Local_Analytics_Platform {

	const SLUG = 'localstats';

	/**
	 * Reader.
	 *
	 * @var Local_Analytics_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Local_Analytics_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Local_Analytics_Reader();
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
	 * Add the platform definition when a supported plugin has data.
	 *
	 * @param array $platforms Map of slug => definition.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms = is_array( $platforms ) ? $platforms : array();
		$label     = $this->reader->get_label();

		if ( '' === $label ) {
			return $platforms;
		}

		$platforms[ self::SLUG ] = array(
			'slug'            => self::SLUG,
			'label'           => __( 'Site analytics plugin', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			/* translators: %s: analytics plugin name, e.g. Koko Analytics. */
			'description'     => sprintf( __( 'Reads visitors, pageviews, top pages and referrers recorded by %s (Jetpack Stats is fetched from WordPress.com). No credentials needed and no personal data is read.', 'specflux-marketing-analytics-chat' ), $label ),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'First-party traffic recorded on this site by the ' . $label . ' plugin (visitors, pageviews, sessions where available, top pages, referrers). Use get-site-traffic, get-site-top-pages and get-site-referrers for traffic questions when Google Analytics 4 is not connected, and always say the numbers come from ' . $label . ', not GA4.',
		);

		return $platforms;
	}

	/**
	 * Connection test: succeeds when a supported plugin with data is active.
	 *
	 * @return array{success:bool,message:string,plugin:string}
	 */
	public function test_connection() {
		$label = $this->reader->get_label();

		if ( '' === $label ) {
			$message = __( 'No supported site analytics plugin with recorded data was found.', 'specflux-marketing-analytics-chat' );
			foreach ( $this->reader->get_unreadable() as $plugin_label => $reason ) {
				$message .= ' ' . $plugin_label . ': ' . $reason;
			}

			return array(
				'success' => false,
				'message' => $message,
				'plugin'  => '',
			);
		}

		$provider = $this->reader->get_provider();
		if ( '' !== $provider ) {
			return array(
				'success' => true,
				/* translators: 1: analytics plugin name, 2: plugin that answered, e.g. Jetpack plugin. */
				'message' => sprintf( __( 'Reading traffic from %1$s (answered by the %2$s).', 'specflux-marketing-analytics-chat' ), $label, $provider ),
				'plugin'  => $this->reader->detect(),
			);
		}

		return array(
			'success' => true,
			/* translators: %s: analytics plugin name. */
			'message' => sprintf( __( 'Reading traffic from %s.', 'specflux-marketing-analytics-chat' ), $label ),
			'plugin'  => $this->reader->detect(),
		);
	}
}
