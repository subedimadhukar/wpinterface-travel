<?php
/**
 * Elementor widget: booking form.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_booking] as an Elementor widget.
 */
final class BookingFormWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-booking-form';
	protected const ICON      = 'eicon-form-horizontal';
	protected const SHORTCODE = 'wpitravel_booking';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Booking Form', 'wpinterface-travel' );
	}
}
