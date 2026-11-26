<?php
/**
 * Enquiries inbox.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Enquiries\Enquiries;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Enquiries, with the number of new enquiries in the menu.
 */
final class EnquiriesPage {

	public const SLUG = 'wpitravel-enquiries';

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
		add_action( 'admin_menu', array( $this, 'add_page' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the submenu with a badge.
	 */
	public function add_page(): void {
		$count = current_user_can( Capabilities::MANAGE_ENQUIRIES ) ? ( new Enquiries() )->count_new() : 0;
		$badge = $count > 0
			? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, number_format_i18n( $count ) )
			: '';

		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Enquiries', 'wpinterface-travel' ),
			__( 'Enquiries', 'wpinterface-travel' ) . $badge,
			Capabilities::MANAGE_ENQUIRIES,
			self::SLUG,
			array( $this, 'render' )
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

		Assets::enqueue( 'enquiries', 'wpitravelEnquiries', array( 'siteName' => get_bloginfo( 'name' ) ) );
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Enquiries', 'wpinterface-travel' ) . '</h1>';

		if ( ! wp_script_is( 'wpitravel-enquiries', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-enquiries"></div></div>';
	}
}
