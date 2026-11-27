<?php
/**
 * Elementor widget: trip map.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_map] as an Elementor widget.
 */
final class TripMapWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-map';
	protected const ICON      = 'eicon-google-maps';
	protected const SHORTCODE = 'wpitravel_trip_map';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Map', 'wpinterface-travel' );
	}
}
