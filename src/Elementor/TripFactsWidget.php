<?php
/**
 * Elementor widget: trip facts.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_facts] as an Elementor widget.
 */
final class TripFactsWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-facts';
	protected const ICON      = 'eicon-bullet-list';
	protected const SHORTCODE = 'wpitravel_trip_facts';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Facts', 'wpinterface-travel' );
	}
}
