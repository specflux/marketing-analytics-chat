<?php
/**
 * Integration tests for MCP Abilities registration.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\integration;

use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Abilities integration test class.
 */
class AbilitiesIntegrationTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();

		// Reset tracked abilities before each test.
		Abilities_Registrar::reset();

		// Set up mock credentials so ability classes register their tools.
		$cred_manager = new Credential_Manager();
		$cred_manager->save_credentials( 'clarity', array(
			'api_token'  => 'test-token',
			'project_id' => 'test-project',
		) );
		$cred_manager->save_credentials( 'ga4', array(
			'access_token'  => 'test-token',
			'refresh_token' => 'test-refresh',
			'property_id'   => '123456',
		) );
		$cred_manager->save_credentials( 'gsc', array(
			'access_token'  => 'test-token',
			'refresh_token' => 'test-refresh',
			'site_url'      => 'https://example.com',
		) );
	}

	/**
	 * Test abilities are registered correctly.
	 *
	 * @group integration
	 */
	public function test_abilities_registration(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertNotEmpty( $tools, 'At least one tool should be registered.' );
	}

	/**
	 * Test tool abilities count.
	 *
	 * @group integration
	 */
	public function test_tool_abilities_count(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertIsArray( $tools );

		// Free plugin registers Clarity (3) + GA4 (5) + GSC (4) + Cross-platform tools.
		$this->assertGreaterThanOrEqual( 12, count( $tools ), 'Should register at least 12 tools.' );
	}

	/**
	 * Test resource abilities count.
	 *
	 * @group integration
	 */
	public function test_resource_abilities_count(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$resources = Abilities_Registrar::get_registered_resources();
		$this->assertIsArray( $resources );
	}

	/**
	 * Test prompt abilities count.
	 *
	 * @group integration
	 */
	public function test_prompt_abilities_count(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$prompts = Abilities_Registrar::get_registered_prompts();
		$this->assertIsArray( $prompts );
	}

	/**
	 * Test ability naming conventions.
	 *
	 * @group integration
	 */
	public function test_ability_naming_conventions(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertNotEmpty( $tools );

		foreach ( $tools as $tool_name => $tool_config ) {
			// Tool names should follow pattern: marketing-analytics/action-name
			$this->assertMatchesRegularExpression(
				'/^marketing-analytics\/[a-z0-9-]+$/',
				$tool_name,
				"Tool name '$tool_name' does not follow naming convention"
			);
		}
	}

	/**
	 * Test abilities have required properties.
	 *
	 * @group integration
	 */
	public function test_abilities_have_required_properties(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertNotEmpty( $tools );

		foreach ( $tools as $tool_name => $tool_config ) {
			// Each tool should have a description.
			$this->assertArrayHasKey(
				'description',
				$tool_config,
				"Tool '$tool_name' missing description"
			);
		}
	}

	/**
	 * Test abilities have a category.
	 *
	 * @group integration
	 */
	public function test_abilities_have_category(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$tools = Abilities_Registrar::get_registered_tools();

		foreach ( $tools as $tool_name => $tool_config ) {
			if ( isset( $tool_config['category'] ) ) {
				$this->assertSame(
					'marketing-analytics',
					$tool_config['category'],
					"Tool '$tool_name' should use the marketing-analytics category"
				);
			}
		}
	}

	/**
	 * Test reset clears all tracked abilities.
	 *
	 * @group integration
	 */
	public function test_reset_clears_abilities(): void {
		$registrar = new Abilities_Registrar();
		$registrar->register_all_abilities();

		$this->assertNotEmpty( Abilities_Registrar::get_registered_tools() );

		Abilities_Registrar::reset();

		$this->assertEmpty( Abilities_Registrar::get_registered_tools() );
		$this->assertEmpty( Abilities_Registrar::get_registered_resources() );
		$this->assertEmpty( Abilities_Registrar::get_registered_prompts() );
	}

	/**
	 * Test WordPress hooks are properly registered.
	 *
	 * @group integration
	 */
	public function test_wordpress_hooks_registered(): void {
		// Verify the registrar can register category without error.
		$registrar = new Abilities_Registrar();
		$registrar->register_category();
		$this->assertTrue( true );
	}
}
