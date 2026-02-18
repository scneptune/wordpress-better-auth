<?php
/**
 * Tests for Better_Auth_Deactivator.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class DeactivatorTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/includes/class-better-auth-deactivator.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_wpdb( string $prefix = 'wp_' ) {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = $prefix;
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	public function test_deactivate_flushes_rewrite_rules(): void {
		$wpdb = $this->mock_wpdb();

		// Table doesn't exist — skip the warning path.
		$wpdb->shouldReceive( 'prepare' )->andReturn( "SHOW TABLES LIKE 'wp_user'" );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		Functions\expect( 'wp_next_scheduled' )
			->with( 'better_auth_sync_users' )
			->andReturn( false );

		Functions\expect( 'flush_rewrite_rules' )->once();

		Better_Auth_Deactivator::deactivate();

		$this->expectNotToPerformAssertions();
	}

	public function test_deactivate_sets_transient_when_users_exist(): void {
		$wpdb = $this->mock_wpdb();

		// Table exists.
		$wpdb->shouldReceive( 'prepare' )
			->with( 'SHOW TABLES LIKE %s', 'wp_user' )
			->andReturn( "SHOW TABLES LIKE 'wp_user'" );

		$wpdb->shouldReceive( 'get_var' )
			->with( "SHOW TABLES LIKE 'wp_user'" )
			->andReturn( 'wp_user' );

		// 5 user records exist.
		$wpdb->shouldReceive( 'get_var' )
			->with( "SELECT COUNT(*) FROM `wp_user`" )
			->andReturn( 5 );

		Functions\expect( '__' )->andReturnFirstArg();

		Functions\expect( 'set_transient' )
			->once()
			->with( 'better_auth_deactivation_notice', Mockery::type( 'string' ), 60 );

		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'flush_rewrite_rules' )->once();

		Better_Auth_Deactivator::deactivate();

		$this->expectNotToPerformAssertions();
	}

	public function test_deactivate_skips_transient_when_no_users(): void {
		$wpdb = $this->mock_wpdb();

		// Table exists but is empty.
		$wpdb->shouldReceive( 'prepare' )
			->with( 'SHOW TABLES LIKE %s', 'wp_user' )
			->andReturn( "SHOW TABLES LIKE 'wp_user'" );

		$wpdb->shouldReceive( 'get_var' )
			->with( "SHOW TABLES LIKE 'wp_user'" )
			->andReturn( 'wp_user' );

		$wpdb->shouldReceive( 'get_var' )
			->with( "SELECT COUNT(*) FROM `wp_user`" )
			->andReturn( 0 );

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'flush_rewrite_rules' )->once();

		Better_Auth_Deactivator::deactivate();

		$this->expectNotToPerformAssertions();
	}

	public function test_deactivate_unschedules_cron_when_scheduled(): void {
		$wpdb = $this->mock_wpdb();

		// Skip user-warning path.
		$wpdb->shouldReceive( 'prepare' )->andReturn( "SHOW TABLES LIKE 'wp_user'" );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		Functions\expect( 'wp_next_scheduled' )
			->with( 'better_auth_sync_users' )
			->andReturn( 1700000000 );

		Functions\expect( 'wp_unschedule_event' )
			->once()
			->with( 1700000000, 'better_auth_sync_users' );

		Functions\expect( 'flush_rewrite_rules' )->once();

		Better_Auth_Deactivator::deactivate();

		$this->expectNotToPerformAssertions();
	}
}
