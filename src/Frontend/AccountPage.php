<?php
/**
 * "My bookings" page.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Admin\Assets;
use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Payments\Gateway;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_account]: customers see their bookings, pay a balance, ask to
 * cancel, print the voucher and write a review. Guests open a booking from
 * the link in our emails, or with the booking number and email.
 */
final class AccountPage {

	public const SHORTCODE = 'wpitravel_account';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Creates the page on install, unless one is set and published.
	 */
	public static function ensure(): void {
		$options = wpitravel()->options();
		$current = (int) $options->get( 'booking.account_page_id', 0 );

		if ( $current && 'publish' === get_post_status( $current ) ) {
			return;
		}

		// Reuse a published page that already holds the shortcode (e.g. after an interrupted install).
		$existing = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				's'           => '[' . self::SHORTCODE . ']',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		if ( $existing ) {
			$options->update( array( 'booking' => array( 'account_page_id' => (int) $existing[0] ) ) );

			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'My bookings', 'wpinterface-travel' ),
				'post_name'    => 'my-bookings',
				'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$options->update( array( 'booking' => array( 'account_page_id' => (int) $page_id ) ) );
		}
	}

	/**
	 * Shortcode output.
	 */
	public function render(): string {
		if ( ! Assets::enqueue( 'account', '', array(), array() ) ) {
			return '';
		}

		// The account app reuses the booking widget's styles, built into style-booking.css.
		Assets::enqueue_style( 'booking' );

		$options = wpitravel()->options();
		$code    = (string) $options->get( 'currency.code', 'USD' );
		$user    = wp_get_current_user();

		wp_add_inline_script(
			'wpitravel-account',
			'window.wpitravelAccount = ' . wp_json_encode(
				array(
					'loggedIn' => $user->exists(),
					'userName' => $user->exists() ? $user->display_name : '',
					'loginUrl' => wp_login_url( BookingAccess::account_url() ),
					'currency' => array_merge( (array) $options->get( 'currency', array() ), array( 'symbol' => Currencies::all()[ $code ]['symbol'] ?? $code ) ),
					'gateways' => array_values(
						array_map(
							static fn( Gateway $gateway ): array => array(
								'id'     => $gateway->id(),
								'title'  => $gateway->title(),
								'online' => $gateway->is_online(),
							),
							Gateways::available( $code )
						)
					),
					'reviews'  => (bool) $options->get( 'reviews.enabled', true ),
					'pageUrl'  => BookingAccess::account_url(),
				)
			) . ';',
			'before'
		);

		return '<div class="wpitravel-account"><noscript>' . esc_html__( 'Turn on JavaScript to see your bookings.', 'wpinterface-travel' ) . '</noscript></div>';
	}
}
