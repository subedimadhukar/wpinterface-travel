<?php
/**
 * Settings endpoint.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * GET /settings and POST|PATCH /settings for the settings screen.
 */
final class SettingsController extends Controller {

	/**
	 * Placeholder sent instead of a stored secret.
	 */
	public const SECRET_MASK = '••••••••';

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Current settings plus the choices the screen needs.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::screen_data( $this->plugin->options()->all() ) );
	}

	/**
	 * Saves a partial settings object sent as the JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$values = $request->get_json_params();

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'wpitravel_invalid_setting', __( 'Send the settings as a JSON object.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$saved = $this->plugin->options()->update( self::drop_masked( $values ) );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( self::screen_data( $saved ) );
	}

	/**
	 * Replaces stored secrets (e.g. the PayPal secret) with a placeholder, so
	 * they never reach the browser.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function mask( array $settings ): array {
		foreach ( Gateways::secret_paths() as $path ) {
			$node = &$settings;

			foreach ( explode( '.', $path ) as $key ) {
				if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
					unset( $node );
					continue 2;
				}
				$node = &$node[ $key ];
			}

			if ( is_string( $node ) && '' !== $node ) {
				$node = self::SECRET_MASK;
			}

			unset( $node );
		}

		return $settings;
	}

	/**
	 * Removes secrets still set to the placeholder, so saving keeps the stored value.
	 *
	 * @param array<string, mixed> $values Submitted settings.
	 * @return array<string, mixed>
	 */
	private static function drop_masked( array $values ): array {
		foreach ( Gateways::secret_paths() as $path ) {
			$keys = explode( '.', $path );
			$last = array_pop( $keys );
			$node = &$values;

			foreach ( $keys as $key ) {
				if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
					unset( $node );
					continue 2;
				}
				$node = &$node[ $key ];
			}

			if ( is_array( $node ) && isset( $node[ $last ] ) && self::SECRET_MASK === $node[ $last ] ) {
				unset( $node[ $last ] );
			}

			unset( $node );
		}

		return $values;
	}

	/**
	 * Settings and select options, shared by the endpoint and the page's preload.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	public static function screen_data( array $settings ): array {
		$currencies = array();

		foreach ( Currencies::all() as $code => $currency ) {
			$currencies[] = array_merge( array( 'code' => $code ), $currency );
		}

		$positions = array();

		foreach ( Currencies::positions() as $value => $label ) {
			$positions[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$pages = array();

		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
			$pages[] = array(
				'value' => (string) $page->ID,
				'label' => get_the_title( $page ),
			);
		}

		$gateways = array();

		foreach ( Gateways::all() as $gateway ) {
			$gateways[] = $gateway->to_settings_schema();
		}

		return array(
			'settings'   => self::mask( $settings ),
			'gateways'   => $gateways,
			'secretMask' => self::SECRET_MASK,
			'currencies' => $currencies,
			'positions'  => $positions,
			'pages'      => $pages,
		);
	}
}
