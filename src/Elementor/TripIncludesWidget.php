<?php
/**
 * Elementor widget: included and not included.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_includes] as an Elementor widget.
 */
final class TripIncludesWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-includes';
	protected const ICON      = 'eicon-check-circle-o';
	protected const SHORTCODE = 'wpitravel_trip_includes';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Included and Not Included', 'wpinterface-travel' );
	}
}
