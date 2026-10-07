<?php
/**
 * Site Analytics Plugin Abilities
 *
 * Traffic, top pages and referrers from a privacy-first analytics plugin
 * installed on this site (Burst Statistics, Independent Analytics, Koko
 * Analytics), for sites that do not use GA4. Registered only when such a
 * plugin with recorded data is active.
 *
 * None of the supported plugins registers WordPress Abilities for historical
 * traffic: Burst Statistics registers a few opt-in live/today abilities
 * (includes/Admin/Abilities_Api/class-abilities-api.php, off by default) but
 * no date-range totals, pages or referrers; Independent Analytics and Koko
 * Analytics register none; Jetpack registers none for stats. These abilities
 * therefore do not duplicate them.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\Content\Local_Analytics_Reader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the site analytics plugin abilities.
 */
class Local_Analytics_Abilities {

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
	 * Register the abilities when a supported analytics plugin with data is active.
	 *
	 * @return void
	 */
	public function register() {
		$label = $this->reader->get_label();
		if ( '' === $label ) {
			return;
		}

		$this->register_one(
			'get-site-traffic',
			__( 'Get Site Traffic (analytics plugin)', 'specflux-marketing-analytics-chat' ),
			'Returns visitors and pageviews (plus sessions where the plugin records them) for a period, the change versus the previous period of equal length, and a day-by-day series, read from the ' . $label . ' plugin installed on this site. Use it for "how is my traffic" questions when Google Analytics 4 is not connected, and say the numbers come from ' . $label . '. Default period: the last 28 days ending yesterday. Koko Analytics only stores daily visitors, so its period visitors is the sum of daily visitors.',
			array(),
			'execute_traffic'
		);
		$this->register_one(
			'get-site-top-pages',
			__( 'Get Site Top Pages (analytics plugin)', 'specflux-marketing-analytics-chat' ),
			'Returns the most viewed pages (path, pageviews, visitors) for a period, read from the ' . $label . ' plugin installed on this site. Use it for "which pages get the most traffic" questions when Google Analytics 4 is not connected, and say the numbers come from ' . $label . '. Default period: the last 28 days ending yesterday.',
			array( 'limit' => $this->limit_property( 'pages' ) ),
			'execute_top_pages'
		);
		$this->register_one(
			'get-site-referrers',
			__( 'Get Site Referrers (analytics plugin)', 'specflux-marketing-analytics-chat' ),
			'Returns the top traffic sources (referrer domain with pageviews, visitors or sessions, depending on what the plugin records) for a period, read from the ' . $label . ' plugin installed on this site. Direct traffic is not listed. Use it for "where does my traffic come from" questions when Google Analytics 4 is not connected, and say the numbers come from ' . $label . '. Default period: the last 28 days ending yesterday.',
			array( 'limit' => $this->limit_property( 'sources' ) ),
			'execute_referrers'
		);
	}

	/**
	 * Input schema for the row limit.
	 *
	 * @param string $noun What is limited.
	 * @return array
	 */
	private function limit_property( $noun ) {
		return array(
			'type'        => 'integer',
			'description' => 'Maximum number of ' . $noun . ' to return (default 10, max 100).',
			'default'     => 10,
			'minimum'     => 1,
			'maximum'     => 100,
		);
	}

	/**
	 * Register one read-only ability.
	 *
	 * @param string $name        Short name.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $extra       Extra input properties.
	 * @param string $callback    Execute method name.
	 * @return void
	 */
	private function register_one( $name, $label, $description, array $extra, $callback ) {
		wp_register_ability(
			'marketing-analytics/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'marketing-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'start_date' => array(
								'type'        => 'string',
								'description' => 'Period start, YYYY-MM-DD (inclusive, site time zone). Default: 27 days before end_date.',
							),
							'end_date'   => array(
								'type'        => 'string',
								'description' => 'Period end, YYYY-MM-DD (inclusive). Default: yesterday.',
							),
						),
						$extra
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'source' => array( 'type' => 'object' ),
						'period' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, $callback ),
				'permission_callback' => array( Abilities_Registrar::class, 'can_access' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute get-site-traffic.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_traffic( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$range = Local_Analytics_Reader::build_range( (array) $args );

				return $this->reader->get_traffic( $range );
			}
		);
	}

	/**
	 * Execute get-site-top-pages.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_top_pages( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args  = (array) $args;
				$range = Local_Analytics_Reader::build_range( $args );

				return $this->reader->get_top_pages( $range, $this->limit( $args ) );
			}
		);
	}

	/**
	 * Execute get-site-referrers.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_referrers( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args  = (array) $args;
				$range = Local_Analytics_Reader::build_range( $args );

				return $this->reader->get_referrers( $range, $this->limit( $args ) );
			}
		);
	}

	/**
	 * Clamp the row limit.
	 *
	 * @param array $args Arguments.
	 * @return int
	 */
	private function limit( array $args ) {
		return max( 1, min( 100, (int) ( $args['limit'] ?? 10 ) ) );
	}
}
