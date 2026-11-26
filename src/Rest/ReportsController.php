<?php
/**
 * Reports REST endpoint.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\BookingSearch;
use WPInterface\Travel\Reports\Reports;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reports/summary?period=30d (or from/to) for the Travel dashboard.
 */
final class ReportsController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reports/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => $this->require_capability( Capabilities::VIEW_REPORTS ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => Reports::PERIODS,
						'default' => '30d',
					),
					'from'   => array(
						'type'    => 'string',
						'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						'default' => '',
					),
					'to'     => array(
						'type'    => 'string',
						'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Dashboard figures.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function summary( WP_REST_Request $request ) {
		$from = (string) $request['from'];
		$to   = (string) $request['to'];

		if ( '' === $from || '' === $to ) {
			list( $from, $to ) = Reports::range( (string) $request['period'] );
		}

		if ( $from > $to || ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > 731 ) {
			return new WP_Error( 'wpitravel_invalid_range', __( 'Choose a date range of up to two years.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$recent = current_user_can( Capabilities::VIEW_BOOKINGS ) ? BookingSearch::run( array( 'per_page' => 6 ) )['items'] : array();

		return new WP_REST_Response(
			Reports::summary( $from, $to ) + array(
				'attention' => Reports::attention(),
				'upcoming'  => Reports::upcoming(),
				'recent'    => $recent,
			)
		);
	}
}
