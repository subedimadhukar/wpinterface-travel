<?php
/**
 * Elementor widget: trip highlights.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_highlights] as an Elementor widget.
 */
final class TripHighlightsWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-highlights';
	protected const ICON      = 'eicon-star-o';
	protected const SHORTCODE = 'wpitravel_trip_highlights';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Highlights', 'wpinterface-travel' );
	}
}
