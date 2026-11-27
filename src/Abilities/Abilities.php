<?php
/**
 * Abilities for AI tools and agents.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Abilities;

use WP_Error;
use WP_Post;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Frontend\TripData;
use WPInterface\Travel\Frontend\TripQuery;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Registers read-only abilities (WordPress 6.9+ Abilities API) that AI
 * tools and agents can use: search trips and check a trip's dates. They
 * return only what the public trip pages show. The MCP Adapter plugin
 * serves them as MCP tools.
 */
final class Abilities {

	public const CATEGORY = 'wpinterface-travel';

	/**
	 * Hooks (only where the Abilities API exists).
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'abilities' ) );
	}

	/**
	 * The "Travel" category.
	 */
	public function category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Travel', 'wpinterface-travel' ),
				'description' => __( 'Trips, departures and bookings.', 'wpinterface-travel' ),
			)
		);
	}

	/**
	 * Trip search and trip availability.
	 */
	public function abilities(): void {
		$public = array(
			'show_in_rest' => true,
			'public'       => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);

		wp_register_ability(
			'wpinterface-travel/search-trips',
			array(
				'label'               => __( 'Search trips', 'wpinterface-travel' ),
				'description'         => __( 'Finds published trips by words, destination, price and length, with their price, duration and next departure dates.', 'wpinterface-travel' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'      => array(
							'type'        => 'string',
							'description' => __( 'Words to look for.', 'wpinterface-travel' ),
						),
						'destination' => array(
							'type'        => 'string',
							'description' => __( 'Destination slug.', 'wpinterface-travel' ),
						),
						'max_price'   => array( 'type' => 'number' ),
						'max_days'    => array( 'type' => 'number' ),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
							'default' => 10,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( self::class, 'search_trips' ),
				'permission_callback' => '__return_true',
				'meta'                => $public,
			)
		);

		wp_register_ability(
			'wpinterface-travel/trip-availability',
			array(
				'label'               => __( 'Trip availability', 'wpinterface-travel' ),
				'description'         => __( 'Departure dates of a trip between two dates, with start times, seats left, whether each can be booked and the price.', 'wpinterface-travel' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'trip_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'from'    => array(
							'type'        => 'string',
							'description' => __( 'First date, YYYY-MM-DD (default today).', 'wpinterface-travel' ),
						),
						'to'      => array(
							'type'        => 'string',
							'description' => __( 'Last date, YYYY-MM-DD (default 90 days on; at most a year).', 'wpinterface-travel' ),
						),
					),
					'required'             => array( 'trip_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( self::class, 'availability' ),
				'permission_callback' => '__return_true',
				'meta'                => $public,
			)
		);
	}

	/**
	 * Search trips.
	 *
	 * @param array<string, mixed>|null $input Input.
	 * @return list<array<string, mixed>>
	 */
	public static function search_trips( $input = null ): array {
		$input   = is_array( $input ) ? $input : array();
		$filters = TripQuery::filters(
			array(
				'trip_q'      => (string) ( $input['search'] ?? '' ),
				'destination' => (string) ( $input['destination'] ?? '' ),
				'max_price'   => $input['max_price'] ?? null,
				'max_days'    => $input['max_days'] ?? null,
			)
		);
		$query   = TripQuery::query( $filters, max( 1, min( 20, (int) ( $input['limit'] ?? 10 ) ) ) );
		$today   = ( new Availability() )->today();
		$trips   = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$card  = TripData::card( $post );
			$dates = array();

			foreach ( array_slice( ( new Availability() )->for_trip( Multilingual::original_trip_id( $post->ID ), $today, gmdate( 'Y-m-d', (int) strtotime( $today . ' +180 days' ) ) ), 0, 5 ) as $slot ) {
				if ( ! empty( $slot['bookable'] ) ) {
					$dates[] = $slot['date'] . ( '' !== $slot['time'] ? ' ' . $slot['time'] : '' );
				}
			}

			$trips[] = array(
				'id'           => $post->ID,
				'title'        => wp_strip_all_tags( $card['title'] ),
				'url'          => $card['url'],
				'summary'      => $card['excerpt'],
				'price_from'   => $card['price'] ? $card['price']['amount'] : null,
				'currency'     => $card['price'] ? $card['price']['currency'] : null,
				'on_request'   => ! empty( $card['on_request'] ),
				'duration'     => $card['duration'],
				'destinations' => $card['destinations'],
				'next_dates'   => $dates,
			);
		}

		return $trips;
	}

	/**
	 * A trip's departures.
	 *
	 * @param array<string, mixed>|null $input Input.
	 * @return list<array<string, mixed>>|WP_Error
	 */
	public static function availability( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$trip  = TripData::resolve( absint( $input['trip_id'] ?? 0 ) );

		if ( ! $trip || PostTypes::TRIP !== $trip->post_type || ! TripData::is_viewable( $trip ) ) {
			return new WP_Error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$availability = new Availability();
		$from         = Schedule::date( (string) ( $input['from'] ?? '' ) );
		$from         = '' !== $from ? max( $from, $availability->today() ) : $availability->today();
		$to           = Schedule::date( (string) ( $input['to'] ?? '' ) );
		$latest       = gmdate( 'Y-m-d', (int) strtotime( $from . ' +365 days' ) );
		$to           = '' !== $to ? min( $to, $latest ) : gmdate( 'Y-m-d', (int) strtotime( $from . ' +90 days' ) );
		$out          = array();

		foreach ( $availability->for_trip( Multilingual::original_trip_id( $trip->ID ), $from, $to ) as $slot ) {
			$out[] = array(
				'date'       => $slot['date'],
				'end_date'   => $slot['end_date'],
				'time'       => $slot['time'],
				'seats_left' => $slot['seats_left'],
				'bookable'   => (bool) $slot['bookable'],
				'price_from' => $slot['from_price'],
				'book_url'   => add_query_arg( 'wpitravel_date', $slot['date'], (string) get_permalink( $trip ) ) . '#wpitravel-booking',
			);
		}

		return $out;
	}
}
