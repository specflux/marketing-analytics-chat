<?php
/**
 * Tests for plugin bootstrap and initialization.
 *
 * Verifies the main plugin file, constants, class loading, and Plugin class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Plugin bootstrap test class.
 */
class PluginBootstrapTest extends TestCase {

	/**
	 * Test plugin constants are defined.
	 */
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'SPECFLUX_MAC_VERSION' ) );
		$this->assertTrue( defined( 'SPECFLUX_MAC_PATH' ) );
		$this->assertTrue( defined( 'SPECFLUX_MAC_URL' ) );
		$this->assertTrue( defined( 'SPECFLUX_MAC_BASENAME' ) );
	}

	/**
	 * Test VERSION constant is a valid semver string.
	 */
	public function test_version_is_valid(): void {
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+/',
			SPECFLUX_MAC_VERSION,
			'Version should be a valid semver string'
		);
	}

	/**
	 * Test PATH constant ends with trailing slash.
	 */
	public function test_path_has_trailing_slash(): void {
		$this->assertStringEndsWith( '/', SPECFLUX_MAC_PATH );
	}

	/**
	 * Test PATH constant points to existing directory.
	 */
	public function test_path_is_valid_directory(): void {
		$this->assertDirectoryExists( SPECFLUX_MAC_PATH );
	}

	/**
	 * Test URL constant ends with trailing slash.
	 */
	public function test_url_has_trailing_slash(): void {
		$this->assertStringEndsWith( '/', SPECFLUX_MAC_URL );
	}

	/**
	 * Test BASENAME matches expected format.
	 */
	public function test_basename_format(): void {
		$this->assertMatchesRegularExpression(
			'/^[a-z0-9-]+\/[a-z0-9-]+\.php$/',
			SPECFLUX_MAC_BASENAME,
			'BASENAME should be in format: slug/slug.php'
		);
	}

	/**
	 * Test all required core classes exist.
	 */
	public function test_core_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Plugin',
			'Specflux_Marketing_Analytics\Loader',
			'Specflux_Marketing_Analytics\Activator',
			'Specflux_Marketing_Analytics\Deactivator',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Core class {$class} should exist"
			);
		}
	}

	/**
	 * Test all ability classes exist.
	 */
	public function test_ability_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Abilities\Abilities_Registrar',
			'Specflux_Marketing_Analytics\Abilities\Clarity_Abilities',
			'Specflux_Marketing_Analytics\Abilities\GA4_Abilities',
			'Specflux_Marketing_Analytics\Abilities\GSC_Abilities',
			'Specflux_Marketing_Analytics\Abilities\Cross_Platform_Abilities',
			'Specflux_Marketing_Analytics\Abilities\Prompts',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Ability class {$class} should exist"
			);
		}
	}

	/**
	 * Test all admin classes exist.
	 */
	public function test_admin_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Admin\Admin',
			'Specflux_Marketing_Analytics\Admin\Ajax_Handler',
			'Specflux_Marketing_Analytics\Admin\Connection_Promoter',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Admin class {$class} should exist"
			);
		}
	}

	/**
	 * Test all API client classes exist.
	 */
	public function test_api_client_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\API_Clients\Clarity_Client',
			'Specflux_Marketing_Analytics\API_Clients\GA4_Client',
			'Specflux_Marketing_Analytics\API_Clients\GSC_Client',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"API client class {$class} should exist"
			);
		}
	}

	/**
	 * Test all credential classes exist.
	 */
	public function test_credential_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Credentials\Credential_Manager',
			'Specflux_Marketing_Analytics\Credentials\Encryption',
			'Specflux_Marketing_Analytics\Credentials\Connection_Tester',
			'Specflux_Marketing_Analytics\Credentials\OAuth_Handler',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Credential class {$class} should exist"
			);
		}
	}

	/**
	 * Test all chat classes exist.
	 */
	public function test_chat_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Chat\Chat_Manager',
			'Specflux_Marketing_Analytics\Chat\MCP_Client',
			'Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler',
			'Specflux_Marketing_Analytics\Chat\Claude_Provider',
			'Specflux_Marketing_Analytics\Chat\OpenAI_Provider',
			'Specflux_Marketing_Analytics\Chat\Gemini_Provider',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Chat class {$class} should exist"
			);
		}
	}

	/**
	 * Test all utility classes exist.
	 */
	public function test_utility_classes_exist(): void {
		$classes = array(
			'Specflux_Marketing_Analytics\Utils\Logger',
			'Specflux_Marketing_Analytics\Utils\Permission_Manager',
			'Specflux_Marketing_Analytics\Cache\Cache_Manager',
			'Specflux_Marketing_Analytics\Prompts\Prompt_Manager',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Utility class {$class} should exist"
			);
		}
	}

	/**
	 * Test LLM provider interface exists.
	 */
	public function test_llm_provider_interface_exists(): void {
		$this->assertTrue(
			interface_exists( 'Specflux_Marketing_Analytics\Chat\LLM_Provider_Interface' ),
			'LLM_Provider_Interface should exist'
		);
	}

	/**
	 * Test LLM providers implement the interface.
	 */
	public function test_llm_providers_implement_interface(): void {
		$providers = array(
			'Specflux_Marketing_Analytics\Chat\Claude_Provider',
			'Specflux_Marketing_Analytics\Chat\OpenAI_Provider',
			'Specflux_Marketing_Analytics\Chat\Gemini_Provider',
		);

		foreach ( $providers as $provider ) {
			$reflection = new \ReflectionClass( $provider );
			$this->assertTrue(
				$reflection->implementsInterface( 'Specflux_Marketing_Analytics\Chat\LLM_Provider_Interface' ),
				"{$provider} should implement LLM_Provider_Interface"
			);
		}
	}

	/**
	 * Test Loader class has required methods.
	 */
	public function test_loader_has_required_methods(): void {
		$methods = array( 'add_action', 'add_filter', 'run' );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Loader::class, $method ),
				"Loader should have '{$method}' method"
			);
		}
	}

	/**
	 * Test Plugin class has required methods.
	 */
	public function test_plugin_has_required_methods(): void {
		$this->assertTrue(
			method_exists( 'Specflux_Marketing_Analytics\Plugin', 'run' ),
			'Plugin should have run() method'
		);
	}

	/**
	 * Test all PHP files have direct access protection.
	 */
	public function test_files_have_direct_access_protection(): void {
		$plugin_dir = SPECFLUX_MAC_PATH . 'includes/';
		$errors     = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content  = file_get_contents( $file->getPathname() );
			$relative = str_replace( SPECFLUX_MAC_PATH, '', $file->getPathname() );

			// Files should check for ABSPATH or have a namespace (which implies autoloading).
			$has_protection = strpos( $content, "defined( 'ABSPATH' )" ) !== false
				|| strpos( $content, "defined('ABSPATH')" ) !== false
				|| strpos( $content, 'namespace ' ) !== false;

			if ( ! $has_protection ) {
				$errors[] = $relative;
			}
		}

		$this->assertEmpty(
			$errors,
			"PHP files missing direct access protection:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test main plugin file exists.
	 */
	public function test_main_plugin_file_exists(): void {
		$main_file = SPECFLUX_MAC_PATH . 'specflux-marketing-analytics-chat.php';
		$this->assertFileExists( $main_file );
	}

	/**
	 * Test readme.txt exists.
	 */
	public function test_readme_exists(): void {
		$readme = SPECFLUX_MAC_PATH . 'readme.txt';
		$this->assertFileExists( $readme );
	}

	/**
	 * Test required directories exist.
	 */
	public function test_required_directories_exist(): void {
		$dirs = array(
			'includes/',
			'includes/abilities/',
			'includes/admin/',
			'includes/api-clients/',
			'includes/cache/',
			'includes/chat/',
			'includes/credentials/',
			'includes/prompts/',
			'includes/utils/',
			'admin/',
			'admin/css/',
			'admin/js/',
			'admin/views/',
		);

		foreach ( $dirs as $dir ) {
			$this->assertDirectoryExists(
				SPECFLUX_MAC_PATH . $dir,
				"Directory '{$dir}' should exist"
			);
		}
	}

	/**
	 * Test activation function exists in main plugin file.
	 */
	public function test_activation_function_exists(): void {
		$this->assertTrue(
			function_exists( 'Specflux_Marketing_Analytics\activate_specflux_mac' ),
			'Activation function should exist in the plugin namespace'
		);
	}

	/**
	 * Test deactivation function exists in main plugin file.
	 */
	public function test_deactivation_function_exists(): void {
		$this->assertTrue(
			function_exists( 'Specflux_Marketing_Analytics\deactivate_specflux_mac' ),
			'Deactivation function should exist in the plugin namespace'
		);
	}

	/**
	 * Test run function exists in main plugin file.
	 */
	public function test_run_function_exists(): void {
		$this->assertTrue(
			function_exists( 'Specflux_Marketing_Analytics\run_specflux_mac' ),
			'Run function should exist in the plugin namespace'
		);
	}
}
