<?php
/**
 * Payment test helpers: gateway settings and a fake PayPal API.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WPInterface\Travel\Payments\Gateways;

/**
 * Answers PayPal API calls from a route table and records every request.
 */
trait PaymentFixtures {

	/**
	 * Responses: "METHOD /path" => [ HTTP code, body array ].
	 *
	 * @var array<string, array{0: int, 1: array<string, mixed>}>
	 */
	protected array $paypal_routes = array();

	/**
	 * Requests sent: list of [ "METHOD /path", body string ].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	protected array $paypal_requests = array();

	/**
	 * Turns gateways on and installs the fake PayPal API.
	 *
	 * @param array<string, array<string, mixed>> $gateways Gateway settings by ID.
	 */
	protected function set_up_payments( array $gateways ): void {
		wpitravel()->options()->update( array( 'payments' => array( 'gateways' => $gateways ) ) );
		Gateways::reset();

		$this->paypal_routes   = array(
			'POST /v1/oauth2/token' => array(
				200,
				array(
					'access_token' => 'TOKEN',
					'expires_in'   => 32400,
				),
			),
		);
		$this->paypal_requests = array();

		add_filter( 'pre_http_request', array( $this, 'fake_paypal' ), 10, 3 );
	}

	/**
	 * Default PayPal settings (sandbox, configured).
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	protected function paypal_settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'enabled'       => true,
				'sandbox'       => true,
				'client_id'     => 'client-id',
				'client_secret' => 'client-secret',
				'webhook_id'    => 'WH-1',
			),
			$overrides
		);
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_paypal( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'paypal.com' ) ) {
			return $pre;
		}

		$key                     = strtoupper( (string) ( $args['method'] ?? 'GET' ) ) . ' ' . wp_parse_url( $url, PHP_URL_PATH );
		$body                    = $args['body'] ?? '';
		$this->paypal_requests[] = array( $key, is_array( $body ) ? http_build_query( $body ) : (string) $body );
		$route                   = $this->paypal_routes[ $key ] ?? array( 404, array( 'name' => 'RESOURCE_NOT_FOUND' ) );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $route[1] ),
			'response' => array(
				'code'    => $route[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Requests sent to a route.
	 *
	 * @param string $key "METHOD /path".
	 * @return list<string> Bodies.
	 */
	protected function paypal_calls( string $key ): array {
		return array_values(
			array_map(
				static fn( array $request ): string => $request[1],
				array_filter( $this->paypal_requests, static fn( array $request ): bool => $request[0] === $key )
			)
		);
	}

	/**
	 * Fake order creation response.
	 *
	 * @param string $order_id Order ID.
	 */
	protected function paypal_order_created( string $order_id ): void {
		$this->paypal_routes['POST /v2/checkout/orders'] = array(
			201,
			array(
				'id'     => $order_id,
				'status' => 'PAYER_ACTION_REQUIRED',
				'links'  => array(
					array(
						'rel'  => 'self',
						'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . $order_id,
					),
					array(
						'rel'  => 'payer-action',
						'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $order_id,
					),
				),
			),
		);
	}

	/**
	 * Fake capture response.
	 *
	 * @param string $order_id   Order ID.
	 * @param string $capture_id Capture ID.
	 * @param string $amount     Amount captured.
	 * @param string $currency   Currency.
	 * @param string $status     Capture status.
	 */
	protected function paypal_capture( string $order_id, string $capture_id, string $amount, string $currency = 'USD', string $status = 'COMPLETED' ): void {
		$this->paypal_routes[ "POST /v2/checkout/orders/{$order_id}/capture" ] = array(
			201,
			array(
				'id'             => $order_id,
				'status'         => 'COMPLETED',
				'purchase_units' => array(
					array(
						'payments' => array(
							'captures' => array(
								array(
									'id'     => $capture_id,
									'status' => $status,
									'amount' => array(
										'currency_code' => $currency,
										'value'         => $amount,
									),
								),
							),
						),
					),
				),
			),
		);
	}
}
