<?php
/**
 * Tests for Better_Auth_Loader.
 *
 * The loader is pure PHP (no WP dependencies beyond add_action / add_filter),
 * so Brain\Monkey stubs are sufficient.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class LoaderTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/includes/class-better-auth-loader.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_add_action_stores_hook(): void {
		$loader    = new Better_Auth_Loader();
		$component = new \stdClass();

		$loader->add_action( 'init', $component, 'do_something' );

		// Use reflection to inspect the protected $actions array.
		$ref = new \ReflectionProperty( Better_Auth_Loader::class, 'actions' );
		$ref->setAccessible( true );
		$actions = $ref->getValue( $loader );

		$this->assertCount( 1, $actions );
		$this->assertSame( 'init', $actions[0]['hook'] );
		$this->assertSame( $component, $actions[0]['component'] );
		$this->assertSame( 'do_something', $actions[0]['callback'] );
		$this->assertSame( 10, $actions[0]['priority'] );
		$this->assertSame( 1, $actions[0]['accepted_args'] );
	}

	public function test_add_filter_stores_hook(): void {
		$loader    = new Better_Auth_Loader();
		$component = new \stdClass();

		$loader->add_filter( 'the_content', $component, 'filter_content', 20, 2 );

		$ref = new \ReflectionProperty( Better_Auth_Loader::class, 'filters' );
		$ref->setAccessible( true );
		$filters = $ref->getValue( $loader );

		$this->assertCount( 1, $filters );
		$this->assertSame( 'the_content', $filters[0]['hook'] );
		$this->assertSame( 20, $filters[0]['priority'] );
		$this->assertSame( 2, $filters[0]['accepted_args'] );
	}

	public function test_run_registers_actions_with_wordpress(): void {
		$loader    = new Better_Auth_Loader();
		$component = new \stdClass();

		$loader->add_action( 'init', $component, 'handle_init' );
		$loader->add_action( 'wp_loaded', $component, 'on_loaded', 5 );

		// Expect add_action to be called for each registered hook.
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', array( $component, 'handle_init' ), 10, 1 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_loaded', array( $component, 'on_loaded' ), 5, 1 );

		$loader->run();

		$this->expectNotToPerformAssertions();
	}

	public function test_run_registers_filters_with_wordpress(): void {
		$loader    = new Better_Auth_Loader();
		$component = new \stdClass();

		$loader->add_filter( 'the_title', $component, 'filter_title' );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'the_title', array( $component, 'filter_title' ), 10, 1 );

		$loader->run();

		$this->expectNotToPerformAssertions();
	}

	public function test_multiple_hooks_all_registered(): void {
		$loader = new Better_Auth_Loader();
		$comp   = new \stdClass();

		$loader->add_action( 'init', $comp, 'a' );
		$loader->add_action( 'init', $comp, 'b' );
		$loader->add_filter( 'body_class', $comp, 'c' );

		$ref_actions = new \ReflectionProperty( Better_Auth_Loader::class, 'actions' );
		$ref_actions->setAccessible( true );
		$ref_filters = new \ReflectionProperty( Better_Auth_Loader::class, 'filters' );
		$ref_filters->setAccessible( true );

		$this->assertCount( 2, $ref_actions->getValue( $loader ) );
		$this->assertCount( 1, $ref_filters->getValue( $loader ) );
	}
}
