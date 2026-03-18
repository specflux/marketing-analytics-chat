<?php
/**
 * Main Plugin Class
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP;

use Marketing_Analytics_MCP\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Main plugin class that initializes all components
 */
class Plugin {

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_ajax_hooks();
		$this->define_abilities_hooks();
	}

	/**
	 * Load required dependencies
	 */
	private function load_dependencies() {
		$this->loader = new Loader();
	}

	/**
	 * Define the locale for internationalization
	 */
	private function set_locale() {
		$this->loader->add_action(
			'plugins_loaded',
			$this,
			'load_plugin_textdomain'
		);
	}

	/**
	 * Load plugin text domain
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'marketing-analytics-chat',
			false,
			dirname( MARKETING_ANALYTICS_MCP_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Register admin-related hooks
	 */
	private function define_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		$this->loader->add_action( 'admin_init', Permission_Manager::class, 'register_capabilities' );

		$admin = new Admin\Admin();

		$this->loader->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_action( 'wp_dashboard_setup', $admin, 'register_dashboard_widget' );
		$this->loader->add_action( 'admin_bar_menu', $admin, 'add_admin_bar_item', 100 );
		$this->loader->add_action( 'admin_head', $admin, 'admin_bar_styles' );
	}

	/**
	 * Register AJAX hooks
	 */
	private function define_ajax_hooks() {
		$ajax_handler = new Admin\Ajax_Handler();
		$ajax_handler->register_hooks();
	}

	/**
	 * Register abilities-related hooks
	 */
	private function define_abilities_hooks() {
		$abilities_registrar = new Abilities\Abilities_Registrar();

		// Register category when categories are initialized
		$this->loader->add_action( 'wp_abilities_api_categories_init', $abilities_registrar, 'register_category' );

		// Register abilities when the Abilities API is ready
		$this->loader->add_action( 'wp_abilities_api_init', $abilities_registrar, 'register_all_abilities' );
	}

	/**
	 * Run the plugin
	 */
	public function run() {
		$this->loader->run();

		/**
		 * Fires after the free plugin is fully loaded.
		 *
		 * Pro add-on and third-party plugins can hook here for initialization.
		 *
		 * @param Plugin $this The main plugin instance.
		 */
		do_action( 'marketing_analytics_mcp_loaded', $this );
	}
}
