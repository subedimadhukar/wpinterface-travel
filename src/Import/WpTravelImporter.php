<?php
/**
 * Importer for WP Travel.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Trips of WP Travel (post type `itineraries`), checked against version
 * 12.2. Prices and dates come from its tables (`wt_pricings`,
 * `wt_price_category_relation`, `wt_dates`), with the older post meta
 * as a fallback. Repeating dates are not converted; the import notes say
 * which trips had them.
 */
final class WpTravelImporter extends Importer {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'wp-travel';
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return 'WP Travel';
	}

	/**
	 * Source trips.
	 *
	 * @return list<int>
	 */
	protected function source_ids(): array {
		return self::post_ids( 'itineraries' );
	}

	/**
	 * Reads one trip.
	 *
	 * @param int $source_id Post ID.
	 * @return array<string, mixed>|null
	 */
	protected function read( int $source_id ): ?array {
		$post = self::post_fields( $source_id );

		if ( ! $post ) {
			return null;
		}

		$meta  = static fn( string $key ) => get_post_meta( $source_id, $key, true );
		$notes = array();

		// Duration.
		$format   = (array) $meta( 'wp_travel_trip_duration_formating' );
		$duration = array(
			'days'   => absint( $format['days'] ?? $meta( 'wp_travel_trip_duration' ) ),
			'nights' => absint( $format['nights'] ?? $meta( 'wp_travel_trip_duration_night' ) ),
			'hours'  => absint( $format['hours'] ?? 0 ),
		);

		// Itinerary.
		$itinerary = array();

		foreach ( (array) $meta( 'wp_travel_trip_itinerary_data' ) as $day ) {
			if ( is_array( $day ) ) {
				$itinerary[] = array(
					'title'       => (string) ( '' !== (string) ( $day['title'] ?? '' ) ? $day['title'] : ( $day['label'] ?? '' ) ),
					'description' => (string) ( $day['desc'] ?? '' ),
				);
			}
		}

		// FAQs: current format, then the one before 5.2.3 (two parallel lists).
		$faqs = array();

		foreach ( (array) $meta( 'wptravel_trip_faqs' ) as $faq ) {
			if ( is_array( $faq ) ) {
				$faqs[] = array(
					'question' => (string) ( $faq['question'] ?? '' ),
					'answer'   => (string) ( $faq['answer'] ?? '' ),
				);
			}
		}

		if ( ! $faqs ) {
			$answers = (array) $meta( 'wp_travel_faq_answer' );

			foreach ( (array) $meta( 'wp_travel_faq_question' ) as $index => $question ) {
				$faqs[] = array(
					'question' => (string) $question,
					'answer'   => (string) ( $answers[ $index ] ?? '' ),
				);
			}
		}

		// Map.
		$map = array();

		if ( '' !== (string) $meta( 'wp_travel_lat' ) && '' !== (string) $meta( 'wp_travel_lng' ) ) {
			$map = array(
				'lat'     => (float) $meta( 'wp_travel_lat' ),
				'lng'     => (float) $meta( 'wp_travel_lng' ),
				'zoom'    => absint( $meta( 'wp_travel_zoomlevel' ) ) ? absint( $meta( 'wp_travel_zoomlevel' ) ) : 10,
				'address' => (string) $meta( 'wp_travel_location' ),
			);
		}

		list( $pricing, $schedule, $recurring ) = $this->pricing_and_dates( $source_id, $meta );

		if ( $recurring ) {
			$notes[] = __( 'repeating dates were not imported; add them in the Departures tab.', 'wpinterface-travel' );
		}

		if ( ! $pricing ) {
			$notes[] = __( 'no prices found; add them in the Pricing tab.', 'wpinterface-travel' );
		}

		return array(
			'post'      => $post,
			'meta'      => array(
				TripMeta::DURATION   => $duration,
				TripMeta::ITINERARY  => $itinerary,
				TripMeta::INCLUDES   => self::to_list( $meta( 'wp_travel_trip_include' ) ),
				TripMeta::EXCLUDES   => self::to_list( $meta( 'wp_travel_trip_exclude' ) ),
				TripMeta::FAQS       => $faqs,
				TripMeta::GALLERY    => array_values( array_filter( array_map( 'absint', (array) $meta( 'wp_travel_itinerary_gallery_ids' ) ) ) ),
				TripMeta::MAP        => $map,
				TripMeta::GROUP_SIZE => array(
					'min' => absint( $meta( 'wp_travel_group_min_size' ) ),
					'max' => absint( $meta( 'wp_travel_group_size' ) ),
				),
				TripMeta::PRICING    => $pricing,
				TripMeta::SCHEDULE   => $schedule,
			),
			'terms'     => array(
				PostTypes::DESTINATION => self::term_names( $source_id, 'travel_locations' ),
				PostTypes::ACTIVITY    => self::term_names( $source_id, 'activity' ),
				PostTypes::TRIP_TYPE   => self::term_names( $source_id, 'itinerary_types' ),
			),
			'thumbnail' => (int) $meta( '_thumbnail_id' ),
			'notes'     => $notes,
		);
	}

	/**
	 * Prices and fixed dates.
	 *
	 * @param int      $trip_id Source trip.
	 * @param callable $meta    Meta reader.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool} Pricing, schedule, whether it had repeating dates.
	 */
	private function pricing_and_dates( int $trip_id, callable $meta ): array {
		global $wpdb;

		$options   = array();
		$pricings  = $wpdb->prefix . 'wt_pricings';
		$relations = $wpdb->prefix . 'wt_price_category_relation';
		$dates     = $wpdb->prefix . 'wt_dates';

		if ( self::table_exists( $pricings ) && self::table_exists( $relations ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WP Travel's tables.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trip_id = %d ORDER BY sort_order, id', $pricings, $trip_id ), ARRAY_A );

			foreach ( $rows as $row ) {
				$categories = array();

				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE pricing_id = %d ORDER BY id', $relations, (int) $row['id'] ), ARRAY_A ) as $category ) {
					$categories[] = array(
						self::term_name( (int) $category['pricing_category_id'] ),
						(string) $category['regular_price'],
						! empty( $category['is_sale'] ) ? (string) $category['sale_price'] : '',
					);
				}

				$option = self::price_option( (string) $row['title'], $categories, absint( $row['min_pax'] ), absint( $row['max_pax'] ) );

				if ( $option ) {
					$options[] = $option;
				}
			}
			// phpcs:enable
		}

		// Older trips: only the minimum price is known.
		if ( ! $options ) {
			$option  = self::price_option( '', array( array( '', (string) $meta( 'wp_travel_trip_price' ), '' ) ) );
			$options = $option ? array( $option ) : array();
		}

		$fixed     = array();
		$recurring = false;

		if ( self::table_exists( $dates ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WP Travel's table.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT start_date, recurring FROM %i WHERE trip_id = %d', $dates, $trip_id ), ARRAY_A ) as $row ) {
				if ( ! empty( $row['recurring'] ) ) {
					$recurring = true;
				} else {
					$fixed[] = (string) $row['start_date'];
				}
			}
		}

		$schedule = array();
		$fixed    = self::future_dates( $fixed );

		if ( $fixed ) {
			$schedule = array(
				'fixed'    => $fixed,
				'capacity' => absint( $meta( 'wp_travel_group_size' ) ),
			);
		}

		return array( $options ? array( 'options' => $options ) : array(), $schedule, $recurring );
	}
}
