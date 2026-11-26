<?php
/**
 * Printable booking voucher.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingView;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * /?wpitravel_voucher={key}&token={token}: a print-ready page customers can
 * print or save as PDF from the browser. Works for every language and
 * script, without a PDF library. Themes can override wpitravel/voucher.php.
 */
final class Voucher {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * Renders the voucher and stops.
	 */
	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page protected by the signed token.
		if ( empty( $_GET['wpitravel_voucher'] ) ) {
			return;
		}

		$key   = sanitize_key( wp_unslash( $_GET['wpitravel_voucher'] ) );
		$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable

		$booking = ( new BookingRepository() )->find_by_key( $key );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! $booking || ! BookingAccess::can_access( $booking, '', $token ) ) {
			wp_die( esc_html__( 'This voucher link is not valid.', 'wpinterface-travel' ), esc_html__( 'Voucher', 'wpinterface-travel' ), array( 'response' => 404 ) );
		}

		if ( in_array( $booking['status'], BookingRepository::RELEASED_STATUSES, true ) ) {
			wp_die( esc_html__( 'This booking was cancelled, so it has no voucher.', 'wpinterface-travel' ), esc_html__( 'Voucher', 'wpinterface-travel' ), array( 'response' => 410 ) );
		}

		$view          = BookingView::for_customer( $booking );
		$meeting_point = (string) get_post_meta( (int) $booking['trip_id'], TripMeta::MEETING_POINT, true );
		$currency      = array_merge( (array) wpitravel()->options()->get( 'currency', array() ), array( 'code' => $booking['currency'] ) );
		$template      = locate_template( 'wpitravel/voucher.php' );

		include '' !== $template ? $template : WPITRAVEL_PATH . 'templates/voucher/voucher.php';
		exit;
	}
}
