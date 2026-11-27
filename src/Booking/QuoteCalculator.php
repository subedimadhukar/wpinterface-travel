<?php
/**
 * Booking price calculation.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WP_Error;
use WP_Post;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Prices a selection (trip, date, option, travelers, coupon) on the server.
 *
 * The browser never sends prices; every total shown, held and booked comes
 * from here. Amounts are summed in minor units and returned as strings.
 *
 * Tax: when prices exclude tax it is added to (subtotal − discount); when
 * they include tax, the included part is shown and the total is unchanged.
 */
final class QuoteCalculator {

	/**
	 * Availability engine.
	 *
	 * @var Availability
	 */
	private Availability $availability;

	/**
	 * Coupons.
	 *
	 * @var Coupons
	 */
	private Coupons $coupons;

	/**
	 * Constructor.
	 *
	 * @param Availability|null $availability Availability engine.
	 * @param Coupons|null      $coupons      Coupons.
	 */
	public function __construct( ?Availability $availability = null, ?Coupons $coupons = null ) {
		$this->availability = $availability ?? new Availability();
		$this->coupons      = $coupons ?? new Coupons();
	}

	/**
	 * Prices a selection.
	 *
	 * @param array<string, mixed> $input   trip_id, date, time, price_option, pax (key => count), coupon.
	 * @param array<string, mixed> $context check_seats (bool, default true), statuses (allowed date statuses),
	 *                                      email (for per-customer coupon limits), strict_coupon (bool: an
	 *                                      invalid coupon is an error instead of a message).
	 * @return array<string, mixed>|WP_Error
	 */
	public function calculate( array $input, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'check_seats'   => true,
				'statuses'      => array( 'open' ),
				'email'         => '',
				'strict_coupon' => false,
			)
		);

		$trip = get_post( absint( $input['trip_id'] ?? 0 ) );

		$hidden = $trip instanceof WP_Post && ( 'publish' !== $trip->post_status || post_password_required( $trip ) );

		if ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type || ( $hidden && ! current_user_can( 'edit_post', $trip->ID ) ) ) {
			return self::error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), 404 );
		}

		if ( TripMeta::on_request( $trip->ID ) ) {
			return self::error( 'wpitravel_price_on_request', __( 'This trip is priced on request. Send us an enquiry for a quote.', 'wpinterface-travel' ), 409 );
		}

		$date = Schedule::date( $input['date'] ?? '' );
		$time = Schedule::time( $input['time'] ?? '' );

		if ( '' === $date ) {
			return self::error( 'wpitravel_date_unavailable', __( 'Choose a departure date.', 'wpinterface-travel' ), 400 );
		}

		$decimals = (int) wpitravel()->options()->get( 'currency.decimals', 2 );
		$pricing  = Pricing::sanitize( get_post_meta( $trip->ID, TripMeta::PRICING, true ), $decimals );
		$option   = self::pick_option( $pricing, (string) ( $input['price_option'] ?? '' ) );

		if ( ! $option ) {
			return self::error( 'wpitravel_invalid_price_option', __( 'Choose one of the trip\'s price options.', 'wpinterface-travel' ), 400 );
		}

		$slot = self::find_slot( $this->availability->for_trip( $trip->ID, $date, $date, true, $option['key'] ), $time );

		if ( ! $slot || ! in_array( $slot['status'], (array) $context['statuses'], true ) ) {
			if ( $slot && 'past_cutoff' === $slot['status'] ) {
				return self::error( 'wpitravel_cutoff_passed', __( 'Bookings for this date have closed.', 'wpinterface-travel' ), 409 );
			}

			if ( $slot && 'sold_out' === $slot['status'] ) {
				return self::error( 'wpitravel_not_enough_seats', __( 'This date is sold out.', 'wpinterface-travel' ), 409, array( 'seats_left' => 0 ) );
			}

			return self::error( 'wpitravel_date_unavailable', __( 'This date is not available. Choose another date.', 'wpinterface-travel' ), 409 );
		}

		$pax = self::validate_pax( $option, (array) ( $input['pax'] ?? array() ) );

		if ( is_wp_error( $pax ) ) {
			return $pax;
		}

		$pax_total = array_sum( $pax );

		if ( $context['check_seats'] && null !== $slot['seats_left'] && $pax_total > $slot['seats_left'] ) {
			return self::error(
				'wpitravel_not_enough_seats',
				sprintf(
					/* translators: %d: seats left. */
					_n( 'Only %d seat is left on this date.', 'Only %d seats are left on this date.', (int) $slot['seats_left'], 'wpinterface-travel' ),
					(int) $slot['seats_left']
				),
				409,
				array( 'seats_left' => $slot['seats_left'] )
			);
		}

		$items    = array();
		$subtotal = 0;

		foreach ( $option['categories'] as $category ) {
			$quantity = $pax[ $category['key'] ] ?? 0;

			if ( $quantity < 1 ) {
				continue;
			}

			$unit      = Money::to_minor( Pricing::effective_price( $category, $decimals ), $decimals );
			$subtotal += $unit * $quantity;
			$items[]   = array(
				'type'        => 'pax',
				'key'         => $category['key'],
				'label'       => $category['label'],
				'quantity'    => $quantity,
				'unit_amount' => Money::from_minor( $unit, $decimals ),
				'total'       => Money::from_minor( $unit * $quantity, $decimals ),
			);
		}

		$extras = array();

		foreach ( (array) ( $input['extras'] ?? array() ) as $key => $quantity ) {
			if ( is_string( $key ) && '' !== sanitize_key( $key ) && (int) $quantity > 0 ) {
				$extras[ sanitize_key( $key ) ] = min( 999, (int) $quantity );
			}
		}

		/**
		 * Adds priced lines before coupons and tax: Pro's tour extras
		 * (positive) and group discounts (negative). Return a WP_Error to
		 * refuse the selection (e.g. a required extra is missing).
		 *
		 * Each line: type, key, label, quantity, total_minor (signed, in the
		 * currency's minor units) and optionally unit_minor.
		 *
		 * @param array $lines   Lines so far.
		 * @param array $context trip_id, option, pax, pax_total, extras, subtotal_minor, decimals, date, input.
		 */
		$lines = apply_filters(
			'wpitravel_quote_lines',
			array(),
			array(
				'trip_id'        => $trip->ID,
				'option'         => $option,
				'pax'            => $pax,
				'pax_total'      => $pax_total,
				'extras'         => $extras,
				'subtotal_minor' => $subtotal,
				'decimals'       => $decimals,
				'date'           => $slot['date'],
				'input'          => $input,
			)
		);

		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$reduction = 0;

		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['total_minor'] ) ) {
				continue;
			}

			$total    = (int) $line['total_minor'];
			$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );

			if ( $total >= 0 ) {
				$subtotal += $total;
			} else {
				$reduction += -$total;
			}

			$items[] = array(
				'type'        => sanitize_key( (string) ( $line['type'] ?? 'extra' ) ),
				'key'         => sanitize_key( (string) ( $line['key'] ?? '' ) ),
				'label'       => sanitize_text_field( (string) ( $line['label'] ?? '' ) ),
				'quantity'    => $quantity,
				'unit_amount' => Money::from_minor( (int) ( $line['unit_minor'] ?? intdiv( $total, $quantity ) ), $decimals ),
				'total'       => Money::from_minor( $total, $decimals ),
			);
		}

		// Reductions never take the price below zero.
		$reduction = min( $reduction, $subtotal );

		$messages = array();
		$discount = $reduction;
		$coupon   = null;
		$code     = Coupons::normalize_code( (string) ( $input['coupon'] ?? '' ) );

		if ( '' !== $code ) {
			$result = $this->coupons->evaluate( $code, $trip->ID, $subtotal - $reduction, $decimals, (string) $context['email'] );

			if ( is_wp_error( $result ) ) {
				if ( $context['strict_coupon'] ) {
					return $result;
				}

				$messages[] = array(
					'type'    => 'coupon',
					'message' => $result->get_error_message(),
				);
			} else {
				$coupon    = $result['coupon'];
				$discount += $result['discount_minor'];
				$items[]   = array(
					'type'        => 'discount',
					'key'         => $coupon['code'],
					'label'       => sprintf(
						/* translators: %s: coupon code. */
						__( 'Coupon %s', 'wpinterface-travel' ),
						$coupon['code']
					),
					'quantity'    => 1,
					'unit_amount' => Money::from_minor( -$result['discount_minor'], $decimals ),
					'total'       => Money::from_minor( -$result['discount_minor'], $decimals ),
				);
			}
		}

		$tax       = self::tax( $subtotal - $discount );
		$total     = $subtotal - $discount + ( $tax['included'] ? 0 : $tax['amount'] );
		$tax_label = $tax['label'];

		if ( $tax['amount'] > 0 ) {
			$items[] = array(
				'type'        => 'tax',
				'key'         => 'tax',
				'label'       => $tax['included']
					/* translators: %s: tax label with rate, e.g. "VAT 13%". */
					? sprintf( __( 'Includes %s', 'wpinterface-travel' ), $tax_label )
					: $tax_label,
				'quantity'    => 1,
				'unit_amount' => Money::from_minor( $tax['amount'], $decimals ),
				'total'       => Money::from_minor( $tax['amount'], $decimals ),
				'included'    => $tax['included'],
			);
		}

		$quote = array(
			'trip_id'            => $trip->ID,
			'trip_title'         => get_the_title( $trip ),
			'date'               => $slot['date'],
			'end_date'           => $slot['end_date'],
			'time'               => $slot['time'],
			'departure_id'       => $slot['departure_id'],
			'source'             => $slot['source'],
			'seats_left'         => $slot['seats_left'],
			'price_option'       => $option['key'],
			'price_option_label' => $option['label'],
			'pax'                => $pax,
			'pax_total'          => $pax_total,
			'extras'             => $extras,
			'currency'           => (string) wpitravel()->options()->get( 'currency.code', 'USD' ),
			'items'              => $items,
			'subtotal'           => Money::from_minor( $subtotal, $decimals ),
			'discount_total'     => Money::from_minor( $discount, $decimals ),
			'tax_total'          => Money::from_minor( $tax['amount'], $decimals ),
			'tax_included'       => $tax['included'],
			'total'              => Money::from_minor( $total, $decimals ),
			'coupon'             => $coupon ? $coupon['code'] : null,
			'coupon_id'          => $coupon ? $coupon['id'] : 0,
			'deposit'            => null,
			'messages'           => $messages,
		);

		/**
		 * Filters a quote, e.g. to add extras or a deposit (Pro).
		 *
		 * @param array $quote Quote.
		 * @param array $input Selection.
		 */
		return (array) apply_filters( 'wpitravel_quote', $quote, $input );
	}

	/**
	 * The quote fields customers may see (no internal IDs).
	 *
	 * @param array<string, mixed> $quote Quote.
	 * @return array<string, mixed>
	 */
	public static function to_public( array $quote ): array {
		unset( $quote['coupon_id'], $quote['source'] );

		return $quote;
	}

	/**
	 * The requested option, or the only option when none was requested.
	 *
	 * @param array<string, mixed> $pricing Pricing.
	 * @param string               $key     Requested key.
	 * @return array<string, mixed>|null
	 */
	private static function pick_option( array $pricing, string $key ): ?array {
		if ( '' === $key && 1 === count( $pricing['options'] ) ) {
			return $pricing['options'][0];
		}

		return Pricing::option( $pricing, sanitize_key( $key ) );
	}

	/**
	 * The slot at the requested time.
	 *
	 * @param list<array<string, mixed>> $slots Slots of one date.
	 * @param string                     $time  HH:MM or ''.
	 * @return array<string, mixed>|null
	 */
	private static function find_slot( array $slots, string $time ): ?array {
		foreach ( $slots as $slot ) {
			if ( $slot['time'] === $time ) {
				return $slot;
			}
		}

		return null;
	}

	/**
	 * Traveler counts per category, checked against the option's limits.
	 *
	 * @param array<string, mixed> $option Price option.
	 * @param array<mixed>         $raw    Requested counts.
	 * @return array<string, int>|WP_Error
	 */
	private static function validate_pax( array $option, array $raw ) {
		$known = array_column( $option['categories'], null, 'key' );
		$pax   = array();

		foreach ( $raw as $key => $count ) {
			$count = absint( $count );

			if ( $count > 0 && ! isset( $known[ $key ] ) ) {
				return self::error( 'wpitravel_pax_out_of_range', __( 'Unknown traveler type.', 'wpinterface-travel' ), 400 );
			}
		}

		foreach ( $known as $key => $category ) {
			$count = absint( $raw[ $key ] ?? 0 );

			if ( $count < $category['min'] || ( $category['max'] > 0 && $count > $category['max'] ) ) {
				return self::error(
					'wpitravel_pax_out_of_range',
					$category['max'] > 0
						/* translators: 1: traveler type, 2: minimum, 3: maximum. */
						? sprintf( __( '%1$s: choose between %2$d and %3$d.', 'wpinterface-travel' ), $category['label'], $category['min'], $category['max'] )
						/* translators: 1: traveler type, 2: minimum. */
						: sprintf( __( '%1$s: choose at least %2$d.', 'wpinterface-travel' ), $category['label'], $category['min'] ),
					400,
					array( 'field' => $key )
				);
			}

			$pax[ $key ] = $count;
		}

		$total = array_sum( $pax );

		if ( $total < max( 1, $option['min_pax'] ) ) {
			return self::error(
				'wpitravel_pax_out_of_range',
				/* translators: %d: minimum travelers. */
				sprintf( _n( 'This option needs at least %d traveler.', 'This option needs at least %d travelers.', max( 1, $option['min_pax'] ), 'wpinterface-travel' ), max( 1, $option['min_pax'] ) ),
				400
			);
		}

		if ( $option['max_pax'] > 0 && $total > $option['max_pax'] ) {
			return self::error(
				'wpitravel_pax_out_of_range',
				/* translators: %d: maximum travelers. */
				sprintf( _n( 'This option allows at most %d traveler.', 'This option allows at most %d travelers.', $option['max_pax'], 'wpinterface-travel' ), $option['max_pax'] ),
				400
			);
		}

		return $pax;
	}

	/**
	 * Tax on an amount, using the tax settings.
	 *
	 * @param int $amount_minor Amount after discount, in minor units.
	 * @return array{amount: int, included: bool, label: string}
	 */
	private static function tax( int $amount_minor ): array {
		$settings = (array) wpitravel()->options()->get( 'tax', array() );
		$rate     = (float) ( $settings['rate'] ?? 0 );
		$included = ! empty( $settings['prices_include_tax'] );
		$label    = sprintf( '%s %s%%', (string) ( $settings['label'] ?? 'Tax' ), rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) );

		if ( empty( $settings['enabled'] ) || $rate <= 0 || $amount_minor <= 0 ) {
			return array(
				'amount'   => 0,
				'included' => $included,
				'label'    => $label,
			);
		}

		$amount = $included
			? $amount_minor - (int) round( $amount_minor / ( 1 + $rate / 100 ) )
			: (int) round( $amount_minor * $rate / 100 );

		return array(
			'amount'   => $amount,
			'included' => $included,
			'label'    => $label,
		);
	}

	/**
	 * An error with an HTTP status.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra data.
	 */
	private static function error( string $code, string $message, int $status, array $data = array() ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) + $data );
	}
}
