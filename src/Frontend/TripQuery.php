<?php
/**
 * Trip listings: filters and sorting.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Query;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripIndex;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Turns filters (from the search form's URL or a block's settings) into
 * WP_Query arguments, and applies them to trip archives.
 *
 * URL parameters: trip_q, destination, activity, trip_type (term slugs),
 * difficulty, min_price, max_price, min_days, max_days and sort
 * (date, price, price-desc, duration, rating, title).
 */
final class TripQuery {

	public const SORTS = array( 'date', 'price', 'price-desc', 'duration', 'rating', 'title' );

	/**
	 * Applies URL filters to the main query of trip archives.
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_archive' ) );
	}

	/**
	 * Filters and sorts the trip archive and trip taxonomy archives.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_archive( $query ): void {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_post_type_archive( PostTypes::TRIP ) && ! $query->is_tax( PostTypes::taxonomies() ) ) {
			return;
		}

		$filters = self::filters( self::from_request() );

		foreach ( self::args( $filters ) as $key => $value ) {
			if ( 'tax_query' === $key ) {
				$value = array_merge( (array) $query->get( 'tax_query' ), $value );
			}

			$query->set( $key, $value );
		}

		$query->set( 'post_type', PostTypes::TRIP );

		/** This filter is documented in src/Frontend/TripQuery.php */
		$query->set( 'posts_per_page', (int) apply_filters( 'wpitravel_trips_per_page', 12 ) );
	}

	/**
	 * The search filters in the address bar, each read and sanitized on its own.
	 *
	 * @return array<string, string>
	 */
	public static function from_request(): array {
		$values = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search filters.
		foreach ( array( 'trip_q', 'destination', 'activity', 'trip_type', 'difficulty', 'min_price', 'max_price', 'min_days', 'max_days', 'sort' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
				continue;
			}

			switch ( $key ) {
				case 'destination':
				case 'activity':
				case 'trip_type':
					$values[ $key ] = sanitize_title( wp_unslash( $_GET[ $key ] ) );
					break;
				case 'difficulty':
				case 'sort':
					$values[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
					break;
				default:
					$values[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable

		return $values;
	}

	/**
	 * Cleans raw filters.
	 *
	 * @param array<string, mixed> $raw Raw values (URL or block attributes).
	 * @return array<string, mixed>
	 */
	public static function filters( array $raw ): array {
		$number = static fn( $value ): ?float => is_numeric( $value ) && (float) $value >= 0 ? (float) $value : null;
		$slug   = static fn( $value ): string => is_string( $value ) ? sanitize_title( $value ) : '';
		$sort   = is_string( $raw['sort'] ?? null ) && in_array( $raw['sort'], self::SORTS, true ) ? $raw['sort'] : '';

		return array(
			'q'           => is_string( $raw['trip_q'] ?? null ) ? substr( sanitize_text_field( $raw['trip_q'] ), 0, 100 ) : '',
			'destination' => $slug( $raw['destination'] ?? '' ),
			'activity'    => $slug( $raw['activity'] ?? '' ),
			'trip_type'   => $slug( $raw['trip_type'] ?? '' ),
			'difficulty'  => is_string( $raw['difficulty'] ?? null ) ? TripMeta::sanitize_difficulty( $raw['difficulty'] ) : '',
			'min_price'   => $number( $raw['min_price'] ?? null ),
			'max_price'   => $number( $raw['max_price'] ?? null ),
			'min_days'    => $number( $raw['min_days'] ?? null ),
			'max_days'    => $number( $raw['max_days'] ?? null ),
			'sort'        => $sort,
		);
	}

	/**
	 * WP_Query arguments for cleaned filters.
	 *
	 * @param array<string, mixed> $filters Filters from filters().
	 * @return array<string, mixed>
	 */
	public static function args( array $filters ): array {
		$args       = array();
		$tax_query  = array();
		$meta_query = array();

		if ( '' !== $filters['q'] ) {
			$args['s'] = $filters['q'];
		}

		$taxonomies = array(
			'destination' => PostTypes::DESTINATION,
			'activity'    => PostTypes::ACTIVITY,
			'trip_type'   => PostTypes::TRIP_TYPE,
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( '' !== $filters[ $key ] ) {
				$tax_query[] = array(
					'taxonomy'         => $taxonomy,
					'field'            => 'slug',
					'terms'            => $filters[ $key ],
					'include_children' => true,
				);
			}
		}

		if ( '' !== $filters['difficulty'] ) {
			$meta_query[] = array(
				'key'   => TripMeta::DIFFICULTY,
				'value' => $filters['difficulty'],
			);
		}

		foreach ( array(
			'price' => TripIndex::PRICE,
			'days'  => TripIndex::DAYS,
		) as $name => $key ) {
			$type = 'price' === $name ? 'DECIMAL(19,4)' : 'UNSIGNED';

			if ( null !== $filters[ 'min_' . $name ] ) {
				$meta_query[] = array(
					'key'     => $key,
					'value'   => $filters[ 'min_' . $name ],
					'compare' => '>=',
					'type'    => $type,
				);
			}

			if ( null !== $filters[ 'max_' . $name ] && $filters[ 'max_' . $name ] > 0 ) {
				$meta_query[] = array(
					'key'     => $key,
					'value'   => $filters[ 'max_' . $name ],
					'compare' => '<=',
					'type'    => $type,
				);
			}
		}

		$sort = self::sort( $filters['sort'] );

		if ( $sort['meta'] ) {
			$args['wpitravel_sort'] = $sort['meta'];

			if ( ! has_filter( 'posts_clauses', array( self::class, 'sort_clauses' ) ) ) {
				add_filter( 'posts_clauses', array( self::class, 'sort_clauses' ), 10, 2 );
			}
		}

		if ( $tax_query ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query -- Listing filters.
		}

		if ( $meta_query ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- Listing filters on indexed numeric meta.
		}

		$args['orderby'] = $sort['orderby'];

		return $args;
	}

	/**
	 * A listing query for blocks and shortcodes.
	 *
	 * @param array<string, mixed> $filters  Filters from filters().
	 * @param int                  $number   Trips per page.
	 * @param int                  $page     Page.
	 * @param int[]                $ids      Only these trips (in this order) when not empty.
	 */
	public static function query( array $filters, int $number = 6, int $page = 1, array $ids = array() ): WP_Query {
		$args = array_merge(
			self::args( $filters ),
			array(
				'post_type'           => PostTypes::TRIP,
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, min( 48, $number ) ),
				'paged'               => max( 1, $page ),
				'ignore_sticky_posts' => true,
			)
		);

		if ( $ids ) {
			$args['post__in'] = array_map( 'absint', $ids );
			$args['orderby']  = 'post__in';
		}

		/**
		 * Filters a trip listing query.
		 *
		 * @param array $args    WP_Query arguments.
		 * @param array $filters Filters.
		 */
		return new WP_Query( (array) apply_filters( 'wpitravel_trip_query_args', $args, $filters ) );
	}

	/**
	 * Joins the sorted value and orders by it, with trips that lack it last
	 * (MySQL would put missing values first in ascending order).
	 *
	 * @param array<string, string> $clauses Query clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public static function sort_clauses( $clauses, $query ): array {
		global $wpdb;

		$clauses = is_array( $clauses ) ? $clauses : array();
		$sort    = $query instanceof WP_Query ? $query->get( 'wpitravel_sort' ) : null;

		if ( ! is_array( $sort ) || ! in_array( $sort['key'] ?? '', array( TripIndex::PRICE, TripIndex::DAYS, TripIndex::RATING ), true ) ) {
			return $clauses;
		}

		$type  = in_array( $sort['type'] ?? '', array( 'DECIMAL(19,4)', 'DECIMAL(3,1)', 'UNSIGNED' ), true ) ? $sort['type'] : 'UNSIGNED';
		$order = 'DESC' === ( $sort['order'] ?? '' ) ? 'DESC' : 'ASC';

		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS wpitravel_sort ON ( {$wpdb->posts}.ID = wpitravel_sort.post_id AND wpitravel_sort.meta_key = %s )", $sort['key'] );
		$clauses['orderby'] = "wpitravel_sort.meta_value IS NULL ASC, CAST( wpitravel_sort.meta_value AS {$type} ) {$order}" . ( '' !== (string) ( $clauses['orderby'] ?? '' ) ? ', ' . $clauses['orderby'] : '' );

		return $clauses;
	}

	/**
	 * Ordering for a sort key. Numeric sorts are applied by sort_clauses().
	 *
	 * @param string $sort Sort key.
	 * @return array{orderby: array<string, string>, meta: array{key: string, type: string, order: string}|null}
	 */
	private static function sort( string $sort ): array {
		$numeric = array(
			'price'      => array( TripIndex::PRICE, 'DECIMAL(19,4)', 'ASC' ),
			'price-desc' => array( TripIndex::PRICE, 'DECIMAL(19,4)', 'DESC' ),
			'duration'   => array( TripIndex::DAYS, 'UNSIGNED', 'ASC' ),
			'rating'     => array( TripIndex::RATING, 'DECIMAL(3,1)', 'DESC' ),
		);

		if ( isset( $numeric[ $sort ] ) ) {
			return array(
				'orderby' => array( 'date' => 'DESC' ),
				'meta'    => array_combine( array( 'key', 'type', 'order' ), $numeric[ $sort ] ),
			);
		}

		$orderbys = array(
			'title' => array( 'title' => 'ASC' ),
			'date'  => array( 'date' => 'DESC' ),
		);

		return array(
			'orderby' => $orderbys[ $sort ] ?? array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'meta'    => null,
		);
	}
}
