<?php
/**
 * Travel dashboard.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Reports\Reports;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Dashboard (sales over time, top trips, upcoming departures and
 * bookings that need attention), plus a summary widget on the WordPress
 * dashboard.
 */
final class DashboardPage {

	public const SLUG = 'wpitravel-dashboard';

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
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . PostTypes::TRIP . '&page=' . self::SLUG );
	}

	/**
	 * Adds the submenu at the top of the Travel menu.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Travel Dashboard', 'wpinterface-travel' ),
			__( 'Dashboard', 'wpinterface-travel' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG,
			array( $this, 'render' ),
			0
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

		$currency = (array) wpitravel()->options()->get( 'currency', array() );
		$code     = (string) ( $currency['code'] ?? 'USD' );

		Assets::enqueue(
			'dashboard',
			'wpitravelDashboard',
			array(
				'currency'    => $currency + array( 'code' => $code ),
				'symbol'      => Currencies::all()[ $code ]['symbol'] ?? $code,
				'dateFormat'  => (string) get_option( 'date_format' ),
				'bookingsUrl' => BookingsPage::url(),
				'newTripUrl'  => admin_url( 'post-new.php?post_type=' . PostTypes::TRIP ),
				'canBook'     => current_user_can( Capabilities::MANAGE_BOOKINGS ),
				'proUrl'      => Admin::show_pro_prompts() ? Admin::pro_url( 'dashboard' ) : '',
			)
		);
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Travel Dashboard', 'wpinterface-travel' ) . '</h1>';

		if ( ! wp_script_is( 'wpitravel-dashboard', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-dashboard"></div></div>';
	}

	/**
	 * Adds the "Travel bookings" widget for staff.
	 */
	public function add_widget(): void {
		if ( current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			wp_add_dashboard_widget( 'wpitravel_bookings', __( 'Travel bookings', 'wpinterface-travel' ), array( $this, 'render_widget' ) );
		}
	}

	/**
	 * Widget: what needs doing, and the last 7 days.
	 */
	public function render_widget(): void {
		$attention = Reports::attention();
		$week      = Reports::summary( ...Reports::range( '7d' ) );
		$currency  = (array) wpitravel()->options()->get( 'currency', array() );
		$links     = array(
			'pending'          => array(
				/* translators: %s: number of bookings. */
				_n( '%s booking awaiting confirmation', '%s bookings awaiting confirmation', $attention['pending'], 'wpinterface-travel' ),
				BookingsPage::url( array( 'status' => 'pending' ) ),
			),
			'cancel_requested' => array(
				/* translators: %s: number of bookings. */
				_n( '%s cancellation request', '%s cancellation requests', $attention['cancel_requested'], 'wpinterface-travel' ),
				BookingsPage::url( array( 'status' => 'cancel_requested' ) ),
			),
			'unpaid_soon'      => array(
				/* translators: %s: number of bookings. */
				_n( '%s unpaid booking leaving within a week', '%s unpaid bookings leaving within a week', $attention['unpaid_soon'], 'wpinterface-travel' ),
				BookingsPage::url( array( 'payment_status' => 'unpaid' ) ),
			),
		);

		echo '<ul>';

		foreach ( $links as $key => $link ) {
			if ( $attention[ $key ] > 0 ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $link[1] ), esc_html( sprintf( $link[0], number_format_i18n( $attention[ $key ] ) ) ) );
			}
		}

		if ( ! array_filter( $attention ) ) {
			echo '<li>' . esc_html__( 'Nothing waiting for you.', 'wpinterface-travel' ) . '</li>';
		}

		echo '</ul><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: number of bookings, 2: amount received. */
				__( 'Last 7 days: %1$s bookings, %2$s received.', 'wpinterface-travel' ),
				number_format_i18n( $week['bookings'] ),
				Money::format( $week['revenue'], $currency )
			)
		);
		echo '</p>';

		if ( current_user_can( Capabilities::VIEW_REPORTS ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( self::url() ), esc_html__( 'Open the Travel dashboard', 'wpinterface-travel' ) );
		}
	}
}
