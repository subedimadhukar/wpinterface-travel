<?php
/**
 * Trip page sections.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Term;
use WPInterface\Travel\Admin\Assets;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Section renderers share one signature.

/**
 * Renders each section of a trip page and the listing/search widgets.
 *
 * Blocks, shortcodes and page templates all call these, so a section looks
 * the same wherever it is placed. Each returns '' when there is nothing to
 * show (e.g. a trip without FAQs).
 */
final class Parts {

	/**
	 * Section names and their renderers, for blocks and shortcodes.
	 *
	 * @return array<string, callable(int, array<string, mixed>): string>
	 */
	public static function sections(): array {
		return array(
			'price'      => array( self::class, 'price' ),
			'facts'      => array( self::class, 'facts' ),
			'highlights' => array( self::class, 'highlights' ),
			'itinerary'  => array( self::class, 'itinerary' ),
			'includes'   => array( self::class, 'includes' ),
			'faqs'       => array( self::class, 'faqs' ),
			'gallery'    => array( self::class, 'gallery' ),
			'map'        => array( self::class, 'map' ),
			'booking'    => array( self::class, 'booking' ),
			'reviews'    => array( self::class, 'reviews' ),
			'enquiry'    => array( self::class, 'enquiry' ),
		);
	}

	/**
	 * "From $1,100" price.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function price( int $trip_id, array $atts = array() ): string {
		$price = TripData::price( $trip_id );

		return $price ? Templates::part( 'parts/price', array( 'price' => $price ) ) : '';
	}

	/**
	 * Key facts.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function facts( int $trip_id, array $atts = array() ): string {
		$facts = TripData::facts( $trip_id );

		return $facts ? Templates::part( 'parts/facts', array( 'facts' => $facts ) ) : '';
	}

	/**
	 * Highlights.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function highlights( int $trip_id, array $atts = array() ): string {
		$items = TripMeta::sanitize_string_list( get_post_meta( $trip_id, TripMeta::HIGHLIGHTS, true ) );

		return $items ? Templates::part( 'parts/highlights', array( 'items' => $items ) ) : '';
	}

	/**
	 * Day-by-day itinerary.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    open: expand all days.
	 */
	public static function itinerary( int $trip_id, array $atts = array() ): string {
		$days = TripData::itinerary( $trip_id );

		return $days ? Templates::part(
			'parts/itinerary',
			array(
				'days' => $days,
				'open' => ! empty( $atts['open'] ),
			)
		) : '';
	}

	/**
	 * Included / excluded.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function includes( int $trip_id, array $atts = array() ): string {
		$included = TripMeta::sanitize_string_list( get_post_meta( $trip_id, TripMeta::INCLUDES, true ) );
		$excluded = TripMeta::sanitize_string_list( get_post_meta( $trip_id, TripMeta::EXCLUDES, true ) );

		return ( $included || $excluded ) ? Templates::part( 'parts/includes', compact( 'included', 'excluded' ) ) : '';
	}

	/**
	 * FAQs.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function faqs( int $trip_id, array $atts = array() ): string {
		$faqs = TripMeta::sanitize_faqs( get_post_meta( $trip_id, TripMeta::FAQS, true ) );

		return $faqs ? Templates::part( 'parts/faqs', array( 'faqs' => $faqs ) ) : '';
	}

	/**
	 * Photo gallery (falls back to the cover image).
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function gallery( int $trip_id, array $atts = array() ): string {
		$images = TripData::gallery( $trip_id );

		if ( ! $images && has_post_thumbnail( $trip_id ) ) {
			$images = array( (int) get_post_thumbnail_id( $trip_id ) );
		}

		return $images ? Templates::part(
			'parts/gallery',
			array(
				'images' => $images,
				'title'  => get_the_title( $trip_id ),
			)
		) : '';
	}

	/**
	 * Map (loads OpenStreetMap only after the visitor asks, for privacy).
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function map( int $trip_id, array $atts = array() ): string {
		$map = TripData::map( $trip_id );

		if ( ! $map ) {
			return '';
		}

		$delta = 180 / ( 2 ** $map['zoom'] );

		Assets::enqueue( 'frontend', '', array(), array() );

		return Templates::part(
			'parts/map',
			array(
				'map'   => $map,
				'embed' => add_query_arg(
					array(
						'bbox'   => implode( ',', array( $map['lng'] - $delta, $map['lat'] - $delta / 2, $map['lng'] + $delta, $map['lat'] + $delta / 2 ) ),
						'layer'  => 'mapnik',
						'marker' => $map['lat'] . ',' . $map['lng'],
					),
					'https://www.openstreetmap.org/export/embed.html'
				),
				'link'  => sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $map['lat'], $map['lng'], $map['zoom'] ),
			)
		);
	}

	/**
	 * Booking form.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function booking( int $trip_id, array $atts = array() ): string {
		return ( new BookingWidget() )->render( $trip_id );
	}

	/**
	 * Reviews.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function reviews( int $trip_id, array $atts = array() ): string {
		return ( new ReviewsDisplay() )->render( $trip_id );
	}

	/**
	 * Enquiry form.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $atts    Unused.
	 */
	public static function enquiry( int $trip_id, array $atts = array() ): string {
		return ( new BookingWidget() )->enquiry_shortcode( array( 'trip' => (string) $trip_id ) );
	}

	/**
	 * Trip grid or list.
	 *
	 * @param array<string, mixed> $atts number, columns, layout, sort, destination, activity, trip_type,
	 *                                   ids, inherit (use the page's query), filters (read the URL),
	 *                                   show_price, show_rating, show_excerpt.
	 */
	public static function trips( array $atts ): string {
		$atts = wp_parse_args(
			$atts,
			array(
				'number'       => 6,
				'columns'      => 3,
				'layout'       => 'grid',
				'sort'         => '',
				'destination'  => '',
				'activity'     => '',
				'trip_type'    => '',
				'ids'          => array(),
				'inherit'      => false,
				'filters'      => false,
				'show_price'   => true,
				'show_rating'  => true,
				'show_excerpt' => true,
			)
		);

		global $wp_query;

		$inherit = $atts['inherit'] && Templates::is_trip_page() && ! is_singular();

		if ( $inherit ) {
			$query = $wp_query;
			$page  = max( 1, (int) get_query_var( 'paged' ) );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters and pagination.
			$request = $atts['filters'] ? wp_unslash( $_GET ) : array();
			$filters = TripQuery::filters(
				array_merge(
					array(
						'destination' => $atts['destination'],
						'activity'    => $atts['activity'],
						'trip_type'   => $atts['trip_type'],
						'sort'        => $atts['sort'],
					),
					array_filter( (array) $request, static fn( $value ) => '' !== $value )
				)
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
			$page  = isset( $_GET['trips_page'] ) ? max( 1, absint( $_GET['trips_page'] ) ) : 1;
			$ids   = is_array( $atts['ids'] ) ? $atts['ids'] : array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );
			$query = TripQuery::query( $filters, (int) $atts['number'], $page, $ids );
		}

		$cards = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$cards[] = TripData::card( $post );
			}
		}

		return Templates::part(
			'parts/trips',
			array(
				'cards'      => $cards,
				'columns'    => max( 1, min( 4, (int) $atts['columns'] ) ),
				'layout'     => 'list' === $atts['layout'] ? 'list' : 'grid',
				'show'       => array(
					'price'   => (bool) $atts['show_price'],
					'rating'  => (bool) $atts['show_rating'],
					'excerpt' => (bool) $atts['show_excerpt'],
				),
				'pagination' => self::pagination( (int) $query->max_num_pages, $page, $inherit ),
			)
		);
	}

	/**
	 * Search and filter form.
	 *
	 * @param array<string, mixed> $atts fields (list), target (archive|current), button.
	 */
	public static function search( array $atts ): string {
		$atts   = wp_parse_args(
			$atts,
			array(
				'fields' => array( 'q', 'destination', 'activity', 'trip_type', 'duration', 'price', 'sort' ),
				'target' => 'archive',
				'button' => __( 'Find trips', 'wpinterface-travel' ),
			)
		);
		$fields = is_array( $atts['fields'] ) ? $atts['fields'] : array_map( 'trim', explode( ',', (string) $atts['fields'] ) );
		$terms  = array();

		foreach ( array(
			'destination' => PostTypes::DESTINATION,
			'activity'    => PostTypes::ACTIVITY,
			'trip_type'   => PostTypes::TRIP_TYPE,
		) as $field => $taxonomy ) {
			if ( in_array( $field, $fields, true ) ) {
				$found           = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
					)
				);
				$terms[ $field ] = is_array( $found ) ? array_map( static fn( WP_Term $term ): array => array( $term->slug, $term->name, (int) $term->parent ), $found ) : array();
			}
		}

		$archive = get_post_type_archive_link( PostTypes::TRIP );

		return Templates::part(
			'parts/search-form',
			array(
				'fields'       => $fields,
				'terms'        => $terms,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: fills in the current filters.
				'values'       => TripQuery::filters( wp_unslash( $_GET ) ),
				'action'       => 'current' === $atts['target'] || ! $archive ? '' : $archive,
				'button'       => (string) $atts['button'],
				'sorts'        => array(
					''           => __( 'Recommended', 'wpinterface-travel' ),
					'price'      => __( 'Price: low to high', 'wpinterface-travel' ),
					'price-desc' => __( 'Price: high to low', 'wpinterface-travel' ),
					'duration'   => __( 'Shortest first', 'wpinterface-travel' ),
					'rating'     => __( 'Top rated', 'wpinterface-travel' ),
					'title'      => __( 'Name', 'wpinterface-travel' ),
				),
				'difficulties' => TripMeta::difficulties(),
			)
		);
	}

	/**
	 * Page links.
	 *
	 * @param int  $pages   Number of pages.
	 * @param int  $current Current page.
	 * @param bool $main    Main query (uses /page/N/) or a block (?trips_page=N).
	 */
	private static function pagination( int $pages, int $current, bool $main ): string {
		if ( $pages < 2 ) {
			return '';
		}

		$links = paginate_links(
			$main
				? array(
					'total'   => $pages,
					'current' => $current,
				)
				: array(
					'base'    => add_query_arg( 'trips_page', '%#%' ),
					'format'  => '',
					'total'   => $pages,
					'current' => $current,
				)
		);

		return is_string( $links ) ? $links : '';
	}
}
