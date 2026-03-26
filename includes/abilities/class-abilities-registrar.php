<?php
/**
 * Abilities Registrar
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Abilities;

use Marketing_Analytics_MCP\Utils\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Registers all MCP abilities with WordPress Abilities API
 */
class Abilities_Registrar {

	/**
	 * Registered tools
	 *
	 * @var array
	 */
	private static $registered_tools = array();

	/**
	 * Registered resources
	 *
	 * @var array
	 */
	private static $registered_resources = array();

	/**
	 * Registered prompts
	 *
	 * @var array
	 */
	private static $registered_prompts = array();

	/**
	 * Get all registered tools
	 *
	 * @return array Registered tools keyed by ability name.
	 */
	public static function get_registered_tools(): array {
		return self::$registered_tools;
	}

	/**
	 * Get all registered resources
	 *
	 * @return array Registered resources keyed by ability name.
	 */
	public static function get_registered_resources(): array {
		return self::$registered_resources;
	}

	/**
	 * Get all registered prompts
	 *
	 * @return array Registered prompts keyed by ability name.
	 */
	public static function get_registered_prompts(): array {
		return self::$registered_prompts;
	}

	/**
	 * Track a registered ability by type
	 *
	 * @param string $name   Ability name.
	 * @param array  $config Ability configuration.
	 */
	public static function track_ability( string $name, array $config ): void {
		$type = isset( $config['type'] ) ? $config['type'] : 'tool';

		switch ( $type ) {
			case 'resource':
				self::$registered_resources[ $name ] = $config;
				break;
			case 'prompt':
				self::$registered_prompts[ $name ] = $config;
				break;
			default:
				self::$registered_tools[ $name ] = $config;
				break;
		}
	}

	/**
	 * Reset all tracked abilities (useful for testing)
	 */
	public static function reset(): void {
		self::$registered_tools     = array();
		self::$registered_resources = array();
		self::$registered_prompts   = array();
	}

	/**
	 * Register ability category
	 *
	 * Called on the 'wp_abilities_api_categories_init' hook
	 */
	public function register_category() {
		// Register the marketing-analytics category.
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'marketing-analytics',
				array(
					'label'       => __( 'Marketing Analytics', 'marketing-analytics-chat' ),
					'description' => __( 'Tools for accessing marketing analytics data from Microsoft Clarity, Google Analytics 4, and Google Search Console.', 'marketing-analytics-chat' ),
				)
			);
		}
	}

	/**
	 * Register all abilities
	 *
	 * Called on the 'wp_abilities_api_init' hook
	 */
	public function register_all_abilities() {
		// Check if Abilities API is available.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			// Log warning.
			Logger::debug( 'Abilities API not available. Please install wordpress/abilities-api.' );
			return;
		}

		// Register Clarity abilities.
		$clarity_abilities = new Clarity_Abilities();
		$clarity_abilities->register();

		// Register GA4 abilities.
		$ga4_abilities = new GA4_Abilities();
		$ga4_abilities->register();

		// Register GSC abilities.
		$gsc_abilities = new GSC_Abilities();
		$gsc_abilities->register();

		// Register cross-platform abilities.
		$cross_platform_abilities = new Cross_Platform_Abilities();
		$cross_platform_abilities->register();

		// Register prompts.
		$prompts = new Prompts();
		$prompts->register();

		/**
		 * Allow pro add-on and third-party plugins to register additional abilities.
		 *
		 * @param Abilities_Registrar $this The abilities registrar instance.
		 */
		do_action( 'marketing_analytics_mcp_register_pro_abilities', $this );
	}
}
