<?php
/**
 * Enquiry endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Enquiries\Enquiries;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * POST /enquiries (public), GET /enquiries and PATCH/DELETE /enquiries/{id} (staff).
 */
final class EnquiriesController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$staff = $this->require_capability( Capabilities::MANAGE_ENQUIRIES );

		register_rest_route(
			self::NAMESPACE,
			'/enquiries',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'trip_id' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'name'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'required'          => true,
						),
						'email'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'required'          => true,
						),
						'phone'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'message' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'required'          => true,
						),
						'website' => array(
							'description'       => __( 'Leave empty. Bots fill it in.', 'wpinterface-travel' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_enquiries' ),
					'permission_callback' => $staff,
					'args'                => array(
						'status'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
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
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/enquiries/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $staff,
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => Enquiries::STATUSES,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $staff,
				),
			)
		);
	}

	/**
	 * Saves an enquiry from the public form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'enquiries', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wpitravel_rate_limited', __( 'Too many messages. Wait a few minutes and try again.', 'wpinterface-travel' ), array( 'status' => 429 ) );
		}

		// Honeypot: humans never see this field. Pretend success so bots learn nothing.
		if ( '' !== (string) $request['website'] ) {
			return new WP_REST_Response( array( 'received' => true ), 201 );
		}

		/** This filter is documented in src/Rest/BookingController.php */
		$check = apply_filters( 'wpitravel_verify_request', true, $request, 'enquiries' );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$enquiry = ( new Enquiries() )->create( $request->get_params(), RateLimiter::client_ip() );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		return new WP_REST_Response( array( 'received' => true ), 201 );
	}

	/**
	 * Inbox.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_enquiries( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = ( new Enquiries() )->paginate( (string) $request['status'], (string) $request['search'], (int) $request['page'], $per_page );
		$response = new WP_REST_Response( $result['items'] );

		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );

		return $response;
	}

	/**
	 * Changes the status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$enquiries = new Enquiries();

		if ( ! $enquiries->find( (int) $request['id'] ) ) {
			return self::not_found();
		}

		$enquiries->set_status( (int) $request['id'], (string) $request['status'] );

		return new WP_REST_Response( $enquiries->find( (int) $request['id'] ) );
	}

	/**
	 * Deletes an enquiry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		if ( ! ( new Enquiries() )->delete( (int) $request['id'] ) ) {
			return self::not_found();
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'wpitravel_enquiry_not_found', __( 'Enquiry not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}
}
