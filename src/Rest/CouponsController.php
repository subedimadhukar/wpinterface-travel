<?php
/**
 * Coupons endpoint.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\Coupons;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /coupons and GET/PATCH/DELETE /coupons/{id} for staff.
 */
final class CouponsController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$permission = $this->require_capability( Capabilities::MANAGE_COUPONS );

		register_rest_route(
			self::NAMESPACE,
			'/coupons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_coupons' ),
					'permission_callback' => $permission,
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_coupon' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/coupons/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_coupon' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_coupon' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_coupon' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * A page of coupons.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_coupons( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = ( new Coupons() )->paginate( (int) $request['page'], $per_page, (string) $request['search'] );
		$response = new WP_REST_Response( array_map( array( Coupons::class, 'to_api' ), $result['items'] ) );

		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );

		return $response;
	}

	/**
	 * Creates a coupon from the JSON or form body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_coupon( WP_REST_Request $request ) {
		$saved = ( new Coupons() )->save( self::body( $request ) );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( Coupons::to_api( $saved ), 201 );
	}

	/**
	 * One coupon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_coupon( WP_REST_Request $request ) {
		$coupon = ( new Coupons() )->find( (int) $request['id'] );

		if ( ! $coupon ) {
			return self::not_found();
		}

		return new WP_REST_Response( Coupons::to_api( $coupon ) );
	}

	/**
	 * Updates the fields sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_coupon( WP_REST_Request $request ) {
		$saved = ( new Coupons() )->save( self::body( $request ), (int) $request['id'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( Coupons::to_api( $saved ) );
	}

	/**
	 * Deletes a coupon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_coupon( WP_REST_Request $request ) {
		if ( ! ( new Coupons() )->delete( (int) $request['id'] ) ) {
			return self::not_found();
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Coupon fields from the request body (not the URL).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		unset( $body['id'], $body['usage_count'] );

		return $body;
	}

	/**
	 * 404 error.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'wpitravel_coupon_not_found', __( 'Coupon not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}
}
