<?php
/**
 * Review endpoints.
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
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * GET and POST /trips/{id}/reviews.
 */
final class ReviewsController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/trips/(?P<id>\d+)/reviews',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_reviews' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_review' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'rating'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'title'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'content'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'required'          => true,
						),
						'booking_key' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						),
						'token'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						),
						'name'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'email'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'default'           => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Published reviews and the rating summary of a trip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_reviews( WP_REST_Request $request ) {
		$trip = get_post( (int) $request['id'] );

		if ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type || 'publish' !== $trip->post_status || post_password_required( $trip ) ) {
			return new WP_Error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$summary  = Reviews::summary( $trip->ID );
		$per_page = (int) $request['per_page'];
		$response = new WP_REST_Response(
			array(
				'summary' => $summary,
				'reviews' => Reviews::list( $trip->ID, (int) $request['page'], $per_page ),
			)
		);

		$response->header( 'X-WP-Total', (string) $summary['count'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $summary['count'] / $per_page ) ) );

		return $response;
	}

	/**
	 * Saves a review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_review( WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'reviews', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wpitravel_rate_limited', __( 'Too many requests. Wait a few minutes and try again.', 'wpinterface-travel' ), array( 'status' => 429 ) );
		}

		$result = ( new Reviews() )->create( (int) $request['id'], $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}
}
