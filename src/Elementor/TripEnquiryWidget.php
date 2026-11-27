<?php
/**
 * Elementor widget: enquiry form.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_enquiry] as an Elementor widget.
 */
final class TripEnquiryWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-enquiry';
	protected const ICON      = 'eicon-mail';
	protected const SHORTCODE = 'wpitravel_enquiry';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Enquiry Form', 'wpinterface-travel' );
	}
}
