<?php
/**
 * Bookings screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Booking\BookingSearch;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Frontend\BookingWidget;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Bookings: list, search, booking details, payments, status
 * changes and bookings entered by staff. The menu shows how many bookings
 * wait for staff (awaiting confirmation or cancellation requested).
 */
final class BookingsPage {

	public const SLUG = 'wpitravel-bookings';

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 8 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string, scalar> $args Extra query arguments (booking, action).
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'edit.php?post_type=' . PostTypes::TRIP ) );
	}

	/**
	 * Adds the submenu right after "All Trips", with a badge.
	 */
	public function add_page(): void {
		$count = 0;

		if ( current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			$counts = BookingSearch::status_counts();
			$count  = $counts['pending'] + $counts['cancel_requested'];
		}

		$badge = $count > 0
			? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, number_format_i18n( $count ) )
			: '';

		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Bookings', 'wpinterface-travel' ),
			__( 'Bookings', 'wpinterface-travel' ) . $badge,
			Capabilities::VIEW_BOOKINGS,
			self::SLUG,
			array( $this, 'render' ),
			1
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the app.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		Assets::enqueue( 'bookings', 'wpitravelBookings', self::config() );
	}

	/**
	 * Data for the app.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$currency = (array) wpitravel()->options()->get( 'currency', array() );
		$code     = (string) ( $currency['code'] ?? 'USD' );
		$trips    = get_posts(
			array(
				'post_type'      => PostTypes::TRIP,
				'post_status'    => array( 'publish', 'private', 'draft', 'future' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Trip picker; agencies have hundreds of trips at most.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array(
			'currency'   => $currency + array( 'code' => $code ),
			'symbol'     => Currencies::all()[ $code ]['symbol'] ?? $code,
			'canManage'  => current_user_can( Capabilities::MANAGE_BOOKINGS ),
			'prefix'     => (string) wpitravel()->options()->get( 'booking.number_prefix', 'TRV-' ),
			'today'      => current_time( 'Y-m-d' ),
			'dateFormat' => (string) get_option( 'date_format' ),
			'trips'      => array_map(
				static fn( \WP_Post $trip ): array => BookingWidget::trip_data( $trip ) + array( 'status' => $trip->post_status ),
				$trips
			),
		);
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap">';

		if ( ! wp_script_is( 'wpitravel-bookings', 'enqueued' ) ) {
			echo '<h1>' . esc_html__( 'Bookings', 'wpinterface-travel' ) . '</h1>';
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-bookings"></div></div>';
	}
}
