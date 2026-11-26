<?php
/**
 * Bookings REST endpoints for staff.
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
use WPInterface\Travel\Booking\BookingSearch;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Emails\Templates;
use WPInterface\Travel\Payments\Gateway;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET    /bookings                 list and search (view_bookings)
 * GET    /bookings/{id}            full booking (view_bookings)
 * PATCH  /bookings/{id}            change status (manage_bookings)
 * POST   /bookings/{id}/notes      add a staff note (manage_bookings)
 * POST   /bookings/manual          enter a booking by hand (manage_bookings)
 *
 * Payments, refunds and resending emails use the routes of
 * PaymentsController and EmailsController.
 */
final class BookingsAdminController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_bookings' ),
				'permission_callback' => $this->require_capability( Capabilities::VIEW_BOOKINGS ),
				'args'                => array(
					'status'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'payment_status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'trip_id'        => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'search'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'date_from'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'date_to'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'orderby'        => array(
						'type'    => 'string',
						'enum'    => BookingSearch::ORDERBY,
						'default' => 'created',
					),
					'order'          => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
					'page'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/manual',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_manual' ),
				'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
				'args'                => array(
					'trip_id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'date'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'required'          => true,
					),
					'time'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'price_option'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'pax'            => array(
						'type'                 => 'object',
						'sanitize_callback'    => array( Controller::class, 'sanitize_counts' ),
						'required'             => true,
						'additionalProperties' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
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
					'status'         => array(
						'type'    => 'string',
						'enum'    => array( 'pending', 'confirmed' ),
						'default' => 'pending',
					),
					'notify'         => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'payment_method' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_booking' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_BOOKINGS ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_booking' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'enum'     => BookingRepository::STATUSES,
							'required' => true,
						),
						'note'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
				'args'                => array(
					'note' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'required'          => true,
						'minLength'         => 1,
					),
				),
			)
		);
	}

	/**
	 * Lists bookings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_bookings( WP_REST_Request $request ): WP_REST_Response {
		$result   = BookingSearch::run( $request->get_params() );
		$response = new WP_REST_Response(
			array(
				'items'  => $result['items'],
				'counts' => BookingSearch::status_counts(),
			)
		);

		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

		return $response;
	}

	/**
	 * One booking with everything staff need.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking( WP_REST_Request $request ) {
		$booking = ( new BookingRepository() )->find( (int) $request['id'] );

		return $booking ? new WP_REST_Response( self::detail( $booking ) ) : self::not_found();
	}

	/**
	 * Changes the status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_booking( WP_REST_Request $request ) {
		$booking = ( new BookingService() )->set_status( (int) $request['id'], (string) $request['status'], sanitize_textarea_field( (string) $request['note'] ) );

		return is_wp_error( $booking ) ? $booking : new WP_REST_Response( self::detail( $booking ) );
	}

	/**
	 * Adds a note to the history (staff only; customers never see it).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_note( WP_REST_Request $request ) {
		$repository = new BookingRepository();
		$booking    = $repository->find( (int) $request['id'] );
		$note       = sanitize_textarea_field( (string) $request['note'] );

		if ( ! $booking ) {
			return self::not_found();
		}

		if ( '' === trim( $note ) ) {
			return new WP_Error( 'wpitravel_empty_note', __( 'Write a note first.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$repository->log( $booking['id'], 'note', $note );

		return new WP_REST_Response( self::detail( $booking ), 201 );
	}

	/**
	 * Enters a booking by hand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_manual( WP_REST_Request $request ) {
		$booking = ( new BookingService() )->create_manual( $request->get_params(), get_current_user_id() );

		return is_wp_error( $booking ) ? $booking : new WP_REST_Response( self::detail( $booking ), 201 );
	}

	/**
	 * The full booking for the admin screen.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	public static function detail( array $booking ): array {
		/**
		 * Filters a booking as the admin screen gets it (Pro adds its
		 * payment plan and invoice).
		 *
		 * @param array $detail  Detail.
		 * @param array $booking Booking.
		 */
		return (array) apply_filters( 'wpitravel_admin_booking_detail', self::build_detail( $booking ), $booking );
	}

	/**
	 * The booking for the admin screen, before filters.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	private static function build_detail( array $booking ): array {
		$repository = new BookingRepository();
		$customer   = $repository->customer( $booking );
		$methods    = array();

		foreach ( Gateways::all() as $gateway ) {
			$methods[ $gateway->id() ] = $gateway->admin_title();
		}

		$payments = array_map(
			static function ( array $payment ) use ( $methods ): array {
				$payment['method_label'] = $methods[ (string) $payment['gateway'] ] ?? __( 'Recorded by staff', 'wpinterface-travel' );

				return $payment;
			},
			( new PaymentRepository() )->for_booking( $booking['id'] )
		);

		$history = array_map(
			static function ( array $row ): array {
				$actor = (int) $row['actor_id'] > 0 ? get_userdata( (int) $row['actor_id'] ) : false;

				return array(
					'id'      => (int) $row['id'],
					'event'   => (string) $row['event'],
					'message' => (string) $row['message'],
					'actor'   => $actor ? $actor->display_name : '',
					'created' => (string) $row['created'],
				);
			},
			array_reverse( $repository->history( $booking['id'] ) )
		);

		$emails = array();

		foreach ( Templates::definitions() as $id => $definition ) {
			if ( 'booking' === $definition['context'] ) {
				$emails[] = array(
					'id'        => $id,
					'title'     => $definition['title'],
					'recipient' => $definition['recipient'],
				);
			}
		}

		return array_merge(
			BookingSearch::summary( $booking, $customer ),
			array(
				'booking_key'    => $booking['booking_key'],
				'price_option'   => $booking['price_option'],
				'payment_method' => $booking['payment_method'],
				'payment_label'  => $methods[ (string) $booking['payment_method'] ] ?? '',
				'subtotal'       => $booking['subtotal'],
				'discount_total' => $booking['discount_total'],
				'tax_total'      => $booking['tax_total'],
				'coupon_code'    => $booking['coupon_code'],
				'customer_note'  => (string) $booking['customer_note'],
				'customer'       => $customer + array(
					'name' => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
				),
				'trip'           => array(
					'id'       => $booking['trip_id'],
					'title'    => html_entity_decode( get_the_title( $booking['trip_id'] ), ENT_QUOTES, 'UTF-8' ),
					'url'      => (string) get_permalink( $booking['trip_id'] ),
					'edit_url' => (string) get_edit_post_link( $booking['trip_id'], 'raw' ),
				),
				'items'          => $repository->items( $booking['id'] ),
				'travelers'      => array_map(
					static fn( array $row ): array => array(
						'first_name' => (string) $row['first_name'],
						'last_name'  => (string) $row['last_name'],
						'email'      => (string) $row['email'],
						'phone'      => (string) $row['phone'],
						'category'   => (string) $row['category'],
						'lead'       => (bool) $row['is_lead'],
					),
					$repository->travelers( $booking['id'] )
				),
				'payments'       => $payments,
				'history'        => $history,
				'emails'         => $emails,
				'statuses'       => array_combine(
					BookingRepository::STATUSES,
					array_map( array( BookingAccess::class, 'status_label' ), BookingRepository::STATUSES )
				),
				'manage_url'     => BookingAccess::manage_url( $booking ),
				'voucher_url'    => BookingAccess::voucher_url( $booking ),
				'gateways'       => array_values(
					array_map(
						static fn( Gateway $gateway ): array => array(
							'id'     => $gateway->id(),
							'title'  => $gateway->admin_title(),
							'online' => $gateway->is_online(),
						),
						Gateways::all()
					)
				),
			)
		);
	}

	/**
	 * Not found error.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}
}
