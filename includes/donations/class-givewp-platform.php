<?php
/**
 * GiveWP donations platform
 *
 * Registers the keyless "givewp" platform with the platform registry, but only
 * when GiveWP is active.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Donations;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the GiveWP donations platform through `specflux_mac_platforms`.
 */
class Givewp_Platform {

	const SLUG = 'givewp';

	/**
	 * Donations reader.
	 *
	 * @var Givewp_Donations_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Givewp_Donations_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Givewp_Donations_Reader();
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
	 * Add the platform definition when GiveWP is active.
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
			'label'           => __( 'GiveWP donations', 'specflux-marketing-analytics-chat' ),
			'auth'            => 'none',
			'description'     => __( 'Shows where your GiveWP donations came from. Only counts and totals per channel, source, campaign and form are read, never donor names, emails, addresses or IP addresses. No credentials needed.', 'specflux-marketing-analytics-chat' ),
			'connection_test' => array( $this, 'test_connection' ),
			'prompt_hint'     => 'This site\'s own GiveWP donations, read from the database (completed live-mode donations only; test, pending, failed and refunded donations are excluded). Use get-donations-by-source for any question about donations, donors, fundraising or "where did my donations come from", by channel, source, campaign, donation form or GiveWP campaign. Amounts are reported per currency and never added across currencies. Say how many donations are "Not tracked" when the note says so, and never invent a source for them. Donor counts are counts only.',
		);

		return $platforms;
	}

	/**
	 * Connection test: succeeds when GiveWP is active.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function test_connection() {
		if ( ! $this->reader->available() ) {
			return array(
				'success' => false,
				'message' => __( 'GiveWP is not active on this site.', 'specflux-marketing-analytics-chat' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'GiveWP is active. Donations are read from your donation records.', 'specflux-marketing-analytics-chat' ),
		);
	}
}
