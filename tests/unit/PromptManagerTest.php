<?php
/**
 * Tests for the Prompt Manager class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Prompts\Prompt_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Prompt Manager test class.
 */
class PromptManagerTest extends TestCase {

	/**
	 * Prompt Manager instance.
	 *
	 * @var Prompt_Manager
	 */
	private $manager;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options  = array();
		$this->manager = new Prompt_Manager();
	}

	/**
	 * Test class exists.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Prompts\Prompt_Manager' ) );
	}

	/**
	 * Test OPTION_NAME constant uses expected prefix.
	 */
	public function test_option_name_constant(): void {
		$this->assertEquals(
			'specflux_mac_custom_prompts',
			Prompt_Manager::OPTION_NAME,
			'OPTION_NAME should use the specflux_mac_ prefix'
		);
	}

	/**
	 * Test get_all_prompts returns empty array when no prompts exist.
	 */
	public function test_get_all_prompts_returns_empty_array(): void {
		$prompts = $this->manager->get_all_prompts();
		$this->assertIsArray( $prompts );
		$this->assertEmpty( $prompts );
	}

	/**
	 * Test create_prompt with valid data.
	 */
	public function test_create_prompt_with_valid_data(): void {
		$data = array(
			'name'         => 'test-prompt',
			'description'  => 'A test prompt',
			'instructions' => 'Do something useful',
		);

		$prompt_id = $this->manager->create_prompt( $data );

		$this->assertIsString( $prompt_id );
		$this->assertStringContainsString( 'marketing-analytics/', $prompt_id );
	}

	/**
	 * Test create_prompt stores data correctly.
	 */
	public function test_create_prompt_stores_data(): void {
		$data = array(
			'name'         => 'my-analysis',
			'description'  => 'Analyze data',
			'instructions' => 'Step 1: Get metrics',
		);

		$prompt_id = $this->manager->create_prompt( $data );
		$prompt    = $this->manager->get_prompt( $prompt_id );

		$this->assertNotNull( $prompt );
		$this->assertEquals( 'my-analysis', $prompt['name'] );
		$this->assertEquals( 'Analyze data', $prompt['description'] );
		$this->assertEquals( 'Step 1: Get metrics', $prompt['instructions'] );
		$this->assertArrayHasKey( 'created_at', $prompt );
		$this->assertArrayHasKey( 'updated_at', $prompt );
		$this->assertEquals( $prompt_id, $prompt['id'] );
	}

	/**
	 * Test create_prompt fails without required name field.
	 */
	public function test_create_prompt_fails_without_name(): void {
		$data = array(
			'description'  => 'A test prompt',
			'instructions' => 'Do something',
		);

		$result = $this->manager->create_prompt( $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test create_prompt fails without description.
	 */
	public function test_create_prompt_fails_without_description(): void {
		$data = array(
			'name'         => 'test-prompt',
			'instructions' => 'Do something',
		);

		$result = $this->manager->create_prompt( $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test create_prompt fails without instructions.
	 */
	public function test_create_prompt_fails_without_instructions(): void {
		$data = array(
			'name'        => 'test-prompt',
			'description' => 'A test prompt',
		);

		$result = $this->manager->create_prompt( $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test create_prompt validates name format (lowercase, numbers, hyphens only).
	 */
	public function test_create_prompt_validates_name_format(): void {
		$data = array(
			'name'         => 'Invalid Name With Spaces',
			'description'  => 'A test prompt',
			'instructions' => 'Do something',
		);

		$result = $this->manager->create_prompt( $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test valid name formats are accepted.
	 */
	public function test_valid_name_formats(): void {
		$valid_names = array( 'test', 'my-prompt', 'analysis-v2', 'seo-check-123' );

		foreach ( $valid_names as $name ) {
			global $mock_options;
			$mock_options = array();

			$data = array(
				'name'         => $name,
				'description'  => 'Test',
				'instructions' => 'Test instructions',
			);

			$result = $this->manager->create_prompt( $data );
			$this->assertIsString( $result, "Name '{$name}' should be accepted" );
		}
	}

	/**
	 * Test create_prompt validates arguments field.
	 */
	public function test_create_prompt_validates_arguments_field(): void {
		$data = array(
			'name'         => 'test-prompt',
			'description'  => 'A test prompt',
			'instructions' => 'Do something',
			'arguments'    => 'not-an-array',
		);

		$result = $this->manager->create_prompt( $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_prompt returns null for non-existent prompt.
	 */
	public function test_get_prompt_returns_null_for_missing(): void {
		$result = $this->manager->get_prompt( 'marketing-analytics/nonexistent' );
		$this->assertNull( $result );
	}

	/**
	 * Test update_prompt updates data correctly.
	 */
	public function test_update_prompt(): void {
		$data = array(
			'name'         => 'update-me',
			'description'  => 'Original description',
			'instructions' => 'Original instructions',
		);

		$prompt_id = $this->manager->create_prompt( $data );

		$update_data = array(
			'name'         => 'update-me',
			'description'  => 'Updated description',
			'instructions' => 'Updated instructions',
		);

		$result = $this->manager->update_prompt( $prompt_id, $update_data );

		$this->assertTrue( $result );

		$prompt = $this->manager->get_prompt( $prompt_id );
		$this->assertEquals( 'Updated description', $prompt['description'] );
		$this->assertEquals( 'Updated instructions', $prompt['instructions'] );
	}

	/**
	 * Test update_prompt fails for non-existent prompt.
	 */
	public function test_update_nonexistent_prompt(): void {
		$data = array(
			'name'         => 'test',
			'description'  => 'Test',
			'instructions' => 'Test',
		);

		$result = $this->manager->update_prompt( 'marketing-analytics/nonexistent', $data );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test delete_prompt removes prompt.
	 */
	public function test_delete_prompt(): void {
		$data = array(
			'name'         => 'delete-me',
			'description'  => 'To be deleted',
			'instructions' => 'Delete instructions',
		);

		$prompt_id = $this->manager->create_prompt( $data );
		$this->assertNotNull( $this->manager->get_prompt( $prompt_id ) );

		$result = $this->manager->delete_prompt( $prompt_id );

		$this->assertTrue( $result );
		$this->assertNull( $this->manager->get_prompt( $prompt_id ) );
	}

	/**
	 * Test delete_prompt returns false for non-existent prompt.
	 */
	public function test_delete_nonexistent_prompt(): void {
		$result = $this->manager->delete_prompt( 'marketing-analytics/nonexistent' );
		$this->assertFalse( $result );
	}

	/**
	 * Test get_preset_templates returns array of templates.
	 */
	public function test_get_preset_templates(): void {
		$templates = $this->manager->get_preset_templates();

		$this->assertIsArray( $templates );
		$this->assertNotEmpty( $templates );

		// Should have known preset keys.
		$expected_keys = array(
			'traffic-drop-analysis',
			'weekly-report',
			'seo-health-check',
			'content-performance-audit',
			'anomaly-investigation',
			'conversion-funnel-analysis',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $templates, "Preset '{$key}' should exist" );
		}
	}

	/**
	 * Test preset templates have required fields.
	 */
	public function test_preset_templates_have_required_fields(): void {
		$templates       = $this->manager->get_preset_templates();
		$required_fields = array( 'name', 'description', 'instructions', 'category' );

		foreach ( $templates as $key => $template ) {
			foreach ( $required_fields as $field ) {
				$this->assertArrayHasKey(
					$field,
					$template,
					"Preset '{$key}' should have '{$field}' field"
				);
			}
		}
	}

	/**
	 * Test preset template names follow naming convention.
	 */
	public function test_preset_template_names_follow_convention(): void {
		$templates = $this->manager->get_preset_templates();

		foreach ( $templates as $key => $template ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]+$/',
				$template['name'],
				"Preset '{$key}' name '{$template['name']}' should be lowercase with hyphens"
			);
		}
	}

	/**
	 * Test preset template category is marketing-analytics.
	 */
	public function test_preset_template_categories(): void {
		$templates = $this->manager->get_preset_templates();

		foreach ( $templates as $key => $template ) {
			$this->assertEquals(
				'marketing-analytics',
				$template['category'],
				"Preset '{$key}' category should be 'marketing-analytics'"
			);
		}
	}

	/**
	 * Test import_preset creates prompt from template.
	 */
	public function test_import_preset(): void {
		$result = $this->manager->import_preset( 'weekly-report' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'marketing-analytics/', $result );

		$prompt = $this->manager->get_prompt( $result );
		$this->assertNotNull( $prompt );
		$this->assertEquals( 'weekly-report', $prompt['name'] );
	}

	/**
	 * Test import_preset fails for invalid key.
	 */
	public function test_import_preset_invalid_key(): void {
		$result = $this->manager->import_preset( 'nonexistent-preset' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test duplicate prompt IDs get unique suffix.
	 */
	public function test_duplicate_prompt_ids_get_suffix(): void {
		$data = array(
			'name'         => 'duplicate-test',
			'description'  => 'First prompt',
			'instructions' => 'Instructions',
		);

		$id1 = $this->manager->create_prompt( $data );

		$data['description'] = 'Second prompt';
		$id2                 = $this->manager->create_prompt( $data );

		$this->assertNotEquals( $id1, $id2, 'Duplicate prompt IDs should be made unique' );
	}

	/**
	 * Test multiple prompts can be stored and retrieved.
	 */
	public function test_multiple_prompts(): void {
		$this->manager->create_prompt( array(
			'name'         => 'prompt-one',
			'description'  => 'First',
			'instructions' => 'Instructions 1',
		) );

		$this->manager->create_prompt( array(
			'name'         => 'prompt-two',
			'description'  => 'Second',
			'instructions' => 'Instructions 2',
		) );

		$all = $this->manager->get_all_prompts();

		$this->assertCount( 2, $all );
	}
}
