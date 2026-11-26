<?php
/**
 * Payment endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: POST /bookings/{key}/pay. Providers: POST /payments/{gateway}/webhook.
 * Staff: GET/POST /bookings/{id}/payments, POST /bookings/{id}/refunds.
 */
final class PaymentsController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<key>[a-f0-9]{32})/pay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pay' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'gateway' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments/(?P<gateway>[a-z0-9_-]+)/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				// Providers cannot log in; each gateway verifies the provider's signature.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/payments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_payments' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_BOOKINGS ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'record_payment' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
					'args'                => array(
						'amount'    => array(
							'type'              => array( 'string', 'number' ),
							'sanitize_callback' => array( Controller::class, 'sanitize_amount' ),
							'required'          => true,
						),
						'method'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => 'manual',
						),
						'note'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
						'reference' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/refunds',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refund' ),
				'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
				'args'                => array(
					'payment_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'amount'     => array(
						'type'              => array( 'string', 'number' ),
						'sanitize_callback' => array( Controller::class, 'sanitize_amount' ),
						'required'          => true,
					),
					'reason'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
					'manual'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Only record the refund (money returned outside the gateway).', 'wpinterface-travel' ),
					),
				),
			)
		);
	}

	/**
	 * Starts paying a booking's balance (e.g. after a cancelled PayPal attempt).
	 *
	 * The booking key is the customer's proof of the booking; paying it
	 * cannot change anything except adding money.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pay( WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'pay', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wpitravel_rate_limited', __( 'Too many requests. Wait a few minutes and try again.', 'wpinterface-travel' ), array( 'status' => 429 ) );
		}

		$booking = ( new BookingRepository() )->find_by_key( (string) $request['key'] );

		if ( ! $booking ) {
			return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$action = ( new PaymentService() )->start( $booking, sanitize_key( (string) $request['gateway'] ) );

		if ( is_wp_error( $action ) ) {
			return $action;
		}

		return new WP_REST_Response( array( 'payment' => $action ) );
	}

	/**
	 * Passes a provider notification to its gateway.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function webhook( WP_REST_Request $request ) {
		$gateway = Gateways::get( (string) $request['gateway'] );

		if ( ! $gateway || ! $gateway->is_enabled() ) {
			return new WP_Error( 'wpitravel_gateway_unavailable', __( 'Unknown payment method.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		return $gateway->handle_webhook( $request );
	}

	/**
	 * Ledger of a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_payments( WP_REST_Request $request ) {
		$booking = ( new BookingRepository() )->find( (int) $request['id'] );

		if ( ! $booking ) {
			return self::booking_not_found();
		}

		return new WP_REST_Response(
			array(
				'booking_id'     => $booking['id'],
				'currency'       => $booking['currency'],
				'total'          => $booking['total'],
				'amount_paid'    => $booking['amount_paid'],
				'balance'        => $booking['balance'],
				'payment_status' => $booking['payment_status'],
				'payment_method' => $booking['payment_method'],
				'payments'       => ( new PaymentRepository() )->for_booking( $booking['id'] ),
			)
		);
	}

	/**
	 * Records money received outside a gateway.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_payment( WP_REST_Request $request ) {
		$payment = ( new PaymentService() )->record_manual(
			(int) $request['id'],
			(string) $request['amount'],
			(string) $request['method'],
			sanitize_text_field( (string) $request['note'] ),
			sanitize_text_field( (string) $request['reference'] )
		);

		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		return new WP_REST_Response( $payment, 201 );
	}

	/**
	 * Refunds a payment of the booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund( WP_REST_Request $request ) {
		$payment = ( new PaymentRepository() )->find( (int) $request['payment_id'] );

		if ( ! $payment || $payment['booking_id'] !== (int) $request['id'] ) {
			return new WP_Error( 'wpitravel_payment_not_found', __( 'Payment not found on this booking.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$refund = ( new PaymentService() )->refund(
			$payment['id'],
			(string) $request['amount'],
			sanitize_text_field( (string) $request['reason'] ),
			(bool) $request['manual']
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		return new WP_REST_Response( $refund, 201 );
	}

	/**
	 * 404 error.
	 */
	private static function booking_not_found(): WP_Error {
		return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}
}
