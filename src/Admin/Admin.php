<?php
/**
 * Admin screens.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every admin screen of the free plugin.
 */
final class Admin {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		( new DashboardPage() )->register();
		( new BookingsPage() )->register();
		( new EnquiriesPage() )->register();
		( new CouponsPage() )->register();
		( new EmailsPage() )->register();
		( new SettingsPage() )->register();
		( new SetupPage() )->register();
		( new TripEditor() )->register();
		( new TripList() )->register();

		add_filter( 'plugin_action_links_' . WPITRAVEL_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'test_mode_notice' ) );
	}

	/**
	 * Warns on Travel screens and the dashboard when a gateway takes test payments.
	 */
	public function test_mode_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ( PostTypes::TRIP !== $screen->post_type && 'dashboard' !== $screen->id ) ) {
			return;
		}

		foreach ( Gateways::all() as $gateway ) {
			if ( $gateway->is_enabled() && 'test' === $gateway->mode() ) {
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html(
						sprintf(
							/* translators: %s: payment method, e.g. PayPal. */
							__( '%s is in sandbox mode: bookings paid with it are test payments, not real money.', 'wpinterface-travel' ),
							$gateway->admin_title()
						)
					),
					esc_url( SettingsPage::url() ),
					esc_html__( 'Payment settings', 'wpinterface-travel' )
				);
			}
		}
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( SettingsPage::url() ),
			esc_html__( 'Settings', 'wpinterface-travel' )
		);

		$links = array_merge( array( 'settings' => $settings ), $links );

		if ( self::show_pro_prompts() ) {
			$links['go_pro'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener" style="font-weight:600">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( self::pro_url( 'plugins-screen' ) ),
				esc_html__( 'Go Pro', 'wpinterface-travel' ),
				/* translators: Accessibility text for a link opening in a new tab. */
				esc_html__( '(opens in a new tab)', 'wpinterface-travel' )
			);
		}

		return $links;
	}

	/**
	 * Whether to mention WPInterface Travel Pro. Only on the plugin's own
	 * screens and the Plugins list (never as a site-wide notice), and never
	 * once Pro is active.
	 */
	public static function show_pro_prompts(): bool {
		/** This filter is documented in src/Rest/SystemController.php */
		$pro_active = (bool) apply_filters( 'wpitravel_pro_active', false );

		/**
		 * Filters whether Free shows its few "Go Pro" links.
		 *
		 * @param bool $show Whether to show them.
		 */
		return (bool) apply_filters( 'wpitravel_show_pro_prompts', ! $pro_active && current_user_can( 'activate_plugins' ) );
	}

	/**
	 * Link to the Pro page, tagged with where it was clicked.
	 *
	 * @param string $source Where the link is, e.g. dashboard.
	 */
	public static function pro_url( string $source ): string {
		return add_query_arg(
			array(
				'utm_source'   => 'wpinterface-travel',
				'utm_medium'   => 'plugin',
				'utm_campaign' => $source,
			),
			'https://wpinterface.com/plugins/wpinterface-travel-pro/'
		);
	}
}
