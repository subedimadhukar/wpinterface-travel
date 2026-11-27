<?php
/**
 * Importer for WP Travel Engine.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Trips of WP Travel Engine (post type `trip`), checked against version
 * 6.9. Most fields are in the serialized `wp_travel_engine_setting` meta;
 * prices and dates are on its `trip-packages` posts (5.0+), with the
 * older settings keys as a fallback.
 */
final class WpTravelEngineImporter extends Importer {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'wp-travel-engine';
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return 'WP Travel Engine';
	}

	/**
	 * Source trips.
	 *
	 * @return list<int>
	 */
	protected function source_ids(): array {
		return self::post_ids( 'trip' );
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

		$setting = get_post_meta( $source_id, 'wp_travel_engine_setting', true );
		$setting = is_array( $setting ) ? $setting : array();
		$get     = static fn( string $key, $fallback = '' ) => $setting[ $key ] ?? $fallback;
		$notes   = array();

		// Duration.
		$length   = absint( $get( 'trip_duration' ) );
		$duration = 'hours' === $get( 'trip_duration_unit' )
			? array( 'hours' => $length )
			: array(
				'days'   => $length,
				'nights' => absint( $get( 'trip_duration_nights' ) ),
			);

		// Itinerary: lists indexed from 1.
		$itinerary = array();
		$days      = (array) $get( 'itinerary', array() );

		foreach ( (array) ( $days['itinerary_title'] ?? array() ) as $index => $title ) {
			$itinerary[] = array(
				'title'       => (string) $title,
				'description' => (string) ( $days['itinerary_content'][ $index ] ?? '' ),
			);
		}

		// FAQs.
		$faqs = array();
		$faq  = (array) $get( 'faq', array() );

		foreach ( (array) ( $faq['faq_title'] ?? array() ) as $index => $question ) {
			$faqs[] = array(
				'question' => (string) $question,
				'answer'   => (string) ( $faq['faq_content'][ $index ] ?? '' ),
			);
		}

		$highlights = array();

		foreach ( (array) $get( 'trip_highlights', array() ) as $row ) {
			$highlights[] = is_array( $row ) ? (string) ( $row['highlight_text'] ?? '' ) : (string) $row;
		}

		$cost    = (array) $get( 'cost', array() );
		$gallery = get_post_meta( $source_id, 'wpte_gallery_id', true );
		$gallery = is_array( $gallery ) ? array_values( array_filter( array_map( 'absint', array_filter( $gallery, 'is_int', ARRAY_FILTER_USE_KEY ) ) ) ) : array();
		$max     = absint( get_post_meta( $source_id, 'total_travellers_per_day', true ) );
		$max     = $max ? $max : absint( get_post_meta( $source_id, 'max_travellers_per_day', true ) );

		$difficulty = '';
		$level      = absint( $get( 'difficulty_level' ) );

		if ( $level ) {
			$difficulty = self::difficulty( self::term_name( $level ) );
		}

		foreach ( self::term_names( $source_id, 'difficulty' ) as $name ) {
			$difficulty = '' !== $difficulty ? $difficulty : self::difficulty( $name );
		}

		list( $pricing, $dates, $rules, $date_notes ) = $this->packages( $source_id, $get );
		$notes                                        = array_merge( $notes, $date_notes );

		$schedule = array();

		if ( $dates || $rules ) {
			$cutoff = absint( $get( 'trip_cut_off_time' ) );

			$schedule = array(
				'fixed'        => $dates,
				'recurring'    => $rules,
				'capacity'     => $max,
				'cutoff_hours' => ! empty( $get( 'trip_cutoff_enable' ) ) ? ( 'hours' === $get( 'trip_cut_off_unit' ) ? $cutoff : $cutoff * 24 ) : 0,
			);
		}

		if ( ! $pricing ) {
			$notes[] = __( 'no prices found; add them in the Pricing tab.', 'wpinterface-travel' );
		}

		if ( ! $dates && ! $rules ) {
			$notes[] = __( 'no upcoming dates; add departures in the Departures tab.', 'wpinterface-travel' );
		}

		return array(
			'post'      => $post,
			'meta'      => array(
				TripMeta::CODE       => (string) $get( 'trip_code' ),
				TripMeta::DURATION   => $duration,
				TripMeta::DIFFICULTY => $difficulty,
				TripMeta::ITINERARY  => $itinerary,
				TripMeta::HIGHLIGHTS => array_values( array_filter( $highlights ) ),
				TripMeta::INCLUDES   => self::to_list( $cost['cost_includes'] ?? '' ),
				TripMeta::EXCLUDES   => self::to_list( $cost['cost_excludes'] ?? '' ),
				TripMeta::FAQS       => $faqs,
				TripMeta::GALLERY    => $gallery,
				TripMeta::GROUP_SIZE => array(
					'min' => absint( $get( 'trip_minimum_pax' ) ),
					'max' => $max,
				),
				TripMeta::MIN_AGE    => absint( get_post_meta( $source_id, 'wp_travel_engine_trip_min_age', true ) ),
				TripMeta::PRICING    => $pricing,
				TripMeta::SCHEDULE   => $schedule,
			),
			'terms'     => array(
				PostTypes::DESTINATION => self::term_names( $source_id, 'destination' ),
				PostTypes::ACTIVITY    => self::term_names( $source_id, 'activities' ),
				PostTypes::TRIP_TYPE   => self::term_names( $source_id, 'trip_types' ),
			),
			'thumbnail' => (int) get_post_meta( $source_id, '_thumbnail_id', true ),
			'notes'     => $notes,
		);
	}

	/**
	 * Prices and departures from the trip's packages, or from the settings
	 * of trips made before packages existed.
	 *
	 * @param int      $trip_id Source trip.
	 * @param callable $get     Settings reader.
	 * @return array{0: array<string, mixed>, 1: list<array<string, string>>, 2: list<array<string, mixed>>, 3: list<string>} Pricing, fixed dates, repeating rules, notes.
	 */
	private function packages( int $trip_id, callable $get ): array {
		$options = array();
		$dates   = array();
		$rules   = array();
		$extra   = array();
		$notes   = array();

		foreach ( array_filter( array_map( 'absint', (array) get_post_meta( $trip_id, 'packages_ids', true ) ) ) as $package_id ) {
			$package = get_post( $package_id );

			if ( ! $package ) {
				continue;
			}

			$rows       = (array) get_post_meta( $package_id, 'package-categories', true );
			$categories = array();

			foreach ( (array) ( $rows['c_ids'] ?? array() ) as $term_id ) {
				$label = (string) ( $rows['labels'][ $term_id ] ?? '' );

				$categories[] = array(
					'' !== $label ? $label : self::term_name( (int) $term_id ),
					(string) ( $rows['prices'][ $term_id ] ?? '' ),
					! empty( $rows['enabled_sale'][ $term_id ] ) ? (string) ( $rows['sale_prices'][ $term_id ] ?? '' ) : '',
				);
			}

			$option = self::price_option( $package->post_title, $categories );

			if ( $option ) {
				$options[] = $option;
			}

			foreach ( (array) get_post_meta( $package_id, 'package-dates', true ) as $date ) {
				if ( ! is_array( $date ) || empty( $date['dtstart'] ) ) {
					continue;
				}

				if ( empty( $date['is_recurring'] ) ) {
					$dates[] = (string) $date['dtstart'];
					continue;
				}

				list( $rule, $expanded, $note ) = self::repeating( $date );

				if ( $rule ) {
					$rules[] = $rule;
				}

				$extra = array_merge( $extra, $expanded );

				if ( '' !== $note ) {
					$notes[] = $note;
				}
			}
		}

		// Trips from before WP Travel Engine 5.0.
		if ( ! $options && ( '' !== (string) $get( 'trip_prev_price' ) || '' !== (string) $get( 'trip_price' ) ) ) {
			$regular = (string) $get( 'trip_prev_price' );
			$sale    = ! empty( $get( 'sale' ) ) ? (string) $get( 'trip_price' ) : '';
			$option  = self::price_option( '', array( array( '', '' !== $regular ? $regular : (string) $get( 'trip_price' ), $sale ) ) );
			$options = $option ? array( $option ) : array();
		}

		if ( ! $dates && ! $rules && ! $extra ) {
			$old = get_post_meta( $trip_id, 'WTE_Fixed_Starting_Dates_setting', true );

			if ( is_array( $old ) ) {
				$dates = array_map( 'strval', (array) ( $old['departure_dates']['sdate'] ?? array() ) );

				foreach ( (array) ( $old['departure_dates'] ?? array() ) as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['recurring']['enable'] ) && 'false' !== $entry['recurring']['enable'] ) {
						$notes[] = __( 'repeating dates saved by WP Travel Engine before version 5 were not imported; add them in the Departures tab.', 'wpinterface-travel' );
						break;
					}
				}
			}
		}

		return array( $options ? array( 'options' => $options ) : array(), array_merge( self::future_dates( $dates ), $extra ), $rules, array_values( array_unique( $notes ) ) );
	}

	/**
	 * A repeating package date: a rule when it fits (every day or weekly
	 * until a date), otherwise dates for the coming year.
	 *
	 * Entry: dtstart (Y-m-d), times ([{from: "07:00"}]), rrule: r_frequency
	 * (DAILY, WEEKLY, MONTHLY, YEARLY), r_weekdays ([1 => "MO"] or ["MO"]),
	 * r_months ([4 => 4]), r_until (Y-m-d) or r_count (default 10).
	 *
	 * @param array<string, mixed> $date Package date.
	 * @return array{0: array<string, mixed>|null, 1: list<array{date: string, time: string}>, 2: string} Rule, dates, note.
	 */
	private static function repeating( array $date ): array {
		$rrule    = (array) ( $date['rrule'] ?? array() );
		$start    = self::ymd( $date['dtstart'] );
		$until    = self::ymd( $rrule['r_until'] ?? '' );
		$count    = is_numeric( $rrule['r_count'] ?? null ) ? max( 1, (int) $rrule['r_count'] ) : 10;
		$times    = array_map( static fn( $slot ): string => is_array( $slot ) ? (string) ( $slot['from'] ?? '' ) : (string) $slot, (array) ( $date['times'] ?? array() ) );
		$freq     = strtoupper( (string) ( $rrule['r_frequency'] ?? 'DAILY' ) );
		$weekdays = self::weekday_codes( (array) ( $rrule['r_weekdays'] ?? array() ) );
		$months   = array_values( array_filter( array_map( 'intval', (array) ( $rrule['r_months'] ?? array() ) ) ) );
		$first    = '' !== $start ? new \DateTimeImmutable( $start, wp_timezone() ) : null;

		if ( ! $first ) {
			return array( null, array(), '' );
		}

		if ( 'WEEKLY' === $freq && ! $weekdays ) {
			$weekdays = array( (int) $first->format( 'N' ) );
		}

		if ( '' !== $until && in_array( $freq, array( 'DAILY', 'WEEKLY' ), true ) ) {
			return array( self::rule( $start, $until, 'WEEKLY' === $freq ? $weekdays : array(), array(), $times ), array(), '' );
		}

		$matches = static function ( \DateTimeImmutable $day ) use ( $freq, $weekdays, $months, $first ): bool {
			switch ( $freq ) {
				case 'WEEKLY':
					return in_array( (int) $day->format( 'N' ), $weekdays, true );
				case 'MONTHLY':
					return $day->format( 'j' ) === $first->format( 'j' ) && ( ! $months || in_array( (int) $day->format( 'n' ), $months, true ) );
				case 'YEARLY':
					return $day->format( 'm-d' ) === $first->format( 'm-d' );
				default:
					return true;
			}
		};

		return array(
			null,
			self::expand( $start, $until, $matches, $times, '' !== $until ? 0 : $count ),
			__( 'repeating dates that repeat monthly, yearly or a set number of times were added as dates for the coming year; add more in the Departures tab later.', 'wpinterface-travel' ),
		);
	}
}
