<?php
/**
 * PayPal.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments\Gateways;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Payments\Gateway;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal Checkout with the Orders v2 REST API.
 *
 * 1. start(): create an order for the booking balance and redirect the
 *    customer to PayPal to approve it.
 * 2. handle_return(): capture the approved order on the server.
 * 3. handle_webhook(): verify PayPal's signature, then capture approved
 *    orders, complete pending captures and record refunds made at PayPal.
 *
 * Every path ends in PaymentService::complete(), which is idempotent, so the
 * return and the webhook can both arrive without double counting.
 */
class PayPal extends Gateway {

	/**
	 * Currencies PayPal accepts.
	 */
	public const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' );

	/**
	 * Currencies PayPal requires as whole numbers.
	 */
	private const ZERO_DECIMAL = array( 'HUF', 'JPY', 'TWD' );

	/**
	 * Test double for the HTTP client.
	 *
	 * @var PayPalClient|null
	 */
	private ?PayPalClient $client = null;

	/**
	 * ID.
	 */
	public function id(): string {
		return 'paypal';
	}

	/**
	 * Staff-facing name.
	 */
	public function admin_title(): string {
		return __( 'PayPal', 'wpinterface-travel' );
	}

	/**
	 * Customers leave the site to pay.
	 */
	public function is_online(): bool {
		return true;
	}

	/**
	 * Sandbox payments are test payments.
	 */
	public function mode(): string {
		return ! empty( $this->settings()['sandbox'] ) ? 'test' : 'live';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'       => false,
			'title'         => __( 'PayPal', 'wpinterface-travel' ),
			'description'   => __( 'Pay securely with PayPal or a card.', 'wpinterface-travel' ),
			'sandbox'       => false,
			'client_id'     => '',
			'client_secret' => '',
			'webhook_id'    => '',
		);
	}

	/**
	 * Extra fields.
	 *
	 * @return list<array{key: string, label: string, type: string, help?: string}>
	 */
	public function settings_fields(): array {
		return array(
			array(
				'key'   => 'sandbox',
				'label' => __( 'Sandbox (test payments only)', 'wpinterface-travel' ),
				'type'  => 'toggle',
				'help'  => __( 'Use sandbox credentials from developer.paypal.com. Turn off before taking real bookings.', 'wpinterface-travel' ),
			),
			array(
				'key'   => 'client_id',
				'label' => __( 'Client ID', 'wpinterface-travel' ),
				'type'  => 'text',
			),
			array(
				'key'   => 'client_secret',
				'label' => __( 'Secret', 'wpinterface-travel' ),
				'type'  => 'password',
			),
			array(
				'key'   => 'webhook_id',
				'label' => __( 'Webhook ID', 'wpinterface-travel' ),
				'type'  => 'text',
				'help'  => __( 'In your PayPal app, add a webhook for the URL below with the events Checkout order approved, Payment capture completed, denied and refunded, then paste its ID here. Without it, payments still work when customers return to your site, but closed browser tabs and refunds made at PayPal are not picked up.', 'wpinterface-travel' ),
			),
		);
	}

	/**
	 * Secret fields.
	 *
	 * @return list<string>
	 */
	public function secret_fields(): array {
		return array( 'client_secret' );
	}

	/**
	 * Available when configured and the currency is supported.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public function is_available( string $currency ): bool {
		$settings = $this->settings();

		return parent::is_available( $currency )
			&& '' !== (string) $settings['client_id']
			&& '' !== (string) $settings['client_secret']
			&& in_array( strtoupper( $currency ), self::CURRENCIES, true );
	}

	/**
	 * Replaces the HTTP client (tests).
	 *
	 * @param PayPalClient|null $client Client.
	 */
	public function set_client( ?PayPalClient $client ): void {
		$this->client = $client;
	}

	/**
	 * Creates a PayPal order and returns the approval URL.
	 *
	 * @param array<string, mixed>      $booking Booking.
	 * @param array<string, mixed>|null $payment Pending ledger row.
	 * @return array{action: string, url: string}|WP_Error
	 */
	public function start( array $booking, ?array $payment ) {
		if ( ! $payment ) {
			return new WP_Error( 'wpitravel_payment_failed', __( 'The payment could not be started.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$return = add_query_arg(
			array(
				'wpitravel_payment_return' => $this->id(),
				'booking'                  => $booking['booking_key'],
			),
			home_url( '/' )
		);

		$order = $this->client()->request(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'reference_id' => (string) $booking['booking_key'],
						'custom_id'    => (string) $payment['id'],
						'invoice_id'   => self::invoice_id( $booking, $payment ),
						'description'  => self::truncate( get_the_title( (int) $booking['trip_id'] ) . ' · ' . $booking['start_date'], 127 ),
						'amount'       => array(
							'currency_code' => $payment['currency'],
							'value'         => self::amount( $payment['amount'], $payment['currency'] ),
						),
					),
				),
				'payment_source' => array(
					'paypal' => array(
						'experience_context' => array(
							'brand_name'          => self::truncate( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 127 ),
							'user_action'         => 'PAY_NOW',
							'shipping_preference' => 'NO_SHIPPING',
							'return_url'          => $return,
							'cancel_url'          => add_query_arg( 'cancel', '1', $return ),
						),
					),
				),
			),
			'wpitravel-order-' . $payment['id']
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$approve = '';

		foreach ( (array) ( $order['links'] ?? array() ) as $link ) {
			if ( in_array( $link['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
				$approve = (string) $link['href'];
			}
		}

		if ( empty( $order['id'] ) || '' === $approve ) {
			return new WP_Error( 'wpitravel_paypal_api', __( 'PayPal did not return a payment page.', 'wpinterface-travel' ), array( 'status' => 502 ) );
		}

		( new PaymentRepository() )->update(
			(int) $payment['id'],
			array(
				'reference'    => (string) $order['id'],
				'gateway_data' => array_merge( (array) $payment['gateway_data'], array( 'order_id' => (string) $order['id'] ) ),
			)
		);

		return array(
			'action' => 'redirect',
			'url'    => esc_url_raw( $approve ),
		);
	}

	/**
	 * Customer came back from PayPal: capture, or record the cancellation.
	 *
	 * @param array<string, string> $params Query parameters (token, booking, cancel).
	 * @return string paid, pending, cancelled or failed.
	 */
	public function handle_return( array $params ): string {
		$order_id = sanitize_text_field( (string) ( $params['token'] ?? '' ) );
		$payment  = '' !== $order_id ? ( new PaymentRepository() )->find_by_reference( $this->id(), $order_id ) : null;
		$booking  = $payment ? ( new BookingRepository() )->find( $payment['booking_id'] ) : null;

		// The order must belong to the booking in the URL.
		if ( ! $payment || ! $booking || ! hash_equals( (string) $booking['booking_key'], (string) ( $params['booking'] ?? '' ) ) ) {
			return 'failed';
		}

		if ( ! empty( $params['cancel'] ) ) {
			( new PaymentService() )->close_pending( $payment['id'], 'cancelled', 'Cancelled at PayPal.' );

			return 'completed' === $payment['status'] ? 'paid' : 'cancelled';
		}

		return $this->capture( $payment );
	}

	/**
	 * Verifies and handles a PayPal webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$webhook_id = (string) $this->settings()['webhook_id'];
		$raw        = (string) $request->get_body();
		$event      = json_decode( $raw, true );

		if ( '' === $webhook_id || ! is_array( $event ) ) {
			return new WP_Error( 'wpitravel_webhook_invalid', __( 'Webhook not configured or invalid.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		if ( ! $this->verify_signature( $request, $raw, $webhook_id ) ) {
			return new WP_Error( 'wpitravel_webhook_signature', __( 'Webhook signature could not be verified.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$type     = (string) ( $event['event_type'] ?? '' );
		$resource = (array) ( $event['resource'] ?? array() );
		$payments = new PaymentRepository();

		switch ( $type ) {
			case 'CHECKOUT.ORDER.APPROVED':
				$payment = $payments->find_by_reference( $this->id(), sanitize_text_field( (string) ( $resource['id'] ?? '' ) ) );

				if ( $payment && 'pending' === $payment['status'] ) {
					$this->capture( $payment );
				}
				break;

			case 'PAYMENT.CAPTURE.COMPLETED':
				$order_id = sanitize_text_field( (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' ) );
				$payment  = $payments->find_by_reference( $this->id(), $order_id );

				if ( $payment && 'COMPLETED' === ( $resource['status'] ?? '' ) ) {
					$capture_id = sanitize_text_field( (string) $resource['id'] );

					( new PaymentService() )->complete(
						$payment['id'],
						$capture_id,
						sanitize_text_field( (string) ( $resource['amount']['value'] ?? '0' ) ),
						sanitize_key( (string) ( $resource['amount']['currency_code'] ?? '' ) ),
						array( 'capture_id' => $capture_id )
					);
				}
				break;

			case 'PAYMENT.CAPTURE.DENIED':
			case 'PAYMENT.CAPTURE.DECLINED':
				$order_id = sanitize_text_field( (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' ) );
				$payment  = $payments->find_by_reference( $this->id(), $order_id );

				if ( $payment ) {
					( new PaymentService() )->close_pending( $payment['id'], 'failed', 'Capture denied by PayPal.' );
				}
				break;

			case 'PAYMENT.CAPTURE.REFUNDED':
				$capture_id = self::capture_id_from_links( (array) ( $resource['links'] ?? array() ) );
				$payment    = '' !== $capture_id ? $payments->find_by_transaction( $this->id(), $capture_id ) : null;

				if ( $payment && 'COMPLETED' === ( $resource['status'] ?? '' ) ) {
					( new PaymentService() )->record_provider_refund(
						$payment,
						sanitize_text_field( (string) ( $resource['amount']['value'] ?? '0' ) ),
						sanitize_text_field( (string) $resource['id'] )
					);
				}
				break;
		}

		return new WP_REST_Response( array( 'received' => true ) );
	}

	/**
	 * PayPal supports refunds of captures.
	 */
	public function supports_refunds(): bool {
		return true;
	}

	/**
	 * Refunds a capture.
	 *
	 * @param array<string, mixed> $payment Completed payment.
	 * @param string               $amount  Amount.
	 * @param string               $reason  Reason (shown to the payer).
	 * @return array{transaction_id: string, data: array<string, mixed>}|WP_Error
	 */
	public function refund( array $payment, string $amount, string $reason ) {
		$body = array(
			'amount' => array(
				'value'         => self::amount( $amount, $payment['currency'] ),
				'currency_code' => $payment['currency'],
			),
		);

		if ( '' !== $reason ) {
			$body['note_to_payer'] = self::truncate( $reason, 255 );
		}

		$refund = $this->client()->request(
			'POST',
			'/v2/payments/captures/' . rawurlencode( (string) $payment['transaction_id'] ) . '/refund',
			$body,
			'wpitravel-refund-' . $payment['id'] . '-' . md5( $amount . microtime() )
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		if ( empty( $refund['id'] ) || ! in_array( $refund['status'] ?? '', array( 'COMPLETED', 'PENDING' ), true ) ) {
			return new WP_Error( 'wpitravel_paypal_api', __( 'PayPal did not accept the refund.', 'wpinterface-travel' ), array( 'status' => 502 ) );
		}

		return array(
			'transaction_id' => (string) $refund['id'],
			'data'           => array( 'paypal_status' => (string) $refund['status'] ),
		);
	}

	/**
	 * Captures an approved order and completes the payment.
	 *
	 * @param array<string, mixed> $payment Pending payment with the order ID as reference.
	 * @return string paid, pending or failed.
	 */
	private function capture( array $payment ): string {
		if ( 'completed' === $payment['status'] ) {
			return 'paid';
		}

		// Only an open payment may take money: a cancelled or failed row (replaced
		// by a newer attempt, or its booking cancelled for not paying in time)
		// would charge the customer without crediting the booking.
		if ( 'pending' !== $payment['status'] ) {
			return 'failed';
		}

		$booking = ( new BookingRepository() )->find( (int) $payment['booking_id'] );

		if ( ! $booking || in_array( $booking['status'], BookingRepository::RELEASED_STATUSES, true ) ) {
			( new PaymentService() )->close_pending( (int) $payment['id'], 'cancelled', 'Booking closed before the payment was captured.' );

			return 'failed';
		}

		$order_id = (string) $payment['reference'];
		$order    = $this->client()->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', null, 'wpitravel-capture-' . $payment['id'], '{}' );

		// Already captured (e.g. by the webhook): read the order instead.
		if ( is_wp_error( $order ) && 'ORDER_ALREADY_CAPTURED' === ( $order->get_error_data()['issue'] ?? '' ) ) {
			$order = $this->client()->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $order_id ) );
		}

		if ( is_wp_error( $order ) ) {
			return 'failed';
		}

		$capture = (array) ( $order['purchase_units'][0]['payments']['captures'][0] ?? array() );
		$status  = (string) ( $capture['status'] ?? '' );

		if ( 'COMPLETED' === $status ) {
			$result = ( new PaymentService() )->complete(
				$payment['id'],
				(string) $capture['id'],
				(string) ( $capture['amount']['value'] ?? '0' ),
				(string) ( $capture['amount']['currency_code'] ?? '' ),
				array( 'capture_id' => (string) $capture['id'] )
			);

			return is_wp_error( $result ) ? 'failed' : 'paid';
		}

		if ( 'PENDING' === $status ) {
			// E.g. an eCheck: the webhook completes it later.
			( new PaymentRepository() )->update(
				(int) $payment['id'],
				array(
					'gateway_data' => array_merge(
						$payment['gateway_data'],
						array(
							'capture_id'     => (string) ( $capture['id'] ?? '' ),
							'pending_reason' => (string) ( $capture['status_details']['reason'] ?? '' ),
						)
					),
				)
			);

			return 'pending';
		}

		( new PaymentService() )->close_pending( $payment['id'], 'failed', 'PayPal capture status: ' . $status );

		return 'failed';
	}

	/**
	 * Asks PayPal whether a webhook really came from PayPal.
	 *
	 * The event is passed exactly as received, because re-encoding the JSON
	 * can change it and break the signature.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $raw        Raw body.
	 * @param string          $webhook_id Webhook ID from the PayPal app.
	 */
	private function verify_signature( WP_REST_Request $request, string $raw, string $webhook_id ): bool {
		$fields = array(
			'auth_algo'         => sanitize_text_field( (string) $request->get_header( 'paypal_auth_algo' ) ),
			'cert_url'          => esc_url_raw( (string) $request->get_header( 'paypal_cert_url' ), array( 'https' ) ),
			'transmission_id'   => sanitize_text_field( (string) $request->get_header( 'paypal_transmission_id' ) ),
			'transmission_sig'  => sanitize_text_field( (string) $request->get_header( 'paypal_transmission_sig' ) ),
			'transmission_time' => sanitize_text_field( (string) $request->get_header( 'paypal_transmission_time' ) ),
			'webhook_id'        => $webhook_id,
		);

		foreach ( $fields as $value ) {
			if ( '' === $value ) {
				return false;
			}
		}

		$body   = substr( (string) wp_json_encode( $fields ), 0, -1 ) . ',"webhook_event":' . $raw . '}';
		$result = $this->client()->request( 'POST', '/v1/notifications/verify-webhook-signature', null, '', $body );

		return ! is_wp_error( $result ) && 'SUCCESS' === ( $result['verification_status'] ?? '' );
	}

	/**
	 * Capture ID from a refund's "up" link (…/v2/payments/captures/{id}).
	 *
	 * @param array<mixed> $links Links.
	 */
	private static function capture_id_from_links( array $links ): string {
		foreach ( $links as $link ) {
			if ( 'up' === ( $link['rel'] ?? '' ) && preg_match( '#/captures/([^/]+)\z#', (string) ( $link['href'] ?? '' ), $match ) ) {
				return $match[1];
			}
		}

		return '';
	}

	/**
	 * Amount in PayPal's format for the currency.
	 *
	 * @param string $amount   Decimal string.
	 * @param string $currency Currency.
	 */
	private static function amount( string $amount, string $currency ): string {
		return Money::normalize( $amount, in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : 2 );
	}

	/**
	 * Invoice ID, unique per payment attempt (PayPal rejects reused invoice IDs).
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $payment Payment.
	 */
	private static function invoice_id( array $booking, array $payment ): string {
		/**
		 * Filters the PayPal invoice ID. Sites sharing one PayPal account
		 * should add a site prefix.
		 *
		 * @param string $invoice_id Default "TRV-12-34" (booking number and payment ID).
		 * @param array  $booking    Booking.
		 * @param array  $payment    Payment.
		 */
		return self::truncate( (string) apply_filters( 'wpitravel_paypal_invoice_id', $booking['number'] . '-' . $payment['id'], $booking, $payment ), 127 );
	}

	/**
	 * Shortens text to a byte limit without breaking characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max length.
	 */
	private static function truncate( string $text, int $limit ): string {
		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
	}

	/**
	 * The API client.
	 */
	private function client(): PayPalClient {
		if ( ! $this->client ) {
			$settings     = $this->settings();
			$this->client = new PayPalClient( (string) $settings['client_id'], (string) $settings['client_secret'], ! empty( $settings['sandbox'] ) );
		}

		return $this->client;
	}
}
