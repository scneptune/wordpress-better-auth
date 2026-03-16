<?php

/**
 * Better Auth request signing and verification helpers.
 *
 * @package Better_Auth
 * @subpackage Better_Auth/includes
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Verifies HMAC signatures and replay protection for Better Auth sync requests.
 */
class Better_Auth_Request_Signer {

	/**
	 * HMAC timestamp drift tolerance in seconds.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	const SIGNATURE_TIMESTAMP_TTL = 300;

	/**
	 * Nonce replay cache TTL in seconds.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	const NONCE_CACHE_TTL = 600;

	/**
	 * Verify HMAC signature and replay protection headers.
	 *
	 * Required headers:
	 * - X-BA-Key-Id
	 * - X-BA-Timestamp
	 * - X-BA-Nonce
	 * - X-BA-Signature
	 *
	 * Signature payload:
	 * METHOD + "\n" + ROUTE + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256(BODY)
	 *
	 * @since 1.0.1
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function verify_request( $request ) {
		$key_id         = (string) $request->get_header( 'X-BA-Key-Id' );
		$timestamp      = (string) $request->get_header( 'X-BA-Timestamp' );
		$nonce          = (string) $request->get_header( 'X-BA-Nonce' );
		$sent_signature = (string) $request->get_header( 'X-BA-Signature' );

		if ( empty( $key_id ) || empty( $timestamp ) || empty( $nonce ) || empty( $sent_signature ) ) {
			return new WP_Error(
				'rest_bad_signature_headers',
				__( 'Missing required signature headers.', 'better-auth' ),
				array( 'status' => 400 )
			);
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error(
				'rest_bad_signature_timestamp',
				__( 'Invalid signature timestamp.', 'better-auth' ),
				array( 'status' => 400 )
			);
		}

		$credentials = $this->resolve_hmac_credentials_by_key_id( $key_id );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$stored_secret = $credentials['secret'];

		$timestamp_int = (int) $timestamp;
		if ( abs( time() - $timestamp_int ) > self::SIGNATURE_TIMESTAMP_TTL ) {
			return new WP_Error(
				'rest_forbidden_stale_request',
				__( 'Stale request timestamp.', 'better-auth' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->is_nonce_replayed( $nonce ) ) {
			return new WP_Error(
				'rest_forbidden_replay',
				__( 'Replay detected for nonce.', 'better-auth' ),
				array( 'status' => 409 )
			);
		}

		$method    = method_exists( $request, 'get_method' ) ? strtoupper( $request->get_method() ) : 'PATCH';
		$route     = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$raw_body  = $this->get_request_body_for_signature( $request );
		$body_hash = hash( 'sha256', $raw_body );

		$payload = implode(
			"\n",
			array(
				$method,
				$route,
				$timestamp,
				$nonce,
				$body_hash,
			)
		);

		$expected_signature = hash_hmac( 'sha256', $payload, $stored_secret );
		if ( ! hash_equals( $expected_signature, $sent_signature ) ) {
			return new WP_Error(
				'rest_forbidden_invalid_signature',
				__( 'Invalid request signature.', 'better-auth' ),
				array( 'status' => 403 )
			);
		}

		$this->mark_nonce_as_used( $nonce );
		$this->touch_key_last_used( $key_id );

		return true;
	}

	/**
	 * Resolve active keyring credentials for a provided key id.
	 *
	 * @since 1.0.1
	 * @param string $key_id Public key id sent by the client.
	 * @return array|WP_Error
	 */
	private function resolve_hmac_credentials_by_key_id( $key_id ) {
		$keyring = get_option( 'better_auth_api_keys', array() );

		if ( is_array( $keyring ) ) {
			foreach ( $keyring as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( empty( $entry['key_id'] ) || empty( $entry['secret'] ) ) {
					continue;
				}

				$status = isset( $entry['status'] ) ? $entry['status'] : 'active';
				if ( 'active' !== $status ) {
					continue;
				}

				if ( hash_equals( (string) $entry['key_id'], (string) $key_id ) ) {
					return array(
						'key_id' => (string) $entry['key_id'],
						'secret' => (string) $entry['secret'],
					);
				}
			}
		}

		return new WP_Error(
			'rest_forbidden_invalid_key_id',
			__( 'Invalid or revoked key id.', 'better-auth' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return a stable raw body string used for signature hashing.
	 *
	 * @since 1.0.1
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	private function get_request_body_for_signature( $request ) {
		if ( method_exists( $request, 'get_body' ) ) {
			return (string) $request->get_body();
		}

		return '';
	}

	/**
	 * Determine if nonce has already been used.
	 *
	 * @since 1.0.1
	 * @param string $nonce Nonce value.
	 * @return bool
	 */
	private function is_nonce_replayed( $nonce ) {
		$cache_key = 'better_auth_sig_nonce_' . md5( $nonce );
		return (bool) get_transient( $cache_key );
	}

	/**
	 * Persist nonce to short-lived cache for replay protection.
	 *
	 * @since 1.0.1
	 * @param string $nonce Nonce value.
	 */
	private function mark_nonce_as_used( $nonce ) {
		$cache_key = 'better_auth_sig_nonce_' . md5( $nonce );
		set_transient( $cache_key, 1, self::NONCE_CACHE_TTL );
	}

	/**
	 * Update key usage timestamp in the keyring.
	 *
	 * @since 1.0.1
	 * @param string $key_id Public key id sent by the client.
	 */
	private function touch_key_last_used( $key_id ) {
		$keyring = get_option( 'better_auth_api_keys', array() );

		if ( ! is_array( $keyring ) || empty( $keyring ) ) {
			return;
		}

		$updated = false;
		foreach ( $keyring as $index => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key_id'] ) ) {
				continue;
			}

			if ( hash_equals( (string) $entry['key_id'], (string) $key_id ) ) {
				$keyring[ $index ]['last_used_at'] = time();
				$updated = true;
				break;
			}
		}

		if ( $updated ) {
			update_option( 'better_auth_api_keys', $keyring, false );
		}
	}
}
