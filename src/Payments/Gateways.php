<?php
/**
 * Gateway registry.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments;

use WPInterface\Travel\Payments\Gateways\BankTransfer;
use WPInterface\Travel\Payments\Gateways\PayLater;
use WPInterface\Travel\Payments\Gateways\PayPal;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the payment gateways.
 *
 * Add one with:
 *
 *     add_filter( 'wpitravel_payment_gateways', fn( $classes ) => array_merge( $classes, array( MyGateway::class ) ) );
 */
final class Gateways {

	/**
	 * Built gateways, keyed by ID.
	 *
	 * @var array<string, Gateway>|null
	 */
	private static ?array $gateways = null;

	/**
	 * All registered gateways, keyed by ID, in display order.
	 *
	 * @return array<string, Gateway>
	 */
	public static function all(): array {
		if ( null === self::$gateways ) {
			/**
			 * Filters the gateway classes. Each must extend Gateway.
			 *
			 * @param list<class-string<Gateway>> $classes Classes.
			 */
			$classes = (array) apply_filters( 'wpitravel_payment_gateways', array( PayPal::class, BankTransfer::class, PayLater::class ) );

			self::$gateways = array();

			foreach ( $classes as $class ) {
				if ( is_string( $class ) && is_subclass_of( $class, Gateway::class ) ) {
					$gateway                          = new $class();
					self::$gateways[ $gateway->id() ] = $gateway;
				}
			}
		}

		return self::$gateways;
	}

	/**
	 * Forgets built gateways (tests, or after a filter changed).
	 */
	public static function reset(): void {
		self::$gateways = null;
	}

	/**
	 * One gateway by ID.
	 *
	 * @param string $id Gateway ID.
	 */
	public static function get( string $id ): ?Gateway {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Gateways customers can use for a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return array<string, Gateway>
	 */
	public static function available( string $currency ): array {
		return array_filter( self::all(), static fn( Gateway $gateway ): bool => $gateway->is_available( $currency ) );
	}

	/**
	 * Whether any gateway is turned on at all.
	 */
	public static function any_enabled(): bool {
		foreach ( self::all() as $gateway ) {
			if ( $gateway->is_enabled() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Default settings of every gateway, for Options::defaults().
	 *
	 * Built without the registry cache, because settings are read while the
	 * registry may not be complete yet.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function default_settings(): array {
		$defaults = array();

		foreach ( self::all() as $id => $gateway ) {
			$defaults[ $id ] = $gateway->defaults();
		}

		return $defaults;
	}

	/**
	 * Settings paths of secret fields, e.g. payments.gateways.paypal.client_secret.
	 *
	 * @return list<string>
	 */
	public static function secret_paths(): array {
		$paths = array();

		foreach ( self::all() as $id => $gateway ) {
			foreach ( $gateway->secret_fields() as $field ) {
				$paths[] = 'payments.gateways.' . $id . '.' . $field;
			}
		}

		return $paths;
	}
}
