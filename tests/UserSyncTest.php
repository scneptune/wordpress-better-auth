<?php
/**
 * Tests for Better_Auth_User_Sync.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class Better_Auth_Test_WooCommerce_Stub {}

class Better_Auth_Test_WC_Customer_Stub {
	public static $saved = array();

	public function __construct( $user_id ) {
		self::$saved['user_id'] = $user_id;
	}

	public function set_email( $email ) {
		self::$saved['email'] = $email;
	}

	public function set_first_name( $first_name ) {
		self::$saved['first_name'] = $first_name;
	}

	public function set_last_name( $last_name ) {
		self::$saved['last_name'] = $last_name;
	}

	public function save() {
		self::$saved['saved'] = true;
	}
}

class UserSyncTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/includes/class-better-auth-request-signer.php';
		require_once dirname( __DIR__ ) . '/includes/class-better-auth-user-sync.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_create_user_endpoint(): void {
		$sync = new Better_Auth_User_Sync();

		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				'better-auth/v1',
				'/create-user',
				Mockery::on(
					function ( $args ) {
						return isset( $args['callback'], $args['permission_callback'] )
							&& is_array( $args['callback'] )
							&& is_array( $args['permission_callback'] )
							&& isset( $args['permission_callback'][1] )
							&& 'verify_hmac_sync_signature' === $args['permission_callback'][1];
					}
				)
			);

		$sync->register_routes();
		$this->addToAssertionCount( 1 );
	}

	public function test_verify_hmac_rejects_missing_headers(): void {
		$sync = new Better_Auth_User_Sync();

		Functions\expect( '__' )->andReturnFirstArg();

		$request = new WP_REST_Request();

		$result = $sync->verify_hmac_sync_signature( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_bad_signature_headers', $result->get_error_code() );
	}

	public function test_create_user_without_woocommerce_uses_subscriber_role(): void {
		$sync = new Better_Auth_User_Sync();

		$request = new WP_REST_Request();
		$request->set_param( 'email', 'new-user@example.com' );
		$request->set_param( 'name', 'Jane Tester' );
		$request->set_param( 'ba_user_id', 'ba-100' );
		$request->set_param( 'phone', '555-1234' );
		$request->set_param( 'otp_method', 'sms' );

		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();

		Functions\expect( 'get_user_by' )
			->once()
			->with( 'email', 'new-user@example.com' )
			->andReturn( false );

		Functions\expect( 'sanitize_user' )
			->once()
			->with( 'new-user', true )
			->andReturn( 'new-user' );

		Functions\expect( 'username_exists' )
			->once()
			->with( 'new-user' )
			->andReturn( false );

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 32, true, true )
			->andReturn( 'generated-password' );

		Functions\expect( 'wp_insert_user' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 'subscriber' === $args['role']
							&& 'new-user@example.com' === $args['user_email'];
					}
				)
			)
			->andReturn( 101 );

		Functions\expect( 'update_user_meta' )
			->once()
			->with( 101, 'phone_number', '555-1234' );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 101, 'better_auth_otp_method', 'sms' );

		global $wpdb;
		$wpdb = new class() {
			public $prefix = 'wp_';
			public $last_update;

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->last_update = array(
					'table'        => $table,
					'data'         => $data,
					'where'        => $where,
					'format'       => $format,
					'where_format' => $where_format,
				);
				return 1;
			}
		};

		$response = $sync->create_wp_user_from_better_auth_user( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 101, $response->get_data()['wp_user_id'] );
		$this->assertFalse( $response->get_data()['woocommerce_customer_created'] );
		$this->assertSame( 'wp_ba_user', $wpdb->last_update['table'] );
		$this->assertSame( array( 'id' => 'ba-100' ), $wpdb->last_update['where'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_user_with_woocommerce_creates_customer(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			class_alias( Better_Auth_Test_WooCommerce_Stub::class, 'WooCommerce' );
		}

		if ( ! class_exists( 'WC_Customer', false ) ) {
			class_alias( Better_Auth_Test_WC_Customer_Stub::class, 'WC_Customer' );
		}

		$sync = new Better_Auth_User_Sync();

		$request = new WP_REST_Request();
		$request->set_param( 'email', 'wc-user@example.com' );
		$request->set_param( 'name', 'John Woo Tester' );
		$request->set_param( 'ba_user_id', 'ba-200' );

		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\expect( 'get_user_by' )->andReturn( false );
		Functions\expect( 'username_exists' )->andReturn( false );
		Functions\expect( 'wp_generate_password' )->once()->with( 32, true, true )->andReturn( 'pw' );

		Functions\expect( 'wp_insert_user' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 'customer' === $args['role'];
					}
				)
			)
			->andReturn( 202 );

		Functions\expect( 'update_user_meta' )->never();

		global $wpdb;
		$wpdb = new class() {
			public $prefix = 'wp_';
			public function update() {
				return 1;
			}
		};

		$response = $sync->create_wp_user_from_better_auth_user( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['woocommerce_customer_created'] );
		$this->assertTrue( Better_Auth_Test_WC_Customer_Stub::$saved['saved'] );
		$this->assertSame( 'John', Better_Auth_Test_WC_Customer_Stub::$saved['first_name'] );
		$this->assertSame( 'Woo Tester', Better_Auth_Test_WC_Customer_Stub::$saved['last_name'] );
	}
}
