<?php
/**
 * Listing and section shortcodes.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes for page builders and classic content:
 *
 * [wpitravel_trips number="6" columns="3" layout="grid|list" sort="price" destination="nepal"
 *   activity="" trip_type="" ids="12,15" filters="yes" show_price="yes" show_rating="yes" show_excerpt="yes"]
 * [wpitravel_trip_search fields="q,destination,duration,price,sort" target="archive|current" button="Find trips"]
 * [wpitravel_trip_{price|facts|highlights|itinerary|includes|faqs|gallery|map} trip="12"]
 *
 * The booking form, reviews and enquiry form keep their own shortcodes
 * ([wpitravel_booking], [wpitravel_reviews], [wpitravel_enquiry]).
 */
final class Shortcodes {

	/**
	 * Sections that get a [wpitravel_trip_{name}] shortcode.
	 */
	public const SECTIONS = array( 'price', 'facts', 'highlights', 'itinerary', 'includes', 'faqs', 'gallery', 'map' );

	/**
	 * Registers the shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'wpitravel_trips', array( $this, 'trips' ) );
		add_shortcode( 'wpitravel_trip_search', array( $this, 'search' ) );

		foreach ( self::SECTIONS as $section ) {
			add_shortcode(
				'wpitravel_trip_' . $section,
				fn( $atts ): string => $this->section( $section, is_array( $atts ) ? $atts : array() )
			);
		}
	}

	/**
	 * [wpitravel_trips].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function trips( $atts ): string {
		$atts = shortcode_atts(
			array(
				'number'       => '6',
				'columns'      => '3',
				'layout'       => 'grid',
				'sort'         => '',
				'destination'  => '',
				'activity'     => '',
				'trip_type'    => '',
				'ids'          => '',
				'filters'      => 'no',
				'show_price'   => 'yes',
				'show_rating'  => 'yes',
				'show_excerpt' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'wpitravel_trips'
		);

		Templates::enqueue_frontend_style();

		return Parts::trips(
			array(
				'number'       => absint( $atts['number'] ),
				'columns'      => absint( $atts['columns'] ),
				'layout'       => $atts['layout'],
				'sort'         => $atts['sort'],
				'destination'  => $atts['destination'],
				'activity'     => $atts['activity'],
				'trip_type'    => $atts['trip_type'],
				'ids'          => $atts['ids'],
				'filters'      => self::yes( $atts['filters'] ),
				'show_price'   => self::yes( $atts['show_price'] ),
				'show_rating'  => self::yes( $atts['show_rating'] ),
				'show_excerpt' => self::yes( $atts['show_excerpt'] ),
			)
		);
	}

	/**
	 * [wpitravel_trip_search].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function search( $atts ): string {
		$atts = shortcode_atts(
			array(
				'fields' => 'q,destination,activity,trip_type,duration,price,sort',
				'target' => 'archive',
				'button' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'wpitravel_trip_search'
		);

		$args = array(
			'fields' => array_filter( array_map( 'trim', explode( ',', $atts['fields'] ) ) ),
			'target' => $atts['target'],
		);

		if ( '' !== $atts['button'] ) {
			$args['button'] = $atts['button'];
		}

		Templates::enqueue_frontend_style();

		return Parts::search( $args );
	}

	/**
	 * [wpitravel_trip_{section}].
	 *
	 * @param string                $section Section name.
	 * @param array<string, string> $atts    trip (ID; defaults to the current post), open (itinerary).
	 */
	public function section( string $section, array $atts ): string {
		$trip = TripData::resolve( absint( $atts['trip'] ?? 0 ) );

		if ( ! $trip || ( 'publish' !== $trip->post_status && ! current_user_can( 'read_post', $trip->ID ) ) ) {
			return '';
		}

		Templates::enqueue_frontend_style();

		$sections = Parts::sections();

		return (string) call_user_func( $sections[ $section ], $trip->ID, array( 'open' => self::yes( $atts['open'] ?? 'no' ) ) );
	}

	/**
	 * Reads a yes/no shortcode attribute.
	 *
	 * @param mixed $value Value.
	 */
	private static function yes( $value ): bool {
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
