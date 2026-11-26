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

	/**
	 * REST sanitizer for amounts sent as text or numbers ("49.50", 49.5).
	 *
	 * @param mixed $value Value.
	 */
	public static function sanitize_amount( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * REST sanitizer for counts keyed by name, e.g. pax { adult: 2 }.
	 *
	 * @param mixed $value Value.
	 * @return array<string, int>
	 */
	public static function sanitize_counts( $value ): array {
		$counts = array();

		foreach ( is_array( $value ) ? $value : array() as $key => $count ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key && is_scalar( $count ) ) {
				$counts[ $key ] = absint( $count );
			}
		}

		return $counts;
	}

	/**
	 * REST sanitizer for nested objects (customer, travelers, add-on
	 * fields): keys become keys, text stays plain text (line breaks kept),
	 * other scalars keep their type. Services clean each field again.
	 *
	 * @param mixed $value Value.
	 * @return array<int|string, mixed>
	 */
	public static function sanitize_object( $value ): array {
		return self::clean_object( $value, 0 );
	}

	/**
	 * Cleans one level of a nested object.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting depth.
	 * @return array<int|string, mixed>
	 */
	private static function clean_object( $value, int $depth ): array {
		$clean = array();

		if ( ! is_array( $value ) || $depth > 4 ) {
			return $clean;
		}

		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? $key : sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$clean[ $key ] = self::clean_object( $item, $depth + 1 );
			} elseif ( is_string( $item ) ) {
				$clean[ $key ] = sanitize_textarea_field( $item );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$clean[ $key ] = $item;
			}
		}

		return $clean;
	}
}
