<?php
/**
 * Elementor widgets.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a "Travel" widget category and one widget per block, when
 * Elementor is active. The widgets print the same HTML as the blocks and
 * shortcodes, so themes style all three alike.
 */
final class Elementor {

	/**
	 * Widget classes (in this namespace), in panel order.
	 */
	public const WIDGETS = array(
		'TripsWidget',
		'TripSearchWidget',
		'TripPriceWidget',
		'TripFactsWidget',
		'TripHighlightsWidget',
		'TripItineraryWidget',
		'TripDeparturesWidget',
		'TripIncludesWidget',
		'TripFaqsWidget',
		'TripGalleryWidget',
		'TripMapWidget',
		'BookingFormWidget',
		'TripReviewsWidget',
		'TripEnquiryWidget',
	);

	/**
	 * Registers hooks. Elementor fires them only when it is active.
	 */
	public function register(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );
	}

	/**
	 * Adds the "Travel" category to the Elementor panel.
	 *
	 * @param object $elements Elementor's elements manager.
	 */
	public function category( $elements ): void {
		if ( is_object( $elements ) && method_exists( $elements, 'add_category' ) ) {
			$elements->add_category(
				'wpinterface-travel',
				array(
					'title' => __( 'Travel', 'wpinterface-travel' ),
					'icon'  => 'eicon-map-pin',
				)
			);
		}
	}

	/**
	 * Registers the widgets.
	 *
	 * @param object $widgets Elementor's widgets manager.
	 */
	public function widgets( $widgets ): void {
		if ( ! is_object( $widgets ) || ! method_exists( $widgets, 'register' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		foreach ( self::WIDGETS as $name ) {
			$class = __NAMESPACE__ . '\\' . $name;
			$widgets->register( new $class() );
		}
	}
}
