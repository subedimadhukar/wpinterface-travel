<?php
/**
 * Coupons screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Coupons. A React app over the /coupons REST routes.
 */
final class CouponsPage {

	public const SLUG = 'wpitravel-coupons';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the submenu under Travel, before Settings.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Coupons', 'wpinterface-travel' ),
			__( 'Coupons', 'wpinterface-travel' ),
			Capabilities::MANAGE_COUPONS,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the app on this screen only.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$trips = get_posts(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => array( 'publish', 'draft', 'future', 'private' ),
				'numberposts' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Admin-only list of trips for the coupon picker.
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		Assets::enqueue(
			'coupons',
			'wpitravelCoupons',
			array(
				'currency' => (string) wpitravel()->options()->get( 'currency.code', 'USD' ),
				'trips'    => array_map(
					static fn( $trip ): array => array(
						'value' => $trip->ID,
						'label' => get_the_title( $trip ),
					),
					$trips
				),
			)
		);
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Coupons', 'wpinterface-travel' ) . '</h1>';

		if ( ! wp_script_is( 'wpitravel-coupons', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-coupons"></div>';
		echo '</div>';
	}
}
