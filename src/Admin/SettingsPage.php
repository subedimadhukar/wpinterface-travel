<?php
/**
 * Settings screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Rest\SettingsController;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Settings. A React app that saves through POST /settings.
 */
final class SettingsPage {

	public const SLUG = 'wpitravel-settings';

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
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * URL of the settings screen.
	 */
	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . PostTypes::TRIP . '&page=' . self::SLUG );
	}

	/**
	 * Adds the submenu under Travel.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Travel Settings', 'wpinterface-travel' ),
			__( 'Settings', 'wpinterface-travel' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the settings app on this screen only.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$data = SettingsController::screen_data( wpitravel()->options()->all() );

		$data['restPath'] = '/wpinterface-travel/v1/settings';
		$data['siteUrl']  = home_url( '/' );

		Assets::enqueue( 'settings', 'wpitravelSettings', $data );
	}

	/**
	 * Page shell; the app mounts into #wpitravel-settings.
	 */
	public function render(): void {
		echo '<div class="wrap wpitravel-settings-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Travel Settings', 'wpinterface-travel' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( SetupPage::url() ) . '">' . esc_html__( 'Setup wizard', 'wpinterface-travel' ) . '</a>';
		echo '<hr class="wp-header-end">';

		if ( ! wp_script_is( 'wpitravel-settings', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-settings"></div>';
		echo '</div>';
	}
}
