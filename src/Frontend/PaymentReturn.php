<?php
/**
 * Return from a payment provider.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Payments\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Handles /?wpitravel_payment_return={gateway}&booking={key}…, then sends the
 * customer back to the trip page, where the booking widget shows the result.
 */
final class PaymentReturn {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Handles a return request.
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Coming back from the provider; the booking key and the provider's order ID are checked instead.
		if ( empty( $_GET['wpitravel_payment_return'] ) ) {
			return;
		}

		$gateway = Gateways::get( sanitize_key( wp_unslash( $_GET['wpitravel_payment_return'] ) ) );
		$params  = array();

		// Gateways read their own return parameters (PayPal: token, booking, cancel).
		foreach ( array_slice( array_keys( $_GET ), 0, 20 ) as $name ) {
			$key = sanitize_key( (string) $name );

			if ( '' !== $key && $key === $name && isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				$params[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		// phpcs:enable

		$booking = ( new BookingRepository() )->find_by_key( (string) ( $params['booking'] ?? '' ) );
		$result  = $gateway && $booking ? $gateway->handle_return( $params ) : 'failed';
		$target  = $booking ? get_permalink( $booking['trip_id'] ) : home_url( '/' );

		$args = array( 'wpitravel_result' => $result );

		if ( $booking ) {
			$booking = ( new BookingRepository() )->find( $booking['id'] );

			$args['wpitravel_booking'] = $booking['booking_key'];
			$args['wpitravel_number']  = $booking['number'];
		}

		nocache_headers();
		wp_safe_redirect( add_query_arg( $args, $target ? $target : home_url( '/' ) ) . '#wpitravel-booking' );
		exit;
	}
}
