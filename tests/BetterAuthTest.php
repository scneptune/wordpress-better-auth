<?php
/**
 * Tests for Better_Auth core class — user-sync and REST endpoint logic.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class BetterAuthTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub WP functions that the constructor calls (dependencies).
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__ ) . '/' );
		Functions\when( 'plugin_dir_url' )->justReturn( 'http://example.com/wp-content/plugins/better-auth/' );

		require_once dirname( __DIR__ ) . '/includes/class-better-auth-loader.php';
		require_once dirname( __DIR__ ) . '/includes/class-better-auth-i18n.php';
		require_once dirname( __DIR__ ) . '/admin/class-better-auth-admin.php';
		require_once dirname( __DIR__ ) . '/public/class-better-auth-public.php';
		require_once dirname( __DIR__ ) . '/includes/class-better-auth.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	//  maybe_create_wp_user_for_ba_user()
	// ------------------------------------------------------------------

	public function test_links_existing_wp_user_by_email(): void {
		$ba_user = (object) array(
			'id'    => 'ba-123',
			'name'  => 'Jane Doe',
			'email' => 'jane@example.com',
		);

		$wp_user     = new \stdClass();
		$wp_user->ID = 42;

		Functions\expect( 'sanitize_email' )
			->once()
			->with( 'jane@example.com' )
			->andReturn( 'jane@example.com' );

		Functions\expect( 'get_user_by' )
			->once()
			->with( 'email', 'jane@example.com' )
			->andReturn( $wp_user );

		// The link doesn't exist yet, so update_user_meta should be called.
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 42, 'better_auth_user_id', true )
			->andReturn( '' );

		Functions\expect( 'sanitize_text_field' )
			->once()
			->with( 'ba-123' )
			->andReturn( 'ba-123' );

		Functions\expect( 'update_user_meta' )
			->once()
			->with( 42, 'better_auth_user_id', 'ba-123' );

		$result = Better_Auth::maybe_create_wp_user_for_ba_user( $ba_user );

		$this->assertSame( 42, $result );
	}

	public function test_skips_meta_update_when_link_exists(): void {
		$ba_user = (object) array(
			'id'    => 'ba-123',
			'name'  => 'Jane Doe',
			'email' => 'jane@example.com',
		);

		$wp_user     = new \stdClass();
		$wp_user->ID = 42;

		Functions\expect( 'sanitize_email' )->andReturn( 'jane@example.com' );
		Functions\expect( 'get_user_by' )->andReturn( $wp_user );

		// Link already exists.
		Functions\expect( 'get_user_meta' )
			->with( 42, 'better_auth_user_id', true )
			->andReturn( 'ba-123' );

		// update_user_meta should NOT be called.
		Functions\expect( 'update_user_meta' )->never();

		$result = Better_Auth::maybe_create_wp_user_for_ba_user( $ba_user );

		$this->assertSame( 42, $result );
	}

	public function test_creates_new_wp_user_when_none_exists(): void {
		$ba_user = (object) array(
			'id'    => 'ba-456',
			'name'  => 'John Smith',
			'email' => 'john@example.com',
		);

		Functions\expect( 'sanitize_email' )->andReturn( 'john@example.com' );
		Functions\expect( 'get_user_by' )->andReturn( false );
		Functions\expect( 'sanitize_user' )->andReturn( 'John Smith' );
		Functions\expect( 'username_exists' )->andReturn( false );
		Functions\expect( 'wp_generate_password' )->andReturn( 'random-password-string!' );
		Functions\expect( 'sanitize_text_field' )->andReturn( 'John Smith' );

		Functions\expect( 'wp_insert_user' )
			->once()
			->with( Mockery::on( function ( $args ) {
				return $args['user_email'] === 'john@example.com'
					&& $args['role'] === 'subscriber'
					&& ! empty( $args['user_pass'] );
			} ) )
			->andReturn( 99 );

		Functions\expect( 'update_user_meta' )
			->once()
			->with( 99, 'better_auth_user_id', Mockery::type( 'string' ) );

		$result = Better_Auth::maybe_create_wp_user_for_ba_user( $ba_user );

		$this->assertSame( 99, $result );
	}

	public function test_appends_suffix_when_username_taken(): void {
		$ba_user = (object) array(
			'id'    => 'ba-789',
			'name'  => 'Admin',
			'email' => 'admin2@example.com',
		);

		Functions\expect( 'sanitize_email' )->andReturn( 'admin2@example.com' );
		Functions\expect( 'get_user_by' )->andReturn( false );
		Functions\expect( 'sanitize_user' )->andReturn( 'Admin' );
		Functions\expect( 'username_exists' )->andReturn( true ); // username taken!
		Functions\expect( 'wp_generate_password' )->andReturn( 'abcdef' );
		Functions\expect( 'sanitize_text_field' )->andReturn( 'Admin' );

		// wp_insert_user should get a user_login that is NOT just "Admin".
		Functions\expect( 'wp_insert_user' )
			->once()
			->with( Mockery::on( function ( $args ) {
				// The login should have a suffix appended.
				return str_starts_with( $args['user_login'], 'Admin_' )
					&& strlen( $args['user_login'] ) > 6;
			} ) )
			->andReturn( 100 );

		Functions\expect( 'update_user_meta' )->once();

		$result = Better_Auth::maybe_create_wp_user_for_ba_user( $ba_user );

		$this->assertSame( 100, $result );
	}

	public function test_returns_wp_error_on_insert_failure(): void {
		$ba_user = (object) array(
			'id'    => 'ba-err',
			'name'  => 'Error User',
			'email' => 'error@example.com',
		);

		Functions\expect( 'sanitize_email' )->andReturn( 'error@example.com' );
		Functions\expect( 'get_user_by' )->andReturn( false );
		Functions\expect( 'sanitize_user' )->andReturn( 'Error User' );
		Functions\expect( 'username_exists' )->andReturn( false );
		Functions\expect( 'wp_generate_password' )->andReturn( 'pwd' );
		Functions\expect( 'sanitize_text_field' )->andReturn( 'Error User' );

		$wp_error = new WP_Error( 'insert_failed', 'Could not create user.' );

		Functions\expect( 'wp_insert_user' )->andReturn( $wp_error );

		$result = Better_Auth::maybe_create_wp_user_for_ba_user( $ba_user );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insert_failed', $result->get_error_code() );
	}

	private function make_better_auth_instance(): Better_Auth {
		Functions\when( 'register_rest_route' )->justReturn( true );
		return new Better_Auth();
	}

	// ------------------------------------------------------------------
	//  send_password_setup_for_otp_user()
	// ------------------------------------------------------------------

	public function test_otp_returns_error_when_user_not_found(): void {
		Functions\when( '__' )->returnArg();
		Functions\expect( 'get_userdata' )->with( 999 )->andReturn( false );

		$result = Better_Auth::send_password_setup_for_otp_user( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	public function test_otp_returns_error_when_not_ba_user(): void {
		Functions\when( '__' )->returnArg();

		$user              = new \stdClass();
		$user->ID          = 10;
		$user->user_login  = 'testuser';

		Functions\expect( 'get_userdata' )->with( 10 )->andReturn( $user );
		Functions\expect( 'get_user_meta' )
			->with( 10, 'better_auth_user_id', true )
			->andReturn( '' );

		$result = Better_Auth::send_password_setup_for_otp_user( 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_better_auth_user', $result->get_error_code() );
	}

	public function test_otp_calls_retrieve_password_for_ba_user(): void {
		$user              = new \stdClass();
		$user->ID          = 10;
		$user->user_login  = 'testuser';

		Functions\expect( 'get_userdata' )->with( 10 )->andReturn( $user );
		Functions\expect( 'get_user_meta' )
			->with( 10, 'better_auth_user_id', true )
			->andReturn( 'ba-linked' );

		Functions\expect( 'retrieve_password' )
			->once()
			->with( 'testuser' )
			->andReturn( true );

		$result = Better_Auth::send_password_setup_for_otp_user( 10 );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	//  Constructor / getter sanity
	// ------------------------------------------------------------------

	public function test_plugin_name_and_version(): void {
		$ba = $this->make_better_auth_instance();

		$this->assertSame( 'better-auth', $ba->get_plugin_name() );
		$this->assertSame( BETTER_AUTH_VERSION, $ba->get_version() );
	}

	public function test_get_loader_returns_loader_instance(): void {
		$ba = $this->make_better_auth_instance();

		$this->assertInstanceOf( Better_Auth_Loader::class, $ba->get_loader() );
	}
}
