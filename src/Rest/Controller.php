<?php
/**
 * Base REST controller.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WPInterface\Travel\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared namespace, permission helpers and error format for every controller.
 *
 * See docs/rest-api.md for the full contract.
 */
abstract class Controller {

	public const NAMESPACE = 'wpinterface-travel/v1';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers the controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback requiring a capability.
	 *
	 * @param string $capability Capability name.
	 * @return callable(): (bool|WP_Error)
	 */
	protected function require_capability( string $capability ): callable {
		return static function () use ( $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}

			return new WP_Error(
				'wpitravel_forbidden',
				__( 'You are not allowed to do that.', 'wpinterface-travel' ),
				array( 'status' => rest_authorization_required_code() )
			);
		};
	}

	/**
	 * Formats an amount for the API: a string with fixed decimals, never a float.
	 *
	 * @param string|int|float $amount   Amount as stored.
	 * @param int              $decimals Decimal places of the currency.
	 */
	protected static function money( $amount, int $decimals = 2 ): string {
		return number_format( (float) $amount, $decimals, '.', '' );
	}
}
