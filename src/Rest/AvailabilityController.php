<?php
/**
 * Public availability endpoint.
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
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET /trips/{id}/availability: bookable departures for the booking widget.
 */
final class AvailabilityController extends Controller {

	/**
	 * Longest range one request may ask for, in days.
	 */
	public const MAX_RANGE_DAYS = 400;

	/**
	 * Default range when `to` is omitted, in days.
	 */
	public const DEFAULT_RANGE_DAYS = 90;

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/trips/(?P<id>\d+)/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					self::range_args(),
					array(
						'price_option' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'all'          => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Staff only: include closed and past cut-off dates.', 'wpinterface-travel' ),
						),
					)
				),
			)
		);
	}

	/**
	 * Shared `from` / `to` arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function range_args(): array {
		$date = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $value ) => '' === $value || '' !== Schedule::date( $value ),
			'default'           => '',
		);

		return array(
			'from' => $date + array( 'description' => __( 'First date (Y-m-d). Defaults to today.', 'wpinterface-travel' ) ),
			'to'   => $date + array( 'description' => __( 'Last date (Y-m-d). Defaults to 90 days after from.', 'wpinterface-travel' ) ),
		);
	}

	/**
	 * Resolves and checks the requested date range.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $today   Today, Y-m-d.
	 * @return array{0: string, 1: string}|WP_Error
	 */
	public static function resolve_range( WP_REST_Request $request, string $today ) {
		$from = (string) $request['from'];
		$from = '' !== $from ? $from : $today;
		$to   = (string) $request['to'];
		$to   = '' !== $to ? $to : gmdate( 'Y-m-d', (int) strtotime( $from . ' +' . self::DEFAULT_RANGE_DAYS . ' days' ) );

		if ( $to < $from ) {
			return new WP_Error( 'wpitravel_invalid_range', __( 'The end date must not be before the start date.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$days = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS );

		if ( $days > self::MAX_RANGE_DAYS ) {
			return new WP_Error(
				'wpitravel_invalid_range',
				/* translators: %d: maximum number of days. */
				sprintf( __( 'Ask for at most %d days at a time.', 'wpinterface-travel' ), self::MAX_RANGE_DAYS ),
				array( 'status' => 400 )
			);
		}

		return array( $from, $to );
	}

	/**
	 * Bookable departures of a published trip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_availability( WP_REST_Request $request ) {
		$trip = self::visible_trip( (int) $request['id'] );

		if ( is_wp_error( $trip ) ) {
			return $trip;
		}

		$availability = new Availability();
		$range        = self::resolve_range( $request, $availability->today() );

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$all        = $request['all'] && current_user_can( Capabilities::MANAGE_BOOKINGS );
		$departures = $availability->for_trip( $trip->ID, $range[0], $range[1], $all, (string) $request['price_option'] );
		$public     = array();

		foreach ( $departures as $departure ) {
			$public[] = array(
				'date'          => $departure['date'],
				'end_date'      => $departure['end_date'],
				'time'          => $departure['time'],
				'departure_id'  => $departure['departure_id'],
				'seats_left'    => $departure['seats_left'],
				'status'        => $departure['status'],
				'bookable'      => $departure['bookable'],
				'from_price'    => $departure['from_price'],
				'regular_price' => $departure['regular_price'],
				'sale'          => $departure['sale'],
			);
		}

		return new WP_REST_Response(
			array(
				'trip_id'    => $trip->ID,
				'currency'   => $this->plugin->options()->get( 'currency.code' ),
				'from'       => $range[0],
				'to'         => $range[1],
				'departures' => $public,
			)
		);
	}

	/**
	 * A trip the current visitor may see: published and not password-protected,
	 * or any trip the user can edit (previews).
	 *
	 * @param int $id Trip ID.
	 * @return WP_Post|WP_Error
	 */
	private static function visible_trip( int $id ) {
		$trip      = get_post( $id );
		$not_found = new WP_Error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );

		if ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type ) {
			return $not_found;
		}

		if ( current_user_can( 'edit_post', $trip->ID ) ) {
			return $trip;
		}

		if ( 'publish' !== $trip->post_status || post_password_required( $trip ) ) {
			return $not_found;
		}

		return $trip;
	}
}
