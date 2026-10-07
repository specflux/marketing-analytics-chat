<?php
/**
 * Form entries platform
 *
 * Registers the keyless "forms" platform with the platform registry, but only
 * when a supported form plugin is active.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the Form entries platform through `specflux_mac_platforms`.
 */
class Forms_Platform {

	const SLUG = 'forms';

	/**
	 * Form entries reader.
	 *
	 * @var Form_Entries_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Form_Entries_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Form_Entries_Reader();
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
	 * Add the platform definition when a supported form plugin is active.
	 *
	 * @param array $platforms Map of slug => definition.
	 * @return array
	 */
	public function register_platform( $platforms ) {
		$platforms = is_array( $platforms ) ? $platforms : array();

		if ( ! $this->reader->detect() ) {
			return $platforms;
		}

		$platforms[ self::SLUG ] = array(
			'slug'            => self::SLUG,
			'label'           => __( 'Form entries', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			'description'     => $this->get_description(),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'Counts of this site\'s own form submissions (leads) per form, day and page, read from the database. Personal data (names, emails, messages) is never read. Use get-leads-by-page to see which pages produce leads and their conversion rate against GA4 views, and get-lead-sources for UTM sources when the form plugin stores the page URL.',
		);

		return $platforms;
	}

	/**
	 * Panel description naming the detected plugins.
	 *
	 * @return string
	 */
	public function get_description() {
		/* translators: %s: comma-separated form plugin names, e.g. Fluent Forms. */
		return sprintf( __( 'Counts form submissions from %s on this site. Only counts, form titles and page paths are read, never names, emails or messages. No credentials needed.', 'specflux-marketing-analytics-chat' ), $this->reader->get_labels() );
	}

	/**
	 * Connection test: succeeds when a supported form plugin is active.
	 *
	 * @return array{success:bool,message:string,plugins:string[]}
	 */
	public function test_connection() {
		$plugins = $this->reader->detect();

		if ( ! $plugins ) {
			return array(
				'success' => false,
				'message' => __( 'No supported form plugin is active (WPForms, Gravity Forms, Fluent Forms, Contact Form 7 with Flamingo, or Formidable Forms).', 'specflux-marketing-analytics-chat' ),
				'plugins' => array(),
			);
		}

		return array(
			'success' => true,
			/* translators: %s: comma-separated form plugin names. */
			'message' => sprintf( __( 'Reading form entry counts from: %s.', 'specflux-marketing-analytics-chat' ), $this->reader->get_labels() ),
			'plugins' => $plugins,
		);
	}
}
