<?php
/**
 * Setup wizard screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Import\Importers;
use WPInterface\Travel\Setup\Setup;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * The setup wizard (currency, booking rules, payments, sample trips,
 * importing from another plugin). It opens once after activation, can be
 * reopened from Travel → Settings, and is listed in Tools → Import.
 */
final class SetupPage {

	public const SLUG = 'wpitravel-setup';

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
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_init', array( $this, 'register_importers' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'admin_title', array( $this, 'admin_title' ) );
	}

	/**
	 * Browser title (the page has no menu item to take it from).
	 *
	 * @param string $title Title.
	 */
	public function admin_title( $title ): string {
		$screen = get_current_screen();

		if ( $screen && '' !== $this->hook && $screen->id === $this->hook ) {
			/* translators: %s: site name. */
			return sprintf( __( 'Set up WPInterface Travel ‹ %s', 'wpinterface-travel' ), get_bloginfo( 'name' ) );
		}

		return (string) $title;
	}

	/**
	 * Screen URL.
	 *
	 * @param string $step Step to open.
	 */
	public static function url( string $step = '' ): string {
		$url = admin_url( 'edit.php?post_type=' . PostTypes::TRIP . '&page=' . self::SLUG );

		return '' !== $step ? add_query_arg( 'step', $step, $url ) : $url;
	}

	/**
	 * Registers the page without a menu item.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Set up WPInterface Travel', 'wpinterface-travel' ),
			__( 'Setup', 'wpinterface-travel' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';

		remove_submenu_page( 'edit.php?post_type=' . PostTypes::TRIP, self::SLUG );
	}

	/**
	 * Opens the wizard once after activation.
	 */
	public function maybe_redirect(): void {
		if ( ! get_option( Setup::REDIRECT_OPTION ) || wp_doing_ajax() || is_network_admin() || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		delete_option( Setup::REDIRECT_OPTION );

		if ( ! Setup::is_done() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Lists the importers in Tools → Import.
	 */
	public function register_importers(): void {
		if ( ! function_exists( 'register_importer' ) || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		// WordPress prints the admin header before running an importer, so
		// send our importers to the wizard here, before any output.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which importer screen was opened.
		$requested = isset( $_GET['import'] ) ? sanitize_key( wp_unslash( $_GET['import'] ) ) : '';

		if ( 0 === strpos( $requested, 'wpitravel-' ) && isset( Importers::all()[ substr( $requested, 10 ) ] ) ) {
			wp_safe_redirect( self::url( 'import' ) );
			exit;
		}

		foreach ( Importers::all() as $importer ) {
			register_importer(
				'wpitravel-' . $importer->id(),
				/* translators: %s: plugin name, e.g. WP Travel. */
				sprintf( __( '%s trips', 'wpinterface-travel' ), $importer->label() ),
				/* translators: %s: plugin name. */
				sprintf( __( 'Copy trips from %s into WPInterface Travel.', 'wpinterface-travel' ), $importer->label() ),
				static function () {
					printf( '<div class="wrap"><p><a class="button button-primary" href="%1$s">%2$s</a></p></div>', esc_url( self::url( 'import' ) ), esc_html__( 'Open the importer', 'wpinterface-travel' ) );
				}
			);
		}
	}

	/**
	 * Reminds staff to finish setup, on Travel screens only.
	 */
	public function notice(): void {
		$screen = get_current_screen();

		if ( Setup::is_done() || ! $screen || $screen->id === $this->hook || PostTypes::TRIP !== $screen->post_type || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Set your currency, booking rules and payment methods in a few steps.', 'wpinterface-travel' ),
			esc_url( self::url() ),
			esc_html__( 'Run setup', 'wpinterface-travel' )
		);
	}

	/**
	 * Loads the wizard.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$currencies = array();

		foreach ( Currencies::all() as $code => $currency ) {
			$currencies[] = array(
				'value' => $code,
				'label' => sprintf( '%1$s (%2$s)', $currency['name'] ?? $code, $code ),
			);
		}

		$archive = get_post_type_archive_link( PostTypes::TRIP );

		Assets::enqueue(
			'setup',
			'wpitravelSetup',
			array(
				'currencies' => $currencies,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which step to open.
				'step'       => isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '',
				'urls'       => array(
					'newTrip'  => admin_url( 'post-new.php?post_type=' . PostTypes::TRIP ),
					'trips'    => admin_url( 'edit.php?post_type=' . PostTypes::TRIP ),
					'settings' => SettingsPage::url(),
					'bookings' => BookingsPage::url(),
					'site'     => $archive ? $archive : home_url( '/' ),
				),
			)
		);
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap wpitravel-setup-wrap">';

		if ( ! wp_script_is( 'wpitravel-setup', 'enqueued' ) ) {
			echo '<h1>' . esc_html__( 'Set up WPInterface Travel', 'wpinterface-travel' ) . '</h1>';
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-setup"></div></div>';
	}
}
