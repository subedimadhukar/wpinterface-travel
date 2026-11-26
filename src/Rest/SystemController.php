<?php
/**
 * System status endpoint.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Install\Upgrader;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET /system: versions and database health, for support and the setup wizard.
 */
final class SystemController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/system',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => $this->require_capability( Capabilities::MANAGE_SETTINGS ),
			)
		);
	}

	/**
	 * Returns plugin, database and environment status.
	 */
	public function get_status(): WP_REST_Response {
		global $wp_version;

		$missing = Schema::missing_tables();

		/**
		 * Filters whether WPInterface Travel Pro is active (Pro returns true).
		 *
		 * @param bool $active Whether Pro is active.
		 */
		$pro_active = (bool) apply_filters( 'wpitravel_pro_active', false );

		return new WP_REST_Response(
			array(
				'version'        => WPITRAVEL_VERSION,
				'db_version'     => (int) get_option( Upgrader::DB_VERSION_OPTION, 0 ),
				'db_version_req' => WPITRAVEL_DB_VERSION,
				'tables_ok'      => empty( $missing ),
				'missing_tables' => $missing,
				'currency'       => $this->plugin->options()->get( 'currency.code' ),
				'php'            => PHP_VERSION,
				'wordpress'      => $wp_version,
				'pro_active'     => $pro_active,
			)
		);
	}
}
