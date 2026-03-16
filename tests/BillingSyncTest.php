<?php
/**
 * Tests for billing sync endpoint and HMAC verification.
 *
 * @package Better_Auth\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class Better_Auth_Test_Billing_WooCommerce_Stub {}

class Better_Auth_Test_Billing_WC_Customer_Stub {
	public static $saved = array();

	public function __construct( $user_id ) {
		self::$saved['user_id'] = $user_id;
	}

	public function set_billing_first_name( $value ) {
		self::$saved['billing_first_name'] = $value;
	}

	public function set_billing_last_name( $value ) {
		self::$saved['billing_last_name'] = $value;
	}

	public function set_billing_address_1( $value ) {
		self::$saved['billing_address_1'] = $value;
	}

	public function set_billing_address_2( $value ) {
		self::$saved['billing_address_2'] = $value;
	}

	public function set_billing_city( $value ) {
		self::$saved['billing_city'] = $value;
	}

	public function set_billing_state( $value ) {
		self::$saved['billing_state'] = $value;
	}

	public function set_billing_postcode( $value ) {
		self::$saved['billing_postcode'] = $value;
	}

	public function set_billing_country( $value ) {
		self::$saved['billing_country'] = $value;
	}

	public function set_billing_phone( $value ) {
		self::$saved['billing_phone'] = $value;
	}

	public function set_billing_email( $value ) {
		self::$saved['billing_email'] = $value;
	}

	public function set_shipping_first_name( $value ) {
		self::$saved['shipping_first_name'] = $value;
	}

	public function set_shipping_last_name( $value ) {
		self::$saved['shipping_last_name'] = $value;
	}

	public function set_shipping_address_1( $value ) {
		self::$saved['shipping_address_1'] = $value;
	}

	public function set_shipping_address_2( $value ) {
		self::$saved['shipping_address_2'] = $value;
	}

	public function set_shipping_city( $value ) {
		self::$saved['shipping_city'] = $value;
	}

	public function set_shipping_state( $value ) {
		self::$saved['shipping_state'] = $value;
	}

	public function set_shipping_postcode( $value ) {
		self::$saved['shipping_postcode'] = $value;
	}

	public function set_shipping_country( $value ) {
		self::$saved['shipping_country'] = $value;
	}

	public function set_email( $value ) {
		self::$saved['email'] = $value;
	}

	public function save() {
		self::$saved['saved'] = true;
	}
}

class BillingSyncTest extends \PHPUnit\Framework\TestCase {

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

	public function test_verify_hmac_signature_accepts_valid_request(): void {
		$sync      = new Better_Auth_User_Sync();
		$secret    = 'super-secret';
		$key_id    = 'better-auth-prod';
		$timestamp = (string) time();
		$nonce     = 'nonce-123';
		$route     = '/better-auth/v1/sync/billing';
		$method    = 'PATCH';
		$body      = '{"ba_user_id":"ba-1","billing_address":{"city":"NYC"}}';

		Functions\expect( 'get_option' )
			->twice()
			->with( 'better_auth_api_keys', array() )
			->andReturn(
				array(
					array(
						'key_id' => $key_id,
						'secret' => $secret,
						'status' => 'active',
					),
				)
			);
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();
		Functions\expect( 'get_transient' )
			->once()
			->with( 'better_auth_sig_nonce_' . md5( $nonce ) )
			->andReturn( false );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'better_auth_sig_nonce_' . md5( $nonce ), 1, 600 );
		Functions\expect( 'update_option' )
			->once()
			->with( 'better_auth_api_keys', Mockery::type( 'array' ), false );

		$signature = $this->build_signature( $method, $route, $timestamp, $nonce, $body, $secret );
		$request   = $this->build_signed_request( $method, $route, $body, $key_id, $timestamp, $nonce, $signature );

		$result = $sync->verify_hmac_sync_signature( $request );

		$this->assertTrue( $result );
	}

	public function test_verify_hmac_signature_rejects_invalid_signature(): void {
		$sync      = new Better_Auth_User_Sync();
		$secret    = 'super-secret';
		$key_id    = 'better-auth-prod';
		$timestamp = (string) time();
		$nonce     = 'nonce-1234';
		$route     = '/better-auth/v1/sync/billing';
		$method    = 'PATCH';
		$body      = '{"ba_user_id":"ba-1"}';

		Functions\expect( 'get_option' )
			->once()
			->with( 'better_auth_api_keys', array() )
			->andReturn(
				array(
					array(
						'key_id' => $key_id,
						'secret' => $secret,
						'status' => 'active',
					),
				)
			);
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();
		Functions\expect( 'get_transient' )
			->once()
			->with( 'better_auth_sig_nonce_' . md5( $nonce ) )
			->andReturn( false );
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'update_option' )->never();

		$request = $this->build_signed_request( $method, $route, $body, $key_id, $timestamp, $nonce, 'bad-signature' );
		$result  = $sync->verify_hmac_sync_signature( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_invalid_signature', $result->get_error_code() );
	}

	public function test_verify_hmac_signature_rejects_replay_nonce(): void {
		$sync      = new Better_Auth_User_Sync();
		$secret    = 'super-secret';
		$key_id    = 'better-auth-prod';
		$timestamp = (string) time();
		$nonce     = 'nonce-replay';
		$route     = '/better-auth/v1/sync/billing';
		$method    = 'PATCH';
		$body      = '{"ba_user_id":"ba-1"}';
		$signature = $this->build_signature( $method, $route, $timestamp, $nonce, $body, $secret );

		Functions\expect( 'get_option' )
			->once()
			->with( 'better_auth_api_keys', array() )
			->andReturn(
				array(
					array(
						'key_id' => $key_id,
						'secret' => $secret,
						'status' => 'active',
					),
				)
			);
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();
		Functions\expect( 'get_transient' )
			->once()
			->with( 'better_auth_sig_nonce_' . md5( $nonce ) )
			->andReturn( true );
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'update_option' )->never();

		$request = $this->build_signed_request( $method, $route, $body, $key_id, $timestamp, $nonce, $signature );
		$result  = $sync->verify_hmac_sync_signature( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_replay', $result->get_error_code() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_billing_details_updates_wp_and_woo_data(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			class_alias( Better_Auth_Test_Billing_WooCommerce_Stub::class, 'WooCommerce' );
		}

		if ( ! class_exists( 'WC_Customer', false ) ) {
			class_alias( Better_Auth_Test_Billing_WC_Customer_Stub::class, 'WC_Customer' );
		}

		$sync = new Better_Auth_User_Sync();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'update_user_meta' )->zeroOrMoreTimes()->andReturn( true );
		Functions\expect( 'wp_update_user' )
			->once()
			->with(
				array(
					'ID'         => 321,
					'user_email' => 'jane@example.com',
				)
			)
			->andReturn( 321 );

		global $wpdb;
		$wpdb = new class() {
			public $prefix = 'wp_';

			public function prepare( $query, $value ) {
				return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
			}

			public function get_row( $query ) {
				return (object) array( 'wpUserId' => 321 );
			}
		};

		$request = new WP_REST_Request();
		$request->set_param( 'ba_user_id', 'ba-200' );
		$request->set_param(
			'billing_address',
			array(
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'address_1'  => '123 Main St',
				'address_2'  => 'Apt 4',
				'city'       => 'Austin',
				'state'      => 'TX',
				'postcode'   => '78701',
				'country'    => 'US',
				'email'      => 'jane@example.com',
				'phone'      => '555-2222',
			)
		);

		$response = $sync->sync_billing_details( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 321, $data['wp_user_id'] );
		$this->assertSame( 'ba-200', $data['better_auth_user_id'] );
		$this->assertTrue( $data['woocommerce_customer_updated'] );
		$this->assertSame( 'Austin', $data['billing_address']['city'] );

		$this->assertTrue( Better_Auth_Test_Billing_WC_Customer_Stub::$saved['saved'] );
		$this->assertSame( 'Jane', Better_Auth_Test_Billing_WC_Customer_Stub::$saved['billing_first_name'] );
		$this->assertSame( 'jane@example.com', Better_Auth_Test_Billing_WC_Customer_Stub::$saved['billing_email'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_shipping_details_updates_wp_and_woo_data(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			class_alias( Better_Auth_Test_Billing_WooCommerce_Stub::class, 'WooCommerce' );
		}

		if ( ! class_exists( 'WC_Customer', false ) ) {
			class_alias( Better_Auth_Test_Billing_WC_Customer_Stub::class, 'WC_Customer' );
		}

		$sync = new Better_Auth_User_Sync();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\expect( 'wp_update_user' )->never();
		Functions\expect( 'update_user_meta' )->zeroOrMoreTimes()->andReturn( true );

		global $wpdb;
		$wpdb = new class() {
			public $prefix = 'wp_';

			public function prepare( $query, $value ) {
				return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
			}

			public function get_row( $query ) {
				return (object) array( 'wpUserId' => 444 );
			}
		};

		$request = new WP_REST_Request();
		$request->set_param( 'ba_user_id', 'ba-ship-1' );
		$request->set_param(
			'shipping_address',
			array(
				'first_name' => 'Ship',
				'last_name'  => 'Tester',
				'address_1'  => '500 Test Lane',
				'address_2'  => '',
				'city'       => 'Miami',
				'state'      => 'FL',
				'postcode'   => '33101',
				'country'    => 'US',
			)
		);

		$response = $sync->sync_shipping_details( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 444, $data['wp_user_id'] );
		$this->assertSame( 'ba-ship-1', $data['better_auth_user_id'] );
		$this->assertTrue( $data['woocommerce_customer_updated'] );
		$this->assertSame( 'Miami', $data['shipping_address']['city'] );

		$this->assertTrue( Better_Auth_Test_Billing_WC_Customer_Stub::$saved['saved'] );
		$this->assertSame( 'Ship', Better_Auth_Test_Billing_WC_Customer_Stub::$saved['shipping_first_name'] );
		$this->assertSame( 'Miami', Better_Auth_Test_Billing_WC_Customer_Stub::$saved['shipping_city'] );
	}

	private function build_signed_request( $method, $route, $body, $key_id, $timestamp, $nonce, $signature ) {
		$request = new WP_REST_Request();
		$request->set_method( $method );
		$request->set_route( $route );
		$request->set_body( $body );
		$request->set_header( 'X-BA-Key-Id', $key_id );
		$request->set_header( 'X-BA-Timestamp', $timestamp );
		$request->set_header( 'X-BA-Nonce', $nonce );
		$request->set_header( 'X-BA-Signature', $signature );

		return $request;
	}

	private function build_signature( $method, $route, $timestamp, $nonce, $body, $secret ) {
		$payload = implode(
			"\n",
			array(
				strtoupper( $method ),
				$route,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);

		return hash_hmac( 'sha256', $payload, $secret );
	}
}
