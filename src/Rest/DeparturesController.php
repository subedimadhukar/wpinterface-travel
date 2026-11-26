<?php
/**
 * Staff departure management.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Departures\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Lists a trip's departures with every status, and lets staff close a date,
 * change its capacity or add a date outside the schedule.
 *
 * GET/POST /trips/{id}/departures, GET/PATCH/DELETE /departures/{id}.
 * Requires permission to edit the trip.
 */
final class DeparturesController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/trips/(?P<id>\d+)/departures',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_departures' ),
					'permission_callback' => array( $this, 'can_edit_trip' ),
					'args'                => AvailabilityController::range_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_departure' ),
					'permission_callback' => array( $this, 'can_edit_trip' ),
					'args'                => array_merge(
						array(
							'date' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'required'          => true,
								'validate_callback' => static fn( $value ) => '' !== Schedule::date( $value ),
							),
							'time' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'default'           => '',
								'validate_callback' => static fn( $value ) => '' === $value || '' !== Schedule::time( $value ),
							),
						),
						self::change_args()
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/departures/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_departure' ),
					'permission_callback' => array( $this, 'can_edit_departure' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_departure' ),
					'permission_callback' => array( $this, 'can_edit_departure' ),
					'args'                => self::change_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_departure' ),
					'permission_callback' => array( $this, 'can_edit_departure' ),
				),
			)
		);
	}

	/**
	 * Optional capacity and status arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function change_args(): array {
		return array(
			'capacity' => array(
				'description' => __( 'Seats for this date; null uses the trip default; 0 means no seats.', 'wpinterface-travel' ),
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 0,
			),
			'status'   => array(
				'type' => 'string',
				'enum' => DepartureRepository::STATUSES,
			),
		);
	}

	/**
	 * Permission: edit the trip in the URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_edit_trip( WP_REST_Request $request ) {
		$trip = get_post( (int) $request['id'] );

		if ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type ) {
			return new WP_Error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		return $this->edit_permission( $trip->ID );
	}

	/**
	 * Permission: edit the departure's trip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_edit_departure( WP_REST_Request $request ) {
		$row = ( new DepartureRepository() )->find( (int) $request['id'] );

		if ( ! $row ) {
			return new WP_Error( 'wpitravel_departure_not_found', __( 'Departure not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		return $this->edit_permission( $row['trip_id'] );
	}

	/**
	 * All departures of a trip in a range, every status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_departures( WP_REST_Request $request ) {
		$availability = new Availability();
		$range        = AvailabilityController::resolve_range( $request, $availability->today() );

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		return new WP_REST_Response(
			array(
				'trip_id'    => (int) $request['id'],
				'from'       => $range[0],
				'to'         => $range[1],
				'departures' => $availability->for_trip( (int) $request['id'], $range[0], $range[1], true ),
			)
		);
	}

	/**
	 * Adds a date or overrides a scheduled one (capacity, status).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_departure( WP_REST_Request $request ) {
		$trip_id  = (int) $request['id'];
		$date     = (string) $request['date'];
		$time     = (string) $request['time'];
		$schedule = Schedule::sanitize( get_post_meta( $trip_id, TripMeta::SCHEDULE, true ) );
		$slots    = Schedule::slots( $schedule, $date, $date );
		$source   = $slots[ $date . '|' . $time ]['source'] ?? 'manual';

		$repository = new DepartureRepository();
		$existed    = null !== $repository->find_slot( $trip_id, $date, $time );
		$row        = $repository->ensure( $trip_id, $date, $time, $source );

		if ( ! $row ) {
			return new WP_Error( 'wpitravel_departure_not_saved', __( 'The departure could not be saved.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$repository->update( $row['id'], self::changes( $request ) );

		$response = new WP_REST_Response( $repository->find( $row['id'] ) );
		$response->set_status( $existed ? 200 : 201 );

		return $response;
	}

	/**
	 * One departure row.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_departure( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( ( new DepartureRepository() )->find( (int) $request['id'] ) );
	}

	/**
	 * Changes capacity or status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_departure( WP_REST_Request $request ): WP_REST_Response {
		$repository = new DepartureRepository();
		$repository->update( (int) $request['id'], self::changes( $request ) );

		return new WP_REST_Response( $repository->find( (int) $request['id'] ) );
	}

	/**
	 * Deletes a departure without bookings. With bookings, cancel it instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_departure( WP_REST_Request $request ) {
		if ( ! ( new DepartureRepository() )->delete_unbooked( (int) $request['id'] ) ) {
			return new WP_Error(
				'wpitravel_departure_has_bookings',
				__( 'This departure has bookings. Set its status to cancelled instead.', 'wpinterface-travel' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Capacity and status present in the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function changes( WP_REST_Request $request ): array {
		$changes = array();
		$params  = $request->get_params();

		if ( array_key_exists( 'capacity', $params ) ) {
			$changes['capacity'] = null === $params['capacity'] ? null : (int) $params['capacity'];
		}

		if ( isset( $params['status'] ) ) {
			$changes['status'] = (string) $params['status'];
		}

		return $changes;
	}

	/**
	 * True when the user can edit the trip, else a 403/401 error.
	 *
	 * @param int $trip_id Trip ID.
	 * @return bool|WP_Error
	 */
	private function edit_permission( int $trip_id ) {
		if ( current_user_can( 'edit_post', $trip_id ) ) {
			return true;
		}

		return new WP_Error(
			'wpitravel_forbidden',
			__( 'You are not allowed to do that.', 'wpinterface-travel' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
