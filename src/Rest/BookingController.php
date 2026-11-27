<?php
/**
 * Checkout endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Booking\Holds;
use WPInterface\Travel\Booking\QuoteCalculator;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * POST /quote, POST /holds, DELETE /holds/{key}, POST /bookings.
 *
 * Public: guests can book. Abuse is limited per IP, and sites can add their
 * own check (e.g. a CAPTCHA) with the `wpitravel_verify_request` filter.
 */
final class BookingController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'quote' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					self::selection_args( true ),
					array(
						'staff' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Staff only: price dates past their booking cut-off, as when entering a booking by hand.', 'wpinterface-travel' ),
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/holds',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_hold' ),
				'permission_callback' => '__return_true',
				'args'                => self::selection_args( false ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/holds/(?P<key>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'release_hold' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'hold_key'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
						'pattern'           => '^[a-f0-9]{32}$',
					),
					'customer'       => array(
						'type'              => 'object',
						'sanitize_callback' => array( Controller::class, 'sanitize_object' ),
						'required'          => true,
					),
					'travelers'      => array(
						'type'              => 'array',
						'sanitize_callback' => array( Controller::class, 'sanitize_object' ),
						'default'           => array(),
						'items'             => array( 'type' => 'object' ),
					),
					'coupon'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'note'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
					'pickup'         => array(
						'description'       => __( 'Key of the chosen pickup point.', 'wpinterface-travel' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'locale'         => array(
						'description'       => __( 'Language of the page the booking was made on, e.g. fr_FR. Emails use it.', 'wpinterface-travel' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'create_account' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'terms_accepted' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'gateway'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					// Extra checkout answers (Pro), checked by `wpitravel_validate_booking`.
					'fields'         => array(
						'type'              => 'object',
						'sanitize_callback' => array( Controller::class, 'sanitize_object' ),
						'default'           => array(),
					),
				),
			)
		);
	}

	/**
	 * Arguments describing a selection.
	 *
	 * @param bool $with_coupon Whether a coupon may be sent.
	 * @return array<string, array<string, mixed>>
	 */
	private static function selection_args( bool $with_coupon ): array {
		$args = array(
			'trip_id'      => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			'date'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'required'          => true,
			),
			'time'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'price_option' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'pax'          => array(
				'type'                 => 'object',
				'sanitize_callback'    => array( Controller::class, 'sanitize_counts' ),
				'required'             => true,
				'additionalProperties' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			// Optional add-ons by key => quantity (Pro's tour extras).
			'extras'       => array(
				'type'                 => 'object',
				'sanitize_callback'    => array( Controller::class, 'sanitize_counts' ),
				'default'              => array(),
				'additionalProperties' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 999,
				),
			),
		);

		if ( $with_coupon ) {
			$args['coupon'] = array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			);
		}

		return $args;
	}

	/**
	 * Prices a selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function quote( WP_REST_Request $request ) {
		$blocked = self::guard( $request, 'quote', 120, 5 * MINUTE_IN_SECONDS );

		if ( $blocked ) {
			return $blocked;
		}

		$staff = $request['staff'] && current_user_can( Capabilities::MANAGE_BOOKINGS );
		$quote = ( new QuoteCalculator() )->calculate( self::selection( $request ), $staff ? array( 'statuses' => array( 'open', 'past_cutoff' ) ) : array() );

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		return new WP_REST_Response( QuoteCalculator::to_public( $quote ) );
	}

	/**
	 * Reserves seats for checkout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_hold( WP_REST_Request $request ) {
		$blocked = self::guard( $request, 'holds', 30, 10 * MINUTE_IN_SECONDS );

		if ( $blocked ) {
			return $blocked;
		}

		$selection = self::selection( $request );
		unset( $selection['coupon'] );

		$quote = ( new QuoteCalculator() )->calculate( $selection );

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		// Admins are not limited (they test checkout); everyone else is.
		$hold = ( new Holds() )->create( $quote, current_user_can( 'manage_options' ) ? '' : RateLimiter::client_key() );

		if ( is_wp_error( $hold ) ) {
			return $hold;
		}

		return new WP_REST_Response(
			array(
				'hold_key'   => $hold['hold_key'],
				'expires_at' => mysql_to_rfc3339( $hold['expires_at'] ) . 'Z',
				'quote'      => QuoteCalculator::to_public( $quote ),
			),
			201
		);
	}

	/**
	 * Releases a hold early (customer went back).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function release_hold( WP_REST_Request $request ): WP_REST_Response {
		( new Holds() )->release( (string) $request['key'] );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Creates a booking from a hold.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( WP_REST_Request $request ) {
		$blocked = self::guard( $request, 'bookings', 10, 10 * MINUTE_IN_SECONDS );

		if ( $blocked ) {
			return $blocked;
		}

		$booking = ( new BookingService() )->create(
			array(
				'hold_key'       => (string) $request['hold_key'],
				'customer'       => (array) $request['customer'],
				'travelers'      => (array) $request['travelers'],
				'coupon'         => (string) $request['coupon'],
				'note'           => (string) $request['note'],
				'locale'         => (string) $request['locale'],
				'pickup'         => (string) $request['pickup'],
				'create_account' => (bool) $request['create_account'],
				'terms_accepted' => (bool) $request['terms_accepted'],
				'gateway'        => (string) $request['gateway'],
				'fields'         => (array) $request['fields'],
			),
			get_current_user_id(),
			RateLimiter::client_ip()
		);

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		/**
		 * Filters what happens after booking: Phase 4 gateways return a
		 * redirect URL or client data here.
		 *
		 * @param array $payment Payment action: action (none|redirect|client), url, client_data, instructions.
		 * @param array $booking Booking.
		 */
		$payment = array(
			'action'       => 'none',
			'instructions' => '',
		);

		if ( '' !== $booking['payment_method'] ) {
			$started = ( new PaymentService() )->start( $booking, $booking['payment_method'] );

			// The booking is saved either way; a failed start can be retried with POST /bookings/{key}/pay.
			$payment = is_wp_error( $started )
				? array(
					'action'  => 'error',
					'message' => $started->get_error_message(),
				)
				: $started;
		}

		/**
		 * Filters the payment action returned after booking.
		 *
		 * @param array $payment Payment action: action (none|redirect|client|error), url, client_data, instructions.
		 * @param array $booking Booking.
		 */
		$payment = (array) apply_filters( 'wpitravel_booking_payment_action', $payment, $booking );

		return new WP_REST_Response(
			array(
				'booking_key' => $booking['booking_key'],
				'number'      => $booking['number'],
				'status'      => $booking['status'],
				'total'       => $booking['total'],
				'currency'    => $booking['currency'],
				'date'        => $booking['start_date'],
				'time'        => $booking['start_time'],
				'payment'     => $payment,
			),
			201
		);
	}

	/**
	 * The selection from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function selection( WP_REST_Request $request ): array {
		return array(
			'trip_id'      => (int) $request['trip_id'],
			'date'         => (string) $request['date'],
			'time'         => (string) $request['time'],
			'price_option' => (string) $request['price_option'],
			'pax'          => (array) $request['pax'],
			'extras'       => (array) $request['extras'],
			'coupon'       => (string) $request['coupon'],
		);
	}

	/**
	 * Applies the rate limit and the site's own check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $bucket  Action.
	 * @param int             $limit   Requests per window.
	 * @param int             $window  Window in seconds.
	 */
	private static function guard( WP_REST_Request $request, string $bucket, int $limit, int $window ): ?WP_Error {
		if ( ! RateLimiter::hit( $bucket, $limit, $window ) ) {
			return new WP_Error( 'wpitravel_rate_limited', __( 'Too many requests. Wait a few minutes and try again.', 'wpinterface-travel' ), array( 'status' => 429 ) );
		}

		/**
		 * Lets a site reject a checkout request, e.g. after a failed CAPTCHA.
		 *
		 * @param true|\WP_Error   $result  True to allow.
		 * @param WP_REST_Request $request Request.
		 * @param string          $action  quote, holds or bookings.
		 */
		$result = apply_filters( 'wpitravel_verify_request', true, $request, $bucket );

		return is_wp_error( $result ) ? $result : null;
	}
}
