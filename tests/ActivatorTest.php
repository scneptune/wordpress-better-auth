<?php
/**
 * Tests for Better_Auth_Activator.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ActivatorTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/includes/class-better-auth-activator.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: create a mock $wpdb and inject it into the global scope.
	 *
	 * @param string $prefix Table prefix.
	 * @return \Mockery\MockInterface
	 */
	private function mock_wpdb( string $prefix = 'wp_' ) {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = $prefix;
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	// ------------------------------------------------------------------
	//  check_for_existing_tables()
	// ------------------------------------------------------------------

	public function test_check_for_existing_tables_returns_true_when_all_exist(): void {
		$wpdb = $this->mock_wpdb();

		$tables = array( 'verification', 'account', 'session', 'user' );

		foreach ( $tables as $suffix ) {
			$table = 'wp_' . $suffix;

			$wpdb->shouldReceive( 'prepare' )
				->once()
				->with( 'SHOW TABLES LIKE %s', $table )
				->andReturn( "SHOW TABLES LIKE '{$table}'" );

			$wpdb->shouldReceive( 'get_var' )
				->once()
				->with( "SHOW TABLES LIKE '{$table}'" )
				->andReturn( $table );
		}

		$this->assertTrue( Better_Auth_Activator::check_for_existing_tables() );
	}

	public function test_check_for_existing_tables_returns_false_when_one_missing(): void {
		$wpdb = $this->mock_wpdb();

		// First table ('verification') is missing — should short-circuit.
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( 'SHOW TABLES LIKE %s', 'wp_verification' )
			->andReturn( "SHOW TABLES LIKE 'wp_verification'" );

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->with( "SHOW TABLES LIKE 'wp_verification'" )
			->andReturn( null );

		$this->assertFalse( Better_Auth_Activator::check_for_existing_tables() );
	}

	// ------------------------------------------------------------------
	//  migrate_*_table() methods call dbDelta with correct SQL
	// ------------------------------------------------------------------

	public function test_migrate_user_table_calls_dbdelta(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );

		Functions\expect( 'dbDelta' )
			->once()
			->with( Mockery::on( function ( $sql ) {
				return str_contains( $sql, 'CREATE TABLE wp_user' )
					&& str_contains( $sql, 'PRIMARY KEY  (id)' )
					&& str_contains( $sql, 'emailVerified tinyint(1)' )
					&& str_contains( $sql, 'KEY email (email)' );
			} ) );

		Better_Auth_Activator::migrate_user_table();

		$this->expectNotToPerformAssertions();
	}

	public function test_migrate_session_table_calls_dbdelta(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\expect( 'dbDelta' )
			->once()
			->with( Mockery::on( function ( $sql ) {
				return str_contains( $sql, 'CREATE TABLE wp_session' )
					&& str_contains( $sql, 'KEY userId (userId)' );
			} ) );

		Better_Auth_Activator::migrate_session_table();

		$this->expectNotToPerformAssertions();
	}

	public function test_migrate_account_table_calls_dbdelta(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\expect( 'dbDelta' )
			->once()
			->with( Mockery::on( function ( $sql ) {
				return str_contains( $sql, 'CREATE TABLE wp_account' )
					&& str_contains( $sql, 'providerId' )
					&& str_contains( $sql, 'password varchar(255)' );
			} ) );

		Better_Auth_Activator::migrate_account_table();

		$this->expectNotToPerformAssertions();
	}

	public function test_migrate_verification_table_calls_dbdelta(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\expect( 'dbDelta' )
			->once()
			->with( Mockery::on( function ( $sql ) {
				return str_contains( $sql, 'CREATE TABLE wp_verification' )
					&& str_contains( $sql, 'identifier text' );
			} ) );

		Better_Auth_Activator::migrate_verification_table();

		$this->expectNotToPerformAssertions();
	}

	// ------------------------------------------------------------------
	//  activate()
	// ------------------------------------------------------------------

	public function test_activate_calls_all_four_migrations(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		// dbDelta should be called exactly 4 times (once per table).
		Functions\expect( 'dbDelta' )->times( 4 );

		Better_Auth_Activator::activate();

		$this->expectNotToPerformAssertions();
	}

	// ------------------------------------------------------------------
	//  SQL format rules for dbDelta
	// ------------------------------------------------------------------

	public function test_sql_uses_two_space_primary_key(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		$captured_sql = '';
		Functions\expect( 'dbDelta' )
			->once()
			->with( Mockery::on( function ( $sql ) use ( &$captured_sql ) {
				$captured_sql = $sql;
				return true;
			} ) );

		Better_Auth_Activator::migrate_user_table();

		// dbDelta requires "PRIMARY KEY  (col)" with exactly two spaces.
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $captured_sql );
		// Must NOT contain IF NOT EXISTS (dbDelta ignores it).
		$this->assertStringNotContainsString( 'IF NOT EXISTS', $captured_sql );
		// Must NOT contain REFERENCES / FOREIGN KEY (dbDelta drops them).
		$this->assertStringNotContainsString( 'REFERENCES', $captured_sql );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $captured_sql );
	}
}
