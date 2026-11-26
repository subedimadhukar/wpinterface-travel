<?php
/**
 * Trip detail fields.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Content;

use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the trip's detail fields as post meta, exposed in REST.
 *
 * Every field has a REST schema (checked when the editor saves) and a
 * sanitize callback (applied to every write, including imports and code).
 * Pricing and the departure schedule have their own classes
 * (Pricing\Pricing, Departures\Schedule).
 */
final class TripMeta {

	public const CODE          = '_wpitravel_trip_code';
	public const DURATION      = '_wpitravel_duration';
	public const DIFFICULTY    = '_wpitravel_difficulty';
	public const GROUP_SIZE    = '_wpitravel_group_size';
	public const MIN_AGE       = '_wpitravel_min_age';
	public const MEETING_POINT = '_wpitravel_meeting_point';
	public const HIGHLIGHTS    = '_wpitravel_highlights';
	public const ITINERARY     = '_wpitravel_itinerary';
	public const INCLUDES      = '_wpitravel_includes';
	public const EXCLUDES      = '_wpitravel_excludes';
	public const FAQS          = '_wpitravel_faqs';
	public const FACTS         = '_wpitravel_facts';
	public const GALLERY       = '_wpitravel_gallery';
	public const MAP           = '_wpitravel_map';
	public const PRICING       = '_wpitravel_pricing';
	public const SCHEDULE      = '_wpitravel_departure_rules';

	/**
	 * Maximum rows in any list field, to keep a malicious request small.
	 */
	private const MAX_ROWS = 200;

	/**
	 * Hooks registration into `init`.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 6 );
	}

	/**
	 * Difficulty levels: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function difficulties(): array {
		/**
		 * Filters the trip difficulty levels.
		 *
		 * @param array $levels Key => label.
		 */
		return (array) apply_filters(
			'wpitravel_trip_difficulties',
			array(
				'easy'        => __( 'Easy', 'wpinterface-travel' ),
				'moderate'    => __( 'Moderate', 'wpinterface-travel' ),
				'challenging' => __( 'Challenging', 'wpinterface-travel' ),
				'strenuous'   => __( 'Strenuous', 'wpinterface-travel' ),
			)
		);
	}

	/**
	 * Meals that an itinerary day can include: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function meals(): array {
		return array(
			'breakfast' => __( 'Breakfast', 'wpinterface-travel' ),
			'lunch'     => __( 'Lunch', 'wpinterface-travel' ),
			'dinner'    => __( 'Dinner', 'wpinterface-travel' ),
		);
	}

	/**
	 * Registers every field.
	 */
	public function register_meta(): void {
		foreach ( self::definitions() as $key => $definition ) {
			register_post_meta(
				PostTypes::TRIP,
				$key,
				array(
					'type'              => $definition['type'],
					'single'            => true,
					'default'           => $definition['default'],
					'sanitize_callback' => $definition['sanitize'],
					'auth_callback'     => array( self::class, 'can_edit' ),
					'show_in_rest'      => isset( $definition['schema'] ) ? array( 'schema' => $definition['schema'] ) : true,
				)
			);
		}
	}

	/**
	 * Only users who can edit the trip may write its meta.
	 *
	 * @param bool   $allowed  Whether allowed so far.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Trip ID.
	 */
	public static function can_edit( $allowed, $meta_key, $post_id ): bool {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Field definitions: meta key => type, default, sanitize callback and optional REST schema.
	 *
	 * @return array<string, array{type: string, default: mixed, sanitize: callable, schema?: array<string, mixed>}>
	 */
	public static function definitions(): array {
		$string_list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			self::CODE          => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
			),
			self::DURATION      => array(
				'type'     => 'object',
				'default'  => array(
					'days'   => 0,
					'nights' => 0,
					'hours'  => 0,
				),
				'sanitize' => array( self::class, 'sanitize_duration' ),
				'schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'days'   => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'nights' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'hours'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
			),
			self::DIFFICULTY    => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( self::class, 'sanitize_difficulty' ),
			),
			self::GROUP_SIZE    => array(
				'type'     => 'object',
				'default'  => array(
					'min' => 0,
					'max' => 0,
				),
				'sanitize' => array( self::class, 'sanitize_group_size' ),
				'schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'min' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'max' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
			),
			self::MIN_AGE       => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			),
			self::MEETING_POINT => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
			),
			self::HIGHLIGHTS    => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_string_list' ),
				'schema'   => $string_list,
			),
			self::ITINERARY     => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_itinerary' ),
				'schema'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'         => array( 'type' => 'string' ),
							'description'   => array( 'type' => 'string' ),
							'meals'         => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'string',
									'enum' => array_keys( self::meals() ),
								),
							),
							'accommodation' => array( 'type' => 'string' ),
						),
					),
				),
			),
			self::INCLUDES      => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_string_list' ),
				'schema'   => $string_list,
			),
			self::EXCLUDES      => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_string_list' ),
				'schema'   => $string_list,
			),
			self::FAQS          => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_faqs' ),
				'schema'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'question' => array( 'type' => 'string' ),
							'answer'   => array( 'type' => 'string' ),
						),
					),
				),
			),
			self::FACTS         => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_facts' ),
				'schema'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'label' => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
						),
					),
				),
			),
			self::GALLERY       => array(
				'type'     => 'array',
				'default'  => array(),
				'sanitize' => array( self::class, 'sanitize_gallery' ),
				'schema'   => array(
					'type'  => 'array',
					'items' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			self::MAP           => array(
				'type'     => 'object',
				'default'  => array(
					'lat'     => 0,
					'lng'     => 0,
					'zoom'    => 10,
					'address' => '',
				),
				'sanitize' => array( self::class, 'sanitize_map' ),
				'schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'lat'     => array(
							'type'    => 'number',
							'minimum' => -90,
							'maximum' => 90,
						),
						'lng'     => array(
							'type'    => 'number',
							'minimum' => -180,
							'maximum' => 180,
						),
						'zoom'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
						'address' => array( 'type' => 'string' ),
					),
				),
			),
			self::PRICING       => array(
				'type'     => 'object',
				'default'  => Pricing::empty_value(),
				// Wrapped: register_meta passes the meta key as the second argument.
				'sanitize' => static fn( $value ) => Pricing::sanitize( $value ),
				'schema'   => self::pricing_schema(),
			),
			self::SCHEDULE      => array(
				'type'     => 'object',
				'default'  => Schedule::empty_value(),
				'sanitize' => static fn( $value ) => Schedule::sanitize( $value ),
				'schema'   => self::schedule_schema(),
			),
		);
	}

	/**
	 * REST schema of the pricing field. Prices may be sent as strings or numbers.
	 *
	 * @return array<string, mixed>
	 */
	private static function pricing_schema(): array {
		$count = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$money = array( 'type' => array( 'string', 'number' ) );

		return array(
			'type'       => 'object',
			'properties' => array(
				'options' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'        => array( 'type' => 'string' ),
							'label'      => array( 'type' => 'string' ),
							'min_pax'    => $count,
							'max_pax'    => $count,
							'categories' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'key'        => array( 'type' => 'string' ),
										'label'      => array( 'type' => 'string' ),
										'price'      => $money,
										'sale_price' => $money,
										'min'        => $count,
										'max'        => $count,
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * REST schema of the departure schedule field.
	 *
	 * @return array<string, mixed>
	 */
	private static function schedule_schema(): array {
		$count   = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			'type'       => 'object',
			'properties' => array(
				'fixed'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'date' => array( 'type' => 'string' ),
							'time' => array( 'type' => 'string' ),
						),
					),
				),
				'recurring'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'start'    => array( 'type' => 'string' ),
							'end'      => array( 'type' => 'string' ),
							'weekdays' => array(
								'type'  => 'array',
								'items' => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 7,
								),
							),
							'months'   => array(
								'type'  => 'array',
								'items' => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 12,
								),
							),
							'times'    => $strings,
						),
					),
				),
				'excluded'     => $strings,
				'capacity'     => $count,
				'cutoff_hours' => $count,
				'advance_days' => $count,
			),
		);
	}

	/**
	 * Duration in days, nights and hours (hours are for day trips).
	 *
	 * @param mixed $value Raw value.
	 * @return array{days: int, nights: int, hours: int}
	 */
	public static function sanitize_duration( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'days'   => absint( $value['days'] ?? 0 ),
			'nights' => absint( $value['nights'] ?? 0 ),
			'hours'  => absint( $value['hours'] ?? 0 ),
		);
	}

	/**
	 * A known difficulty key, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_difficulty( $value ): string {
		$value = sanitize_key( is_string( $value ) ? $value : '' );

		return array_key_exists( $value, self::difficulties() ) ? $value : '';
	}

	/**
	 * Group size; max 0 means no limit, and max is never below min.
	 *
	 * @param mixed $value Raw value.
	 * @return array{min: int, max: int}
	 */
	public static function sanitize_group_size( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$min   = absint( $value['min'] ?? 0 );
		$max   = absint( $value['max'] ?? 0 );

		if ( $max > 0 && $max < $min ) {
			$max = $min;
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Non-empty plain-text lines.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	public static function sanitize_string_list( $value ): array {
		$clean = array();

		foreach ( self::rows( $value ) as $item ) {
			$item = is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}

	/**
	 * Itinerary days. Descriptions allow post HTML.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{title: string, description: string, meals: list<string>, accommodation: string}>
	 */
	public static function sanitize_itinerary( $value ): array {
		$clean = array();

		foreach ( self::rows( $value ) as $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}

			$meals = array_values( array_intersect( array_keys( self::meals() ), (array) ( $day['meals'] ?? array() ) ) );
			$row   = array(
				'title'         => self::text( $day['title'] ?? '' ),
				'description'   => self::html( $day['description'] ?? '' ),
				'meals'         => $meals,
				'accommodation' => self::text( $day['accommodation'] ?? '' ),
			);

			if ( '' !== $row['title'] || '' !== $row['description'] ) {
				$clean[] = $row;
			}
		}

		return $clean;
	}

	/**
	 * FAQs with a question. Answers allow post HTML.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{question: string, answer: string}>
	 */
	public static function sanitize_faqs( $value ): array {
		$clean = array();

		foreach ( self::rows( $value ) as $faq ) {
			if ( ! is_array( $faq ) ) {
				continue;
			}

			$question = self::text( $faq['question'] ?? '' );

			if ( '' !== $question ) {
				$clean[] = array(
					'question' => $question,
					'answer'   => self::html( $faq['answer'] ?? '' ),
				);
			}
		}

		return $clean;
	}

	/**
	 * Custom label/value facts shown next to the built-in ones.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{label: string, value: string}>
	 */
	public static function sanitize_facts( $value ): array {
		$clean = array();

		foreach ( self::rows( $value ) as $fact ) {
			if ( ! is_array( $fact ) ) {
				continue;
			}

			$label = self::text( $fact['label'] ?? '' );
			$text  = self::text( $fact['value'] ?? '' );

			if ( '' !== $label && '' !== $text ) {
				$clean[] = array(
					'label' => $label,
					'value' => $text,
				);
			}
		}

		return $clean;
	}

	/**
	 * Unique attachment IDs, in order.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int>
	 */
	public static function sanitize_gallery( $value ): array {
		$ids = array_filter( array_map( 'absint', self::rows( $value ) ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Map position. 0,0 means "no position set".
	 *
	 * @param mixed $value Raw value.
	 * @return array{lat: float, lng: float, zoom: int, address: string}
	 */
	public static function sanitize_map( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'lat'     => max( -90.0, min( 90.0, (float) ( $value['lat'] ?? 0 ) ) ),
			'lng'     => max( -180.0, min( 180.0, (float) ( $value['lng'] ?? 0 ) ) ),
			'zoom'    => max( 1, min( 20, (int) ( $value['zoom'] ?? 10 ) ) ),
			'address' => self::text( $value['address'] ?? '' ),
		);
	}

	/**
	 * The value as a list, capped at MAX_ROWS.
	 *
	 * @param mixed $value Raw value.
	 * @return list<mixed>
	 */
	private static function rows( $value ): array {
		return is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_ROWS ) : array();
	}

	/**
	 * Single-line plain text.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * HTML allowed in post content.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function html( $value ): string {
		return is_scalar( $value ) ? trim( wp_kses_post( (string) $value ) ) : '';
	}
}
