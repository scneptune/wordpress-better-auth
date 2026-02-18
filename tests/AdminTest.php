<?php
/**
 * Tests for Better_Auth_Admin.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class AdminTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/admin/class-better-auth-admin.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	//  register_settings()
	// ------------------------------------------------------------------

	public function test_register_settings_registers_both_options(): void {
		$admin = new Better_Auth_Admin( 'better-auth', '1.0.0' );

		Functions\expect( 'register_setting' )
			->once()
			->with( 'general', 'better_auth_delete_users_on_uninstall', Mockery::type( 'array' ) );

		Functions\expect( 'register_setting' )
			->once()
			->with( 'general', 'better_auth_api_secret', Mockery::type( 'array' ) );

		Functions\expect( '__' )->andReturnFirstArg();

		Functions\expect( 'add_settings_section' )
			->once()
			->with(
				'better_auth_settings',
				'Better Auth',
				Mockery::type( 'array' ),
				'general'
			);

		Functions\expect( 'add_settings_field' )->twice();

		$admin->register_settings();

		$this->expectNotToPerformAssertions();
	}

	// ------------------------------------------------------------------
	//  show_admin_notices()
	// ------------------------------------------------------------------

	public function test_show_admin_notices_displays_and_deletes_transient(): void {
		$admin = new Better_Auth_Admin( 'better-auth', '1.0.0' );

		Functions\expect( 'get_transient' )
			->once()
			->with( 'better_auth_deactivation_notice' )
			->andReturn( 'Plugin was deactivated. 3 records remain.' );

		Functions\expect( 'esc_html' )
			->once()
			->with( 'Plugin was deactivated. 3 records remain.' )
			->andReturn( 'Plugin was deactivated. 3 records remain.' );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'better_auth_deactivation_notice' );

		// Capture output.
		ob_start();
		$admin->show_admin_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Plugin was deactivated', $output );
	}

	public function test_show_admin_notices_does_nothing_without_transient(): void {
		$admin = new Better_Auth_Admin( 'better-auth', '1.0.0' );

		Functions\expect( 'get_transient' )
			->once()
			->with( 'better_auth_deactivation_notice' )
			->andReturn( false );

		Functions\expect( 'delete_transient' )->never();

		ob_start();
		$admin->show_admin_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// ------------------------------------------------------------------
	//  render_api_secret_field()
	// ------------------------------------------------------------------

	public function test_render_api_secret_field_outputs_password_input(): void {
		$admin = new Better_Auth_Admin( 'better-auth', '1.0.0' );

		Functions\expect( 'get_option' )
			->with( 'better_auth_api_secret', '' )
			->andReturn( 'test-secret' );

		Functions\expect( 'esc_attr' )->andReturn( 'test-secret' );
		Functions\expect( 'esc_html_e' )->andReturn( '' );

		ob_start();
		$admin->render_api_secret_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringContainsString( 'name="better_auth_api_secret"', $output );
		$this->assertStringContainsString( 'test-secret', $output );
	}

	// ------------------------------------------------------------------
	//  render_delete_users_field()
	// ------------------------------------------------------------------

	public function test_render_delete_users_field_outputs_checkbox(): void {
		$admin = new Better_Auth_Admin( 'better-auth', '1.0.0' );

		Functions\expect( 'get_option' )
			->with( 'better_auth_delete_users_on_uninstall', false )
			->andReturn( false );

		Functions\expect( 'checked' )->once();
		Functions\expect( 'esc_html_e' )->andReturn( '' );

		ob_start();
		$admin->render_delete_users_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'name="better_auth_delete_users_on_uninstall"', $output );
	}
}
