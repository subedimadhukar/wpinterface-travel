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

		list( $pricing, $schedule, $date_notes ) = $this->pricing_and_dates( $source_id, $meta );
		$notes                                   = array_merge( $notes, $date_notes );

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
	 * Prices and departures (fixed dates, repeating rules, excluded days).
	 *
	 * @param int      $trip_id Source trip.
	 * @param callable $meta    Meta reader.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<string>} Pricing, schedule, notes.
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

		$fixed    = array();
		$rules    = array();
		$excluded = array();
		$notes    = array();

		if ( self::table_exists( $dates ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WP Travel's table; columns vary by version, so all are read.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trip_id = %d', $dates, $trip_id ), ARRAY_A ) as $row ) {
				if ( empty( $row['recurring'] ) ) {
					$fixed[] = (string) $row['start_date'];
					continue;
				}

				list( $rule, $expanded, $note ) = self::repeating( $row );

				if ( $rule ) {
					$rules[] = $rule;
				}

				$fixed = array_merge( $fixed, $expanded );

				if ( '' !== $note ) {
					$notes[] = $note;
				}
			}

			list( $excluded, $skipped ) = self::excluded( $wpdb->prefix . 'wt_excluded_dates_times', $trip_id );

			if ( $skipped ) {
				$notes[] = __( 'excluded dates that repeat or close only some start times were not imported; check the Departures tab.', 'wpinterface-travel' );
			}
		}

		$fixed = array_merge(
			self::future_dates( array_filter( $fixed, 'is_string' ) ),
			array_values( array_filter( $fixed, 'is_array' ) )
		);

		$schedule = array();

		if ( $fixed || $rules ) {
			$schedule = array(
				'fixed'     => $fixed,
				'recurring' => $rules,
				'excluded'  => $excluded,
				'capacity'  => absint( $meta( 'wp_travel_group_size' ) ),
			);
		}

		return array( $options ? array( 'options' => $options ) : array(), $schedule, array_values( array_unique( $notes ) ) );
	}

	/**
	 * A repeating row of wt_dates: a rule when it fits, otherwise dates for
	 * the coming year.
	 *
	 * Columns: years ("every_year" or "2026,2027"), months ("every_month" or
	 * "4,5,6"), days ("MO,TH"), date_days ("1,15"), start_date, end_date,
	 * trip_time ("09:00,14:30", used when the row's time switch is on).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{0: array<string, mixed>|null, 1: list<array{date: string, time: string}>, 2: string} Rule, dates, note.
	 */
	private static function repeating( array $row ): array {
		$list  = static fn( $value, string $every ): array => in_array( trim( (string) $value ), array( '', $every ), true ) ? array() : array_values( array_filter( array_map( 'intval', explode( ',', (string) $value ) ) ) );
		$years = $list( $row['years'] ?? '', 'every_year' );
		$month = $list( $row['months'] ?? '', 'every_month' );
		$days  = self::weekday_codes( (string) ( $row['days'] ?? '' ) );
		$mdays = $list( $row['date_days'] ?? '', '' );
		$start = self::ymd( $row['start_date'] ?? '' );
		$end   = self::ymd( $row['end_date'] ?? '' );
		$times = ! empty( $row['id'] ) && get_post_meta( (int) $row['id'], 'wp_travel_trip_time_enable', true ) ? self::times( (string) ( $row['trip_time'] ?? '' ) ) : array();
		sort( $years );

		// Years in a row: the rule's start and end cover them.
		$span = $years && ( end( $years ) - $years[0] + 1 ) === count( $years );

		if ( $span ) {
			$start = max( $start, $years[0] . '-01-01' );
			$end   = '' !== $end ? min( $end, end( $years ) . '-12-31' ) : end( $years ) . '-12-31';
		}

		// Weekdays win over days of the month, as in WP Travel.
		if ( ( $days || ! $mdays ) && ( ! $years || $span ) ) {
			return array( self::rule( $start, $end, $days, $month, $times ), array(), '' );
		}

		$dates = self::expand(
			$start,
			$end,
			static fn( \DateTimeImmutable $day ): bool => ( ! $years || in_array( (int) $day->format( 'Y' ), $years, true ) )
				&& ( ! $month || in_array( (int) $day->format( 'n' ), $month, true ) )
				&& ( $days ? in_array( (int) $day->format( 'N' ), $days, true ) : in_array( (int) $day->format( 'j' ), $mdays, true ) ),
			$times
		);

		return array( null, $dates, __( 'repeating dates on certain days of the month or in separate years were added as dates for the coming year; add more in the Departures tab later.', 'wpinterface-travel' ) );
	}

	/**
	 * Whole days WP Travel excludes, from wt_excluded_dates_times. Rows that
	 * repeat or close only some times are counted, not imported.
	 *
	 * @param string $table   Table.
	 * @param int    $trip_id Trip.
	 * @return array{0: list<string>, 1: int} Days, rows skipped.
	 */
	private static function excluded( string $table, int $trip_id ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array( array(), 0 );
		}

		$days    = array();
		$skipped = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WP Travel's table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trip_id = %d', $table, $trip_id ), ARRAY_A ) as $row ) {
			if ( ! empty( $row['recurring'] ) || '' !== trim( (string) ( $row['time'] ?? '' ) ) ) {
				++$skipped;
				continue;
			}

			$days = array_merge( $days, self::days_between( (string) $row['start_date'], (string) ( $row['end_date'] ?? '' ) ) );
		}

		$today = current_time( 'Y-m-d' );

		return array( array_values( array_unique( array_filter( $days, static fn( string $day ): bool => $day >= $today ) ) ), $skipped );
	}
}
