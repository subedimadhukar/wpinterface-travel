<?php
/**
 * Booking widget on trip pages.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Post;
use WPInterface\Travel\Admin\Assets;
use WPInterface\Travel\Booking\Pickups;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Payments\Gateway;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the booking widget: [wpitravel_booking trip="123"], or appended to
 * single trip pages when "Add the booking form to trip pages" is on.
 *
 * The widget is a small React app that talks to the checkout REST endpoints.
 * Themes and blocks (Phase 6) can place it anywhere with the shortcode.
 */
final class BookingWidget {

	public const SHORTCODE = 'wpitravel_booking';

	public const ENQUIRY_SHORTCODE = 'wpitravel_enquiry';

	/**
	 * Trips whose data was already printed on this page.
	 *
	 * @var array<int, bool>
	 */
	private array $printed = array();

	/**
	 * Whether the shared page data was printed.
	 *
	 * @var bool
	 */
	private bool $page_data_printed = false;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_shortcode( self::ENQUIRY_SHORTCODE, array( $this, 'enquiry_shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_to_trip' ), 20 );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'trip' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$id   = absint( $atts['trip'] ) ? absint( $atts['trip'] ) : (int) get_the_ID();

		return $this->render( $id );
	}

	/**
	 * [wpitravel_enquiry trip="123"]: just the enquiry form (trip optional).
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function enquiry_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'trip' => 0 ), is_array( $atts ) ? $atts : array(), self::ENQUIRY_SHORTCODE );
		$trip = absint( $atts['trip'] );

		if ( ! Assets::enqueue( 'booking', '', array(), array() ) ) {
			return '';
		}

		$this->print_page_data();

		return sprintf(
			'<div class="wpitravel-booking wpitravel-enquiry" data-trip-id="%1$d"><noscript>%2$s</noscript></div>',
			$trip,
			esc_html__( 'Turn on JavaScript to send us a message.', 'wpinterface-travel' )
		);
	}

	/**
	 * Prints the shared page data once.
	 */
	private function print_page_data(): void {
		if ( ! $this->page_data_printed ) {
			wp_add_inline_script( 'wpitravel-booking', 'window.wpitravelBooking = ' . wp_json_encode( $this->page_data(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
			$this->page_data_printed = true;
		}
	}

	/**
	 * Appends the widget to the content of a single trip page.
	 *
	 * @param string $content Post content.
	 */
	public function append_to_trip( $content ): string {
		$content = (string) $content;

		if (
			! is_singular( PostTypes::TRIP )
			|| ! in_the_loop()
			|| ! is_main_query()
			|| ! wpitravel()->options()->get( 'booking.auto_widget', true )
			|| has_shortcode( $content, self::SHORTCODE )
			|| Templates::renders_parts()
		) {
			return $content;
		}

		return $content . $this->render( (int) get_the_ID() );
	}

	/**
	 * Widget markup for a trip, and its script and data.
	 *
	 * @param int $trip_id Trip ID.
	 */
	public function render( int $trip_id ): string {
		$trip = get_post( $trip_id );

		if ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type || 'publish' !== $trip->post_status || post_password_required( $trip ) ) {
			return '';
		}

		// Priced on request: the enquiry form takes the booking's place.
		if ( TripMeta::on_request( $trip_id ) ) {
			return $this->enquiry_shortcode( array( 'trip' => (string) $trip_id ) );
		}

		$data = self::trip_data( $trip );

		if ( empty( $data['options'] ) ) {
			return '';
		}

		if ( ! Assets::enqueue( 'booking', '', array(), array() ) ) {
			return '';
		}

		$this->print_page_data();

		if ( empty( $this->printed[ $trip_id ] ) ) {
			wp_add_inline_script( 'wpitravel-booking', 'window.wpitravelBooking.trips[' . $trip_id . '] = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
			$this->printed[ $trip_id ] = true;
		}

		return sprintf(
			'<div id="wpitravel-booking" class="wpitravel-booking" data-trip-id="%1$d"><noscript>%2$s</noscript></div>',
			$trip_id,
			esc_html__( 'Turn on JavaScript to book this trip.', 'wpinterface-travel' )
		);
	}

	/**
	 * Data shared by every widget on the page.
	 *
	 * @return array<string, mixed>
	 */
	private function page_data(): array {
		$options  = wpitravel()->options();
		$currency = (array) $options->get( 'currency', array() );
		$code     = (string) ( $currency['code'] ?? 'USD' );
		$terms_id = (int) $options->get( 'booking.terms_page_id', 0 );
		$user     = wp_get_current_user();

		return array(
			'trips'          => new \stdClass(),
			'currency'       => array_merge( $currency, array( 'symbol' => Currencies::all()[ $code ]['symbol'] ?? $code ) ),
			'taxIncluded'    => $options->get( 'tax.enabled' ) && $options->get( 'tax.prices_include_tax' ),
			'holdMinutes'    => (int) $options->get( 'booking.hold_minutes', 15 ),
			'locale'         => determine_locale(),
			'guestCheckout'  => (bool) $options->get( 'booking.guest_checkout', true ),
			'createAccounts' => (bool) $options->get( 'booking.create_accounts', false ),
			'terms'          => $terms_id && 'publish' === get_post_status( $terms_id )
				? array(
					'url'   => get_permalink( $terms_id ),
					'title' => get_the_title( $terms_id ),
				)
				: null,
			'user'           => $user->exists()
				? array(
					'email'     => $user->user_email,
					'firstName' => $user->first_name,
					'lastName'  => $user->last_name,
				)
				: null,
			'gateways'       => array_values(
				array_map(
					static fn( Gateway $gateway ): array => array(
						'id'          => $gateway->id(),
						'title'       => $gateway->title(),
						'description' => $gateway->description(),
						'online'      => $gateway->is_online(),
					),
					Gateways::available( $code )
				)
			),
			'loginUrl'       => wp_login_url( (string) get_permalink() ),
			'today'          => current_time( 'Y-m-d' ),
			'startOfWeek'    => (int) get_option( 'start_of_week', 1 ),
		);
	}

	/**
	 * Price options of a trip for the widget (and the staff booking form).
	 *
	 * @param WP_Post $trip Trip.
	 * @return array<string, mixed>
	 */
	public static function trip_data( WP_Post $trip ): array {
		$decimals = (int) wpitravel()->options()->get( 'currency.decimals', 2 );
		// A translated trip books the original: its prices, dates and seats.
		$original = Multilingual::original_trip_id( $trip->ID );
		$pricing  = Pricing::sanitize( get_post_meta( $original, TripMeta::PRICING, true ), $decimals );
		$labels   = $original !== $trip->ID ? self::labels( Pricing::sanitize( get_post_meta( $trip->ID, TripMeta::PRICING, true ), $decimals ) ) : array();
		$options  = array();

		foreach ( $pricing['options'] as $option ) {
			$categories = array();

			foreach ( $option['categories'] as $category ) {
				$categories[] = array(
					'key'     => $category['key'],
					'label'   => $labels[ $option['key'] . '/' . $category['key'] ] ?? $category['label'],
					'price'   => Pricing::effective_price( $category, $decimals ),
					'regular' => $category['price'],
					'min'     => $category['min'],
					'max'     => $category['max'],
				);
			}

			$options[] = array(
				'key'        => $option['key'],
				'label'      => $labels[ $option['key'] ] ?? $option['label'],
				'minPax'     => $option['min_pax'],
				'maxPax'     => $option['max_pax'],
				'categories' => $categories,
			);
		}

		/**
		 * Filters a trip's data for the booking form (Pro adds its extras).
		 *
		 * @param array   $data Data: id, title, options.
		 * @param WP_Post $trip Trip.
		 */
		return (array) apply_filters(
			'wpitravel_booking_trip_data',
			array(
				'id'      => $original,
				'title'   => get_the_title( $trip ),
				'options' => $options,
				'pickups' => self::pickups( $trip->ID, $original ),
			),
			$trip
		);
	}
	/**
	 * Translated names of price options and traveler types, by key
	 * ("option" and "option/category").
	 *
	 * @param array<string, mixed> $pricing Sanitized pricing of a translation.
	 * @return array<string, string>
	 */
	private static function labels( array $pricing ): array {
		$labels = array();

		foreach ( (array) ( $pricing['options'] ?? array() ) as $option ) {
			$labels[ (string) $option['key'] ] = (string) $option['label'];

			foreach ( (array) $option['categories'] as $category ) {
				$labels[ $option['key'] . '/' . $category['key'] ] = (string) $category['label'];
			}
		}

		return array_filter( $labels, static fn( string $label ): bool => '' !== $label );
	}
	/**
	 * Pickup points for the booking form: the original trip's points (the
	 * ones bookings are checked against), with a translation's names.
	 *
	 * @param int $trip_id  Trip shown.
	 * @param int $original Original trip.
	 * @return list<array{key: string, name: string, time: string, note: string}>
	 */
	private static function pickups( int $trip_id, int $original ): array {
		$names = array();

		if ( $trip_id !== $original ) {
			foreach ( Pickups::for_trip( $trip_id ) as $point ) {
				$names[ $point['key'] ] = $point;
			}
		}

		return array_map(
			static fn( array $point ): array => array(
				'key'  => $point['key'],
				'name' => $names[ $point['key'] ]['name'] ?? $point['name'],
				'time' => $point['time'],
				'note' => $names[ $point['key'] ]['note'] ?? $point['note'],
			),
			Pickups::for_trip( $original )
		);
	}
}
