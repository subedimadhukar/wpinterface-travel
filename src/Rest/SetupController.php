<?php
/**
 * Setup, sample data and import REST endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Import\Importers;
use WPInterface\Travel\Setup\SampleData;
use WPInterface\Travel\Setup\Setup;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET    /setup                  wizard state (sample trips, importers found)
 * POST   /setup/complete         finish or skip the wizard
 * POST   /setup/sample-data      add the sample trips
 * DELETE /setup/sample-data      remove them
 * POST   /import/{importer}      import one batch of trips (offset)
 */
final class SetupController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$permission = $this->require_capability( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			self::NAMESPACE,
			'/setup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/setup/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/setup/sample-data',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_samples' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_samples' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<importer>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $permission,
				'args'                => array(
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Wizard state.
	 */
	public function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'done'      => Setup::is_done(),
				'samples'   => SampleData::ids(),
				'importers' => Importers::detected(),
			)
		);
	}

	/**
	 * Finishes the wizard.
	 */
	public function complete(): WP_REST_Response {
		Setup::complete();

		return $this->status();
	}

	/**
	 * Adds the sample trips.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_samples() {
		$ids = SampleData::create();

		return is_wp_error( $ids ) ? $ids : new WP_REST_Response( array( 'samples' => $ids ), 201 );
	}

	/**
	 * Removes the sample trips.
	 */
	public function remove_samples(): WP_REST_Response {
		return new WP_REST_Response( SampleData::remove() + array( 'samples' => SampleData::ids() ) );
	}

	/**
	 * Imports one batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$importer = Importers::get( (string) $request['importer'] );

		if ( ! $importer ) {
			return new WP_Error( 'wpitravel_unknown_importer', __( 'Unknown importer.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $importer->run( (int) $request['offset'] ) );
	}
}
