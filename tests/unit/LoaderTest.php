<?php
/**
 * Tests for the Loader class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Loader test class.
 */
class LoaderTest extends TestCase {

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	private $loader;

	/**
	 * Track registered hooks.
	 *
	 * @var array
	 */
	private $registered_hooks;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->loader           = new Loader();
		$this->registered_hooks = array();
	}

	/**
	 * Test loader class exists.
	 */
	public function test_loader_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Loader' ) );
	}

	/**
	 * Test loader initializes with empty hook arrays.
	 */
	public function test_loader_initializes_empty(): void {
		$reflection = new \ReflectionClass( $this->loader );

		$actions = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$this->assertEmpty( $actions->getValue( $this->loader ) );

		$filters = $reflection->getProperty( 'filters' );
		$filters->setAccessible( true );
		$this->assertEmpty( $filters->getValue( $this->loader ) );
	}

	/**
	 * Test add_action stores hook correctly.
	 */
	public function test_add_action_stores_hook(): void {
		$component = new \stdClass();

		$this->loader->add_action( 'init', $component, 'test_callback' );

		$reflection = new \ReflectionClass( $this->loader );
		$actions    = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$stored = $actions->getValue( $this->loader );

		$this->assertCount( 1, $stored );
		$this->assertEquals( 'init', $stored[0]['hook'] );
		$this->assertSame( $component, $stored[0]['component'] );
		$this->assertEquals( 'test_callback', $stored[0]['callback'] );
		$this->assertEquals( 10, $stored[0]['priority'] );
		$this->assertEquals( 1, $stored[0]['accepted_args'] );
	}

	/**
	 * Test add_action with custom priority and args.
	 */
	public function test_add_action_with_custom_priority(): void {
		$component = new \stdClass();

		$this->loader->add_action( 'init', $component, 'test_callback', 20, 3 );

		$reflection = new \ReflectionClass( $this->loader );
		$actions    = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$stored = $actions->getValue( $this->loader );

		$this->assertEquals( 20, $stored[0]['priority'] );
		$this->assertEquals( 3, $stored[0]['accepted_args'] );
	}

	/**
	 * Test add_filter stores hook correctly.
	 */
	public function test_add_filter_stores_hook(): void {
		$component = new \stdClass();

		$this->loader->add_filter( 'the_content', $component, 'filter_content' );

		$reflection = new \ReflectionClass( $this->loader );
		$filters    = $reflection->getProperty( 'filters' );
		$filters->setAccessible( true );
		$stored = $filters->getValue( $this->loader );

		$this->assertCount( 1, $stored );
		$this->assertEquals( 'the_content', $stored[0]['hook'] );
		$this->assertEquals( 'filter_content', $stored[0]['callback'] );
	}

	/**
	 * Test multiple hooks can be added.
	 */
	public function test_multiple_hooks(): void {
		$component = new \stdClass();

		$this->loader->add_action( 'init', $component, 'callback_one' );
		$this->loader->add_action( 'admin_init', $component, 'callback_two' );
		$this->loader->add_filter( 'the_title', $component, 'filter_title' );

		$reflection = new \ReflectionClass( $this->loader );

		$actions = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$this->assertCount( 2, $actions->getValue( $this->loader ) );

		$filters = $reflection->getProperty( 'filters' );
		$filters->setAccessible( true );
		$this->assertCount( 1, $filters->getValue( $this->loader ) );
	}

	/**
	 * Test run method calls add_action and add_filter for all registered hooks.
	 */
	public function test_run_registers_all_hooks(): void {
		$component = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'action_callback', 'filter_callback' ) )
			->getMock();

		$this->loader->add_action( 'init', $component, 'action_callback' );
		$this->loader->add_filter( 'the_content', $component, 'filter_callback' );

		// run() calls add_action/add_filter which are mocked in bootstrap.
		// The mock functions are no-ops, so we just verify it doesn't throw.
		$this->loader->run();

		// If we get here without errors, the run method works.
		$this->assertTrue( true );
	}

	/**
	 * Test that the add method preserves hook structure.
	 */
	public function test_hook_structure_integrity(): void {
		$component = new \stdClass();

		$this->loader->add_action( 'test_hook', $component, 'my_callback', 15, 2 );

		$reflection = new \ReflectionClass( $this->loader );
		$actions    = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$hook = $actions->getValue( $this->loader )[0];

		$expected_keys = array( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $hook, "Hook should have '{$key}' key" );
		}
	}

	/**
	 * Test that string class references work for static methods.
	 */
	public function test_static_class_reference(): void {
		$this->loader->add_action( 'admin_init', 'Specflux_Marketing_Analytics\Utils\Permission_Manager', 'register_capabilities' );

		$reflection = new \ReflectionClass( $this->loader );
		$actions    = $reflection->getProperty( 'actions' );
		$actions->setAccessible( true );
		$stored = $actions->getValue( $this->loader );

		$this->assertEquals( 'Specflux_Marketing_Analytics\Utils\Permission_Manager', $stored[0]['component'] );
	}
}
