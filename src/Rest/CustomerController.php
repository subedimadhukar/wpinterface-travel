<?php
/**
 * Customer booking endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Booking\BookingView;
use WPInterface\Travel\Emails\Mailer;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * GET /bookings/{key}, POST /bookings/{key}/cancel-request,
 * POST /bookings/lookup and GET /my/bookings.
 *
 * Guests prove a booking with the token from our emails or the booking email
 * (see BookingAccess). Failed attempts are rate-limited per IP.
 */
final class CustomerController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$proof = array(
			'email' => array(
				'type'    => 'string',
				'default' => '',
			),
			'token' => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<key>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => '__return_true',
				'args'                => $proof,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<key>[a-f0-9]{32})/cancel-request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'request_cancellation' ),
				'permission_callback' => '__return_true',
				'args'                => $proof + array(
					'reason' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'number' => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/my/bookings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'my_bookings' ),
				'permission_callback' => static function () {
					return is_user_logged_in() ? true : new WP_Error( 'wpitravel_login_required', __( 'Log in to see your bookings.', 'wpinterface-travel' ), array( 'status' => 401 ) );
				},
			)
		);
	}

	/**
	 * One booking for its customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking( WP_REST_Request $request ) {
		$booking = $this->authorized_booking( $request );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$this->maybe_claim( $booking, (string) $request['token'] );

		return new WP_REST_Response( BookingView::for_customer( $booking ) );
	}

	/**
	 * Asks the agency to cancel. Staff decide and handle any refund.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_cancellation( WP_REST_Request $request ) {
		$booking = $this->authorized_booking( $request );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$view = BookingView::for_customer( $booking );

		if ( ! $view['can_cancel'] ) {
			return new WP_Error( 'wpitravel_cannot_cancel', __( 'This booking can no longer be cancelled online. Please contact us.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$reason  = substr( sanitize_textarea_field( (string) $request['reason'] ), 0, 1000 );
		$updated = ( new BookingService() )->set_status( (int) $booking['id'], 'cancel_requested', $reason );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return new WP_REST_Response( BookingView::for_customer( $updated ) );
	}

	/**
	 * Emails a link to a booking found by number and email.
	 *
	 * The link goes to the booking's own email address, never in the
	 * response: booking numbers are easy to guess, so knowing a number and
	 * an email must not be enough to open someone's booking. The answer is
	 * the same whether or not a booking matched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'lookup', 10, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}

		$number  = strtoupper( trim( (string) $request['number'] ) );
		$prefix  = strtoupper( (string) wpitravel()->options()->get( 'booking.number_prefix', 'TRV-' ) );
		$id      = 0 === strpos( $number, $prefix ) ? substr( $number, strlen( $prefix ) ) : $number;
		$booking = ctype_digit( $id ) ? ( new BookingRepository() )->find( (int) $id ) : null;

		if ( $booking && BookingAccess::can_access( $booking, (string) $request['email'] ) ) {
			// One email per booking every 5 minutes.
			$key = 'wpitravel_link_' . $booking['id'];

			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
				( new Mailer() )->send_booking( 'customer_booking_link', $booking );
			}
		}

		return new WP_REST_Response(
			array(
				'sent'    => true,
				'message' => __( 'If the booking number and email match a booking, we have emailed a link to it. Check your inbox (and spam folder).', 'wpinterface-travel' ),
			)
		);
	}

	/**
	 * Bookings of the logged-in user.
	 */
	public function my_bookings(): WP_REST_Response {
		$list = array();

		foreach ( ( new BookingRepository() )->for_user( get_current_user_id() ) as $booking ) {
			$view   = BookingView::for_customer( $booking );
			$list[] = array_intersect_key(
				$view,
				array_flip( array( 'booking_key', 'token', 'number', 'status', 'status_label', 'payment_status_label', 'trip', 'date', 'time', 'pax', 'total', 'balance', 'currency' ) )
			);
		}

		return new WP_REST_Response( $list );
	}

	/**
	 * The booking in the URL, if the visitor may see it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function authorized_booking( WP_REST_Request $request ) {
		$booking = ( new BookingRepository() )->find_by_key( (string) $request['key'] );
		$email   = (string) $request['email'];
		$token   = (string) $request['token'];

		if ( $booking && BookingAccess::can_access( $booking, $email, $token ) ) {
			return $booking;
		}

		// Only failed guesses count towards the limit.
		if ( ! RateLimiter::hit( 'booking_access', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}

		return self::not_found();
	}

	/**
	 * Attaches a guest booking to the logged-in user who opened its signed
	 * link, when the account email matches the booking email.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param string               $token   Token from the link.
	 */
	private function maybe_claim( array $booking, string $token ): void {
		$user = wp_get_current_user();

		if ( ! $user->exists() || 0 !== (int) $booking['user_id'] || '' === $token || ! hash_equals( BookingAccess::token( $booking ), $token ) ) {
			return;
		}

		$customer = ( new BookingRepository() )->customer( $booking );

		if ( strtolower( $customer['email'] ) === strtolower( $user->user_email ) ) {
			( new BookingRepository() )->assign_user( (int) $booking['id'], (int) $user->ID );
		}
	}

	/**
	 * 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'wpitravel_booking_not_found', __( 'We could not find a booking with these details.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}

	/**
	 * 429.
	 */
	private static function rate_limited(): WP_Error {
		return new WP_Error( 'wpitravel_rate_limited', __( 'Too many attempts. Wait a few minutes and try again.', 'wpinterface-travel' ), array( 'status' => 429 ) );
	}
}
