<?php
/**
 * Custom capabilities.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

use WPInterface\Travel\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin capabilities and their default role mapping.
 *
 * Users with `manage_options` always receive every plugin capability, so a
 * site can never lock its administrators out of bookings or settings.
 * Trip editing uses the trip post type's own primitive capabilities.
 */
final class Capabilities {

	public const MANAGE_SETTINGS  = 'wpitravel_manage_settings';
	public const MANAGE_BOOKINGS  = 'wpitravel_manage_bookings';
	public const VIEW_BOOKINGS    = 'wpitravel_view_bookings';
	public const MANAGE_ENQUIRIES = 'wpitravel_manage_enquiries';
	public const MANAGE_COUPONS   = 'wpitravel_manage_coupons';
	public const VIEW_REPORTS     = 'wpitravel_view_reports';

	/**
	 * All plugin capabilities, including the trip post type's.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_merge(
			array(
				self::MANAGE_SETTINGS,
				self::MANAGE_BOOKINGS,
				self::VIEW_BOOKINGS,
				self::MANAGE_ENQUIRIES,
				self::MANAGE_COUPONS,
				self::VIEW_REPORTS,
			),
			PostTypes::trip_capabilities()
		);
	}

	/**
	 * Capabilities granted to each role on install.
	 *
	 * @return array<string, list<string>>
	 */
	public static function default_role_map(): array {
		/**
		 * Filters which roles receive plugin capabilities on install.
		 *
		 * @param array $map Role name => list of capabilities.
		 */
		return (array) apply_filters(
			'wpitravel_default_role_capabilities',
			array(
				'administrator' => self::all(),
				'editor'        => array_merge(
					array(
						self::MANAGE_BOOKINGS,
						self::VIEW_BOOKINGS,
						self::MANAGE_ENQUIRIES,
						self::VIEW_REPORTS,
					),
					PostTypes::trip_capabilities()
				),
			)
		);
	}

	/**
	 * Adds capabilities to roles (activation / upgrade).
	 */
	public static function add_to_roles(): void {
		foreach ( self::default_role_map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( (array) $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes plugin capabilities from every role (uninstall).
	 */
	public static function remove_from_roles(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = $roles->get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Registers the administrator safety net.
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_to_site_managers' ), 10, 1 );
	}

	/**
	 * Grants every plugin capability to users who can `manage_options`.
	 *
	 * @param array<string, bool> $allcaps User capabilities.
	 * @return array<string, bool>
	 */
	public function grant_to_site_managers( $allcaps ): array {
		$allcaps = is_array( $allcaps ) ? $allcaps : array();

		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::all() as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}
}
