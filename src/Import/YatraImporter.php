<?php
/**
 * Importer for Yatra.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Trips of Yatra 3.x, which keeps trips in its own tables (`yatra_trips`
 * and related; `yatra_new_*` before 3.0.5) instead of posts. Checked
 * against version 3.1. Yatra 2.x (post type `tour`) is not supported.
 */
final class YatraImporter extends Importer {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'yatra';
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return 'Yatra';
	}

	/**
	 * Table name, or '' when Yatra 3 has no data here.
	 *
	 * @param string $name Short name, e.g. trips.
	 */
	private static function table( string $name ): string {
		global $wpdb;

		foreach ( array( 'yatra_', 'yatra_new_' ) as $prefix ) {
			if ( self::table_exists( $wpdb->prefix . $prefix . 'trips' ) ) {
				$table = $wpdb->prefix . $prefix . $name;

				return self::table_exists( $table ) ? $table : '';
			}
		}

		return '';
	}

	/**
	 * Source trips (not deleted).
	 *
	 * @return list<int>
	 */
	protected function source_ids(): array {
		global $wpdb;

		$table = self::table( 'trips' );

		if ( '' === $table ) {
			return array();
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' ORDER BY id", $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Yatra's table.
	}

	/**
	 * Reads one trip.
	 *
	 * @param int $source_id Yatra trip ID.
	 * @return array<string, mixed>|null
	 */
	protected function read( int $source_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Yatra's tables.
		$trip = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'trips' ), $source_id ), ARRAY_A );

		if ( ! is_array( $trip ) ) {
			return null;
		}

		$notes   = array();
		$content = self::table( 'trip_content' );
		$rows    = '' !== $content ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trip_id = %d ORDER BY sort_order, id', $content, $source_id ), ARRAY_A ) : array();

		$highlights = array();
		$faqs       = array();
		$gallery    = array();

		foreach ( $rows as $row ) {
			switch ( $row['content_type'] ) {
				case 'highlight':
					$highlights[] = (string) $row['title'];
					break;
				case 'faq':
					$faqs[] = array(
						'question' => (string) $row['title'],
						'answer'   => (string) $row['description'],
					);
					break;
				case 'image':
					$id = attachment_url_to_postid( (string) $row['content_url'] );

					if ( $id ) {
						$gallery[] = $id;
					}
					break;
			}
		}

		// Itinerary.
		$itinerary = array();
		$days      = self::table( 'trip_itinerary_days' );

		if ( '' !== $days ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT title, description FROM %i WHERE trip_id = %d ORDER BY day_number, `order`, id', $days, $source_id ), ARRAY_A ) as $day ) {
				$itinerary[] = array(
					'title'       => (string) $day['title'],
					'description' => (string) $day['description'],
				);
			}
		}

		// Destinations, activities, categories (trip types) and difficulty.
		$terms           = array();
		$classifications = self::table( 'classifications' );
		$links           = self::table( 'trip_classifications' );

		if ( '' !== $classifications && '' !== $links ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT c.name, c.type FROM %i l INNER JOIN %i c ON c.id = l.classification_id WHERE l.trip_id = %d ORDER BY l.sort_order, c.name', $links, $classifications, $source_id ), ARRAY_A ) as $row ) {
				$terms[ (string) $row['type'] ][] = (string) $row['name'];
			}
		}

		$difficulty = '';

		if ( ! empty( $trip['difficulty_level'] ) && '' !== $classifications ) {
			$difficulty = self::difficulty( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $classifications, (int) $trip['difficulty_level'] ) ) );
		}

		// Fixed departures.
		$dates     = array();
		$capacity  = absint( $trip['max_travelers'] ?? 0 );
		$available = self::table( 'trip_availability_dates' );

		if ( '' !== $available ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT departure_date, seats_total FROM %i WHERE trip_id = %d', $available, $source_id ), ARRAY_A ) as $row ) {
				$dates[]  = (string) $row['departure_date'];
				$capacity = max( $capacity, absint( $row['seats_total'] ) );
			}
		}
		// Repeating rules.
		$rules    = array();
		$excluded = array();
		$extra    = array();
		$table    = self::table( 'trip_availability_rules' );

		if ( '' !== $table ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE trip_id = %d AND status = 'active'", $table, $source_id ), ARRAY_A ) as $row ) {
				if ( 'unavailable' === ( $row['availability_status'] ?? '' ) ) {
					continue;
				}

				list( $rule, $expanded, $note ) = self::repeating( $row );

				if ( $rule ) {
					$rules[] = $rule;
				}

				$extra    = array_merge( $extra, $expanded );
				$capacity = max( $capacity, absint( $row['seats_total'] ?? 0 ) );
				$excluded = array_merge( $excluded, self::excluded( $row['excluded_dates'] ?? '' ) );

				if ( '' !== $note ) {
					$notes[] = $note;
				}
			}
		}
		// phpcs:enable

		$dates    = array_merge( self::future_dates( $dates ), $extra );
		$schedule = $dates || $rules ? array(
			'fixed'        => $dates,
			'recurring'    => $rules,
			'excluded'     => array_values( array_unique( $excluded ) ),
			'capacity'     => $capacity,
			'cutoff_hours' => absint( $trip['booking_deadline_hours'] ?? 0 ),
		) : array();

		if ( ! $dates && ! $rules ) {
			$notes[] = __( 'no upcoming dates; add departures in the Departures tab.', 'wpinterface-travel' );
		}

		if ( $excluded && count( $rules ) + ( $extra ? 1 : 0 ) > 1 ) {
			$notes[] = __( 'excluded dates of each Yatra rule now apply to the whole trip; check the Departures tab.', 'wpinterface-travel' );
		}

		$notes = array_values( array_unique( $notes ) );

		$pricing = $this->pricing( $trip );

		if ( ! $pricing ) {
			$notes[] = __( 'no prices found; add them in the Pricing tab.', 'wpinterface-travel' );
		}

		$map = array();

		if ( null !== $trip['starting_latitude'] && null !== $trip['starting_longitude'] && ( 0.0 !== (float) $trip['starting_latitude'] || 0.0 !== (float) $trip['starting_longitude'] ) ) {
			$map = array(
				'lat'     => (float) $trip['starting_latitude'],
				'lng'     => (float) $trip['starting_longitude'],
				'zoom'    => 10,
				'address' => (string) $trip['starting_location'],
			);
		}

		$hours = absint( $trip['duration_hours'] ?? 0 );

		return array(
			'post'      => array(
				// Read from Yatra's own table: cleaned like any other input.
				'post_title'   => sanitize_text_field( (string) $trip['title'] ),
				'post_name'    => sanitize_title( (string) $trip['slug'] ),
				'post_content' => wp_kses_post( (string) $trip['description'] ),
				'post_excerpt' => sanitize_textarea_field( (string) $trip['short_description'] ),
				'post_status'  => in_array( $trip['status'], array( 'publish', 'published' ), true ) ? 'publish' : 'draft',
			),
			'meta'      => array(
				TripMeta::DURATION      => $hours > 0 ? array( 'hours' => $hours ) : array(
					'days'   => absint( $trip['duration_days'] ?? 0 ),
					'nights' => absint( $trip['duration_nights'] ?? 0 ),
				),
				TripMeta::DIFFICULTY    => $difficulty,
				TripMeta::GROUP_SIZE    => array(
					'min' => absint( $trip['min_travelers'] ?? 0 ),
					'max' => absint( $trip['max_travelers'] ?? 0 ),
				),
				TripMeta::MIN_AGE       => absint( $trip['age_min'] ?? 0 ),
				TripMeta::MEETING_POINT => (string) ( $trip['starting_location'] ?? '' ),
				TripMeta::HIGHLIGHTS    => array_values( array_filter( $highlights ) ),
				TripMeta::ITINERARY     => $itinerary,
				TripMeta::INCLUDES      => self::items( $trip['included_items'] ?? '' ),
				TripMeta::EXCLUDES      => self::items( $trip['excluded_items'] ?? '' ),
				TripMeta::FAQS          => $faqs,
				TripMeta::GALLERY       => $gallery,
				TripMeta::MAP           => $map,
				TripMeta::PRICING       => $pricing,
				TripMeta::SCHEDULE      => $schedule,
			),
			'terms'     => array(
				PostTypes::DESTINATION => $terms['destination'] ?? array(),
				PostTypes::ACTIVITY    => $terms['activity'] ?? array(),
				PostTypes::TRIP_TYPE   => $terms['category'] ?? array(),
			),
			'thumbnail' => absint( $trip['featured_image'] ?? 0 ),
			'notes'     => $notes,
		);
	}

	/**
	 * Prices: one price, or one per traveler type.
	 *
	 * @param array<string, mixed> $trip Trip row.
	 * @return array<string, mixed>
	 */
	private function pricing( array $trip ): array {
		$categories = array();

		if ( 'traveler_based' === ( $trip['pricing_type'] ?? '' ) ) {
			foreach ( (array) json_decode( (string) ( $trip['price_types'] ?? '' ), true ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$regular = (string) ( $row['original_price'] ?? ( $row['price'] ?? '' ) );
				$sale    = (string) ( $row['sale_price'] ?? ( $row['discounted_price'] ?? '' ) );

				$categories[] = array( (string) ( $row['label'] ?? ( $row['category_label'] ?? '' ) ), $regular, $sale );
			}
		}

		if ( ! $categories ) {
			$sale         = (string) ( $trip['sale_price'] ?? '' );
			$sale         = '' !== $sale && (float) $sale > 0 ? $sale : (string) ( $trip['discounted_price'] ?? '' );
			$categories[] = array( '', (string) ( $trip['original_price'] ?? '' ), (float) $sale > 0 ? $sale : '' );
		}

		$option = self::price_option( '', $categories, absint( $trip['min_travelers'] ?? 0 ), absint( $trip['max_travelers'] ?? 0 ) );

		return $option && (float) $option['categories'][0]['price'] > 0 ? array( 'options' => array( $option ) ) : array();
	}

	/**
	 * Titles from Yatra's included/excluded JSON ([{title, description}]).
	 *
	 * @param mixed $json JSON.
	 * @return list<string>
	 */
	private static function items( $json ): array {
		$items = array();

		foreach ( (array) json_decode( (string) $json, true ) as $row ) {
			$items[] = is_array( $row ) ? (string) ( $row['title'] ?? '' ) : (string) $row;
		}

		return array_values( array_filter( array_map( 'trim', $items ) ) );
	}
	/**
	 * A repeating availability rule: a rule when it fits (weekly, every day,
	 * every 7 days), otherwise dates for the coming year (Nth weekday of the
	 * month, every N days).
	 *
	 * Row: rule_type (weekly, monthly, interval), start_date, end_date,
	 * days_of_week (JSON 0–6, 0 = Sunday), week_of_month (1–4, 5 = last),
	 * day_of_week, interval_days, interval_start_date, months (JSON 1–12),
	 * time_slots (JSON [{departure_time}]) or departure_time.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{0: array<string, mixed>|null, 1: list<array{date: string, time: string}>, 2: string} Rule, dates, note.
	 */
	private static function repeating( array $row ): array {
		$json   = static function ( $value ): array {
			$decoded = is_string( $value ) ? json_decode( $value, true ) : $value;

			return is_array( $decoded ) ? $decoded : ( is_string( $value ) && '' !== trim( $value ) ? explode( ',', $value ) : array() );
		};
		$months = array_values( array_filter( array_map( 'intval', $json( $row['months'] ?? '' ) ) ) );
		$slots  = array_map( static fn( $slot ): string => is_array( $slot ) ? (string) ( $slot['departure_time'] ?? '' ) : '', $json( $row['time_slots'] ?? '' ) );
		$times  = self::times( array_filter( $slots ) ? $slots : array( (string) ( $row['departure_time'] ?? '' ) ) );
		$start  = self::ymd( $row['start_date'] ?? '' );
		$end    = self::ymd( $row['end_date'] ?? '' );
		$iso    = static fn( int $day ): int => 0 === $day ? 7 : $day;
		$month  = static fn( \DateTimeImmutable $day ): bool => ! $months || in_array( (int) $day->format( 'n' ), $months, true );
		$type   = (string) ( $row['rule_type'] ?? 'weekly' );
		$note   = __( 'repeating dates on certain weeks of the month or every few days were added as dates for the coming year; add more in the Departures tab later.', 'wpinterface-travel' );

		if ( 'weekly' === $type ) {
			$days = array_map( static fn( $day ): int => $iso( (int) $day ), $json( $row['days_of_week'] ?? '' ) );

			return array( self::rule( $start, $end, $days, $months, $times ), array(), '' );
		}

		if ( 'interval' === $type ) {
			$every     = max( 1, (int) ( $row['interval_days'] ?? 1 ) );
			$reference = self::ymd( '' !== (string) ( $row['interval_start_date'] ?? '' ) ? $row['interval_start_date'] : $start );

			if ( 1 === $every || ( 7 === $every && '' !== $reference ) ) {
				$weekday = 7 === $every ? array( (int) gmdate( 'N', (int) strtotime( $reference ) ) ) : array();

				return array( self::rule( $start, $end, $weekday, $months, $times ), array(), '' );
			}

			$origin = (int) strtotime( '' !== $reference ? $reference : current_time( 'Y-m-d' ) );

			return array(
				null,
				self::expand( $start, $end, static fn( \DateTimeImmutable $day ): bool => $month( $day ) && 0 === ( (int) round( ( strtotime( $day->format( 'Y-m-d' ) ) - $origin ) / DAY_IN_SECONDS ) ) % $every, $times ),
				$note,
			);
		}

		if ( 'monthly' === $type ) {
			$week    = (int) ( $row['week_of_month'] ?? 1 );
			$weekday = $iso( (int) ( $row['day_of_week'] ?? 0 ) );

			return array(
				null,
				self::expand(
					$start,
					$end,
					static fn( \DateTimeImmutable $day ): bool => $month( $day )
						&& (int) $day->format( 'N' ) === $weekday
						&& ( 5 === $week ? $day->modify( '+7 days' )->format( 'n' ) !== $day->format( 'n' ) : (int) ceil( (int) $day->format( 'j' ) / 7 ) === $week ),
					$times
				),
				$note,
			);
		}

		return array( null, array(), '' );
	}

	/**
	 * Excluded days from a rule's JSON list of dates and {start, end} periods.
	 *
	 * @param mixed $value JSON.
	 * @return list<string>
	 */
	private static function excluded( $value ): array {
		$items = is_string( $value ) ? json_decode( $value, true ) : $value;
		$days  = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$days = array_merge( $days, is_array( $item ) ? self::days_between( (string) ( $item['start'] ?? '' ), (string) ( $item['end'] ?? '' ) ) : self::days_between( (string) $item, '' ) );
		}

		$today = current_time( 'Y-m-d' );

		return array_values( array_filter( $days, static fn( string $day ): bool => $day >= $today ) );
	}
}
