<?php
/**
 * Who may see a booking.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may see or act on a booking, and builds customer links.
 *
 * A customer proves a booking with any of:
 * - being logged in as its owner;
 * - the signed token in links we email (HMAC of booking key + email, keyed
 *   with the site's secret salt, so it cannot be guessed or forged);
 * - the booking email address, together with the booking key.
 * Staff with "view bookings" can see every booking.
 */
final class BookingAccess {

	/**
	 * Signed token for a booking's customer links.
	 *
	 * @param array<string, mixed> $booking Booking.
	 */
	public static function token( array $booking ): string {
		$email = strtolower( (string) ( ( new BookingRepository() )->customer( $booking )['email'] ?? '' ) );

		return substr( hash_hmac( 'sha256', $booking['booking_key'] . '|' . $email, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether the current visitor may see the booking.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param string               $email   Email typed by the visitor ('' for none).
	 * @param string               $token   Token from a link ('' for none).
	 */
	public static function can_access( array $booking, string $email = '', string $token = '' ): bool {
		$user_id = get_current_user_id();

		if ( $user_id > 0 && (int) $booking['user_id'] === $user_id ) {
			return true;
		}

		if ( current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			return true;
		}

		if ( '' !== $token && hash_equals( self::token( $booking ), $token ) ) {
			return true;
		}

		$stored = strtolower( (string) ( ( new BookingRepository() )->customer( $booking )['email'] ?? '' ) );

		return '' !== $email && '' !== $stored && hash_equals( $stored, strtolower( trim( $email ) ) );
	}

	/**
	 * Link to the booking on the "My bookings" page.
	 *
	 * @param array<string, mixed>  $booking Booking.
	 * @param array<string, string> $extra   Extra query arguments.
	 */
	public static function manage_url( array $booking, array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'booking' => $booking['booking_key'],
					'token'   => self::token( $booking ),
				),
				$extra
			),
			self::account_url()
		);
	}

	/**
	 * Link to the printable voucher.
	 *
	 * @param array<string, mixed> $booking Booking.
	 */
	public static function voucher_url( array $booking ): string {
		return add_query_arg(
			array(
				'wpitravel_voucher' => $booking['booking_key'],
				'token'             => self::token( $booking ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Address of the "My bookings" page (home page when none is set).
	 */
	public static function account_url(): string {
		$page = (int) wpitravel()->options()->get( 'booking.account_page_id', 0 );
		$url  = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';

		return $url ? $url : home_url( '/' );
	}

	/**
	 * Human-readable booking status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'pending'          => __( 'Awaiting confirmation', 'wpinterface-travel' ),
			'confirmed'        => __( 'Confirmed', 'wpinterface-travel' ),
			'completed'        => __( 'Completed', 'wpinterface-travel' ),
			'cancel_requested' => __( 'Cancellation requested', 'wpinterface-travel' ),
			'cancelled'        => __( 'Cancelled', 'wpinterface-travel' ),
			'failed'           => __( 'Failed', 'wpinterface-travel' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Human-readable payment status.
	 *
	 * @param string $status Payment status.
	 */
	public static function payment_status_label( string $status ): string {
		$labels = array(
			'unpaid'             => __( 'Not paid', 'wpinterface-travel' ),
			'partial'            => __( 'Partly paid', 'wpinterface-travel' ),
			'paid'               => __( 'Paid', 'wpinterface-travel' ),
			'refunded'           => __( 'Refunded', 'wpinterface-travel' ),
			'partially_refunded' => __( 'Partly refunded', 'wpinterface-travel' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
