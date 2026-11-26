<?php
/**
 * PayPal REST client.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments\Gateways;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal client for the PayPal REST API (OAuth 2, Orders v2, Payments v2,
 * webhook signature verification). Access tokens are cached in a transient.
 */
class PayPalClient {

	private const LIVE    = 'https://api-m.paypal.com';
	private const SANDBOX = 'https://api-m.sandbox.paypal.com';

	/**
	 * Client ID.
	 *
	 * @var string
	 */
	private string $client_id;

	/**
	 * Client secret.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Use the sandbox.
	 *
	 * @var bool
	 */
	private bool $sandbox;

	/**
	 * Constructor.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @param bool   $sandbox   Use the sandbox.
	 */
	public function __construct( string $client_id, string $secret, bool $sandbox ) {
		$this->client_id = $client_id;
		$this->secret    = $secret;
		$this->sandbox   = $sandbox;
	}

	/**
	 * Calls the API.
	 *
	 * @param string                    $method          HTTP method.
	 * @param string                    $path            Path, e.g. /v2/checkout/orders.
	 * @param array<string, mixed>|null $body            JSON body.
	 * @param string                    $idempotency_key PayPal-Request-Id, '' for none.
	 * @param string|null               $raw_body        Pre-encoded JSON body (used as is).
	 * @return array<string, mixed>|WP_Error Decoded response.
	 */
	public function request( string $method, string $path, ?array $body = null, string $idempotency_key = '', ?string $raw_body = null ) {
		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		);

		if ( '' !== $idempotency_key ) {
			$headers['PayPal-Request-Id'] = $idempotency_key;
		}

		$response = wp_remote_request(
			$this->base() . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => null !== $raw_body ? $raw_body : ( null !== $body ? wp_json_encode( $body ) : null ),
				'timeout' => 30,
			)
		);

		return self::decode( $response );
	}

	/**
	 * An OAuth access token, cached until shortly before it expires.
	 *
	 * @return string|WP_Error
	 */
	public function token() {
		$cache_key = 'wpitravel_paypal_token_' . md5( $this->client_id . ( $this->sandbox ? 'sandbox' : 'live' ) );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'timeout' => 30,
			)
		);
		$data     = self::decode( $response );

		if ( is_wp_error( $data ) ) {
			return new WP_Error( 'wpitravel_paypal_auth', __( 'PayPal rejected the API credentials. Check the client ID, secret and sandbox setting.', 'wpinterface-travel' ), array( 'status' => 502 ) );
		}

		$token = (string) ( $data['access_token'] ?? '' );

		if ( '' === $token ) {
			return new WP_Error( 'wpitravel_paypal_auth', __( 'PayPal did not return an access token.', 'wpinterface-travel' ), array( 'status' => 502 ) );
		}

		set_transient( $cache_key, $token, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );

		return $token;
	}

	/**
	 * API base URL.
	 */
	private function base(): string {
		return $this->sandbox ? self::SANDBOX : self::LIVE;
	}

	/**
	 * Decodes a response; non-2xx becomes a WP_Error with PayPal's issue code.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpitravel_paypal_http', __( 'PayPal could not be reached. Try again in a moment.', 'wpinterface-travel' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code < 200 || $code >= 300 ) {
			$issue = (string) ( $data['details'][0]['issue'] ?? ( $data['name'] ?? ( $data['error'] ?? 'UNKNOWN' ) ) );

			return new WP_Error(
				'wpitravel_paypal_api',
				/* translators: %s: PayPal error code. */
				sprintf( __( 'PayPal returned an error (%s).', 'wpinterface-travel' ), $issue ),
				array(
					'status'    => 502,
					'http_code' => $code,
					'issue'     => $issue,
					'debug_id'  => (string) ( $data['debug_id'] ?? '' ),
				)
			);
		}

		return $data;
	}
}
