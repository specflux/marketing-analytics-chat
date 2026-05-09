<?php
/**
 * Plugin Name: Specflux Marketing Analytics Chat
 * Plugin URI: https://github.com/specflux/marketing-analytics-chat
 * Description: Chat with your marketing analytics data using AI. Connects Google Analytics 4, Search Console, Microsoft Clarity, and more.
 * Version: 0.1.6
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Stephen Paul Samynathan
 * Author URI: https://www.specflux.com/author/stephen/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: specflux-marketing-analytics-chat
 * Domain Path: /languages
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Plugin version.
define( 'SPECFLUX_MAC_VERSION', '0.1.6' );
define( 'SPECFLUX_MAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPECFLUX_MAC_URL', plugin_dir_url( __FILE__ ) );
define( 'SPECFLUX_MAC_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader.
if ( file_exists( SPECFLUX_MAC_PATH . 'vendor/autoload.php' ) ) {
	require_once SPECFLUX_MAC_PATH . 'vendor/autoload.php';
} else {
	// Display admin notice if dependencies are missing.
	add_action(
		'admin_notices',
		function () {
			?>
		<div class="notice notice-error">
			<p>
				<strong>Specflux Marketing Analytics Chat:</strong>
				<?php esc_html_e( 'Dependencies are missing. Please run "composer install" in the plugin directory.', 'specflux-marketing-analytics-chat' ); ?>
			</p>
		</div>
			<?php
		}
	);
	return;
}

/**
 * Check for recommended MCP Adapter plugin
 *
 * MCP Adapter is optional but recommended for external AI client access.
 * The built-in chat and Abilities API work without it.
 */
function check_plugin_dependencies() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Show recommendation if MCP Adapter is not active.
	if ( ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				// Only show on plugin pages, not everywhere.
				$screen = get_current_screen();
				if ( ! $screen || 'plugins' !== $screen->id ) {
					return;
				}
				?>
			<div class="notice notice-info is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Specflux Marketing Analytics Chat:', 'specflux-marketing-analytics-chat' ); ?></strong>
					<?php esc_html_e( 'For external AI client access (Claude Desktop, Cursor, etc.), install the MCP Adapter plugin.', 'specflux-marketing-analytics-chat' ); ?>
				</p>
				<p>
					<a href="https://github.com/WordPress/mcp-adapter" target="_blank"><?php esc_html_e( 'Get MCP Adapter from GitHub', 'specflux-marketing-analytics-chat' ); ?></a>
				</p>
			</div>
				<?php
			}
		);
	}
	return true;
}

/**
 * Activation hook
 */
function activate_specflux_mac() {
	require_once SPECFLUX_MAC_PATH . 'includes/class-activator.php';
	Activator::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_specflux_mac' );

/**
 * Deactivation hook
 */
function deactivate_specflux_mac() {
	require_once SPECFLUX_MAC_PATH . 'includes/class-deactivator.php';
	Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_specflux_mac' );

/**
 * Initialize plugin
 */
function run_specflux_mac() {
	// Check for required plugin dependencies.
	if ( ! check_plugin_dependencies() ) {
		return;
	}

	// Check if the Plugin class exists.
	if ( ! class_exists( __NAMESPACE__ . '\Plugin' ) ) {
		return;
	}

	$plugin = new Plugin();
	$plugin->run();
}

// Run the plugin after plugins are loaded to ensure dependencies are available.
add_action( 'plugins_loaded', __NAMESPACE__ . '\run_specflux_mac' );
