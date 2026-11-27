<?php
/**
 * Elementor widget: trip gallery.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_gallery] as an Elementor widget.
 */
final class TripGalleryWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-gallery';
	protected const ICON      = 'eicon-gallery-grid';
	protected const SHORTCODE = 'wpitravel_trip_gallery';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Gallery', 'wpinterface-travel' );
	}
}
