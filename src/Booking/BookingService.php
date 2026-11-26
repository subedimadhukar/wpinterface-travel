<?php
/**
 * Booking creation and status changes.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WP_Error;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Emails\Templates;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Db;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a seat hold into a booking, and changes booking status.
 *
 * Creating a booking happens in one transaction: lock the departure, check
 * seats again, count the coupon use, save the customer, booking, items and
 * travelers, add the seats to the departure and delete the hold.
 */
final class BookingService {

	/**
	 * Bookings.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Holds.
	 *
	 * @var Holds
	 */
	private Holds $holds;

	/**
	 * Coupons.
	 *
	 * @var Coupons
	 */
	private Coupons $coupons;

	/**
	 * Quote calculator.
	 *
	 * @var QuoteCalculator
	 */
	private QuoteCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository|null $bookings   Bookings.
	 * @param Holds|null             $holds      Holds.
	 * @param Coupons|null           $coupons    Coupons.
	 * @param QuoteCalculator|null   $calculator Quote calculator.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?Holds $holds = null, ?Coupons $coupons = null, ?QuoteCalculator $calculator = null ) {
		$this->bookings   = $bookings ?? new BookingRepository();
		$this->holds      = $holds ?? new Holds();
		$this->coupons    = $coupons ?? new Coupons();
		$this->calculator = $calculator ?? new QuoteCalculator( null, $this->coupons );
	}

	/**
	 * Creates a booking from a hold.
	 *
	 * @param array<string, mixed> $input   hold_key, customer{email, first_name, last_name, phone, country},
	 *                                      travelers[], coupon, note, create_account, terms_accepted.
	 * @param int                  $user_id Logged-in user, or 0.
	 * @param string               $ip      Customer IP.
	 * @param int                  $staff_id Staff member entering the booking by hand (0 for a customer
	 *                                       booking). Staff bookings skip the guest, terms and payment
	 *                                       method checks, which are the customer's to answer.
	 * @return array<string, mixed>|WP_Error The new booking.
	 */
	public function create( array $input, int $user_id, string $ip = '', int $staff_id = 0 ) {
		$options = wpitravel()->options();
		$manual  = $staff_id > 0;

		if ( ! $manual && 0 === $user_id && ! $options->get( 'booking.guest_checkout', true ) ) {
			return new WP_Error( 'wpitravel_login_required', __( 'Log in to book this trip.', 'wpinterface-travel' ), array( 'status' => 401 ) );
		}

		if ( ! $manual && (int) $options->get( 'booking.terms_page_id', 0 ) > 0 && empty( $input['terms_accepted'] ) ) {
			return new WP_Error( 'wpitravel_terms_required', __( 'Accept the terms and conditions to book.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$hold = $this->holds->find( (string) ( $input['hold_key'] ?? '' ) );

		if ( ! $hold ) {
			return new WP_Error( 'wpitravel_hold_expired', __( 'Your reserved seats have expired. Choose your date again.', 'wpinterface-travel' ), array( 'status' => 410 ) );
		}

		$customer = self::clean_customer( (array) ( $input['customer'] ?? array() ) );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$departure = ( new DepartureRepository() )->find( $hold['departure_id'] );

		if ( ! $departure ) {
			return new WP_Error( 'wpitravel_date_unavailable', __( 'This date is not available. Choose another date.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$quote = $this->calculator->calculate(
			array(
				'trip_id'      => $hold['trip_id'],
				'date'         => $departure['start_date'],
				'time'         => $departure['start_time'],
				'price_option' => (string) ( $hold['selection']['price_option'] ?? '' ),
				'pax'          => (array) ( $hold['selection']['pax'] ?? array() ),
				'extras'       => (array) ( $hold['selection']['extras'] ?? array() ),
				'coupon'       => (string) ( $input['coupon'] ?? '' ),
			),
			array(
				// The hold already owns the seats; it may also outlive the cut-off.
				'check_seats'   => false,
				'statuses'      => array( 'open', 'sold_out', 'past_cutoff' ),
				'email'         => $customer['email'],
				'strict_coupon' => true,
			)
		);

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$gateway = $manual ? sanitize_key( (string) ( $input['gateway'] ?? '' ) ) : self::check_gateway( (string) ( $input['gateway'] ?? '' ), $quote );

		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$input['gateway'] = $gateway;

		$travelers = self::clean_travelers( (array) ( $input['travelers'] ?? array() ), $quote['pax'], $customer );

		if ( is_wp_error( $travelers ) ) {
			return $travelers;
		}

		/**
		 * Lets Pro and add-ons check extra checkout answers (`fields`) before
		 * any seats are taken. Return a WP_Error to refuse the booking.
		 *
		 * @param true|WP_Error $valid True to allow.
		 * @param array         $input Booking input (customer, fields…).
		 * @param array         $quote Quote it will be created from.
		 */
		$valid = apply_filters( 'wpitravel_validate_booking', true, $input, $quote );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$input['staff_id'] = $staff_id;

		$booking_id = Db::transaction(
			function () use ( $hold, $quote, $customer, $travelers, $user_id, $ip, $input ) {
				return $this->write_booking( $hold, $quote, $customer, $travelers, $user_id, $ip, $input );
			}
		);

		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		if ( ! $manual && 0 === $user_id && ! empty( $input['create_account'] ) && $options->get( 'booking.create_accounts', false ) ) {
			$this->create_account( (int) $booking_id, $customer );
		}

		/**
		 * Fires after a booking was created (emails, integrations).
		 *
		 * @param int   $booking_id Booking ID.
		 * @param array $quote      Quote it was created from.
		 * @param array $input      Booking input (customer, fields…).
		 */
		do_action( 'wpitravel_booking_created', (int) $booking_id, $quote, $input );

		return (array) $this->bookings->find( (int) $booking_id );
	}

	/**
	 * A booking entered by staff (phone, email or walk-in customers).
	 *
	 * Prices and seats are checked as for a customer, but staff may book a
	 * date past its cut-off. The booking can start confirmed, and the
	 * customer emails can be left out.
	 *
	 * @param array<string, mixed> $input    trip_id, date, time, price_option, pax, customer, travelers,
	 *                                       coupon, note, status (pending|confirmed), notify (bool),
	 *                                       payment_method.
	 * @param int                  $staff_id Staff user.
	 * @return array<string, mixed>|WP_Error The new booking.
	 */
	public function create_manual( array $input, int $staff_id ) {
		$customer = self::clean_customer( (array) ( $input['customer'] ?? array() ) );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$quote = $this->calculator->calculate(
			array(
				'trip_id'      => $input['trip_id'] ?? 0,
				'date'         => $input['date'] ?? '',
				'time'         => $input['time'] ?? '',
				'price_option' => $input['price_option'] ?? '',
				'pax'          => (array) ( $input['pax'] ?? array() ),
				'extras'       => (array) ( $input['extras'] ?? array() ),
				'coupon'       => (string) ( $input['coupon'] ?? '' ),
			),
			array(
				'statuses'      => array( 'open', 'past_cutoff' ),
				'email'         => $customer['email'],
				'strict_coupon' => true,
			)
		);

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$hold = $this->holds->create( $quote );

		if ( is_wp_error( $hold ) ) {
			return $hold;
		}

		$notify = ! isset( $input['notify'] ) || (bool) $input['notify'];
		$mute   = static fn( bool $send, string $template ): bool => $send && 'customer' !== ( Templates::get( $template )['recipient'] ?? '' );

		if ( ! $notify ) {
			add_filter( 'wpitravel_send_booking_email', $mute, 10, 2 );
		}

		$booking = $this->create(
			array(
				'hold_key'  => $hold['hold_key'],
				'customer'  => $customer,
				'travelers' => (array) ( $input['travelers'] ?? array() ),
				'coupon'    => (string) ( $input['coupon'] ?? '' ),
				'note'      => (string) ( $input['note'] ?? '' ),
				'gateway'   => (string) ( $input['payment_method'] ?? '' ),
				'fields'    => (array) ( $input['fields'] ?? array() ),
			),
			0,
			'',
			$staff_id
		);

		if ( ! is_wp_error( $booking ) && 'confirmed' === ( $input['status'] ?? '' ) ) {
			$booking = $this->set_status( (int) $booking['id'], 'confirmed', __( 'Confirmed when entered.', 'wpinterface-travel' ) );
		}

		if ( ! $notify ) {
			remove_filter( 'wpitravel_send_booking_email', $mute, 10 );
		}

		if ( is_wp_error( $booking ) ) {
			$this->holds->release( $hold['hold_key'] );
		}

		return $booking;
	}

	/**
	 * Changes a booking's status. Cancelling or failing a booking gives its
	 * seats and coupon use back; reopening it takes them again.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $status     New status.
	 * @param string $note       Reason, for the history.
	 * @return array<string, mixed>|WP_Error Updated booking.
	 */
	public function set_status( int $booking_id, string $status, string $note = '' ) {
		if ( ! in_array( $status, BookingRepository::STATUSES, true ) ) {
			return new WP_Error( 'wpitravel_invalid_status', __( 'Unknown booking status.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$result = Db::transaction(
			function () use ( $booking_id, $status, $note ) {
				global $wpdb;

				$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Schema::table( 'bookings' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; row lock.

				if ( ! is_array( $booking ) ) {
					return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
				}

				$old = (string) $booking['status'];

				if ( $old === $status ) {
					return $old;
				}

				$was_released = in_array( $old, BookingRepository::RELEASED_STATUSES, true );
				$is_released  = in_array( $status, BookingRepository::RELEASED_STATUSES, true );
				$pax          = (int) $booking['pax'];
				$departure_id = (int) $booking['departure_id'];

				if ( ! $was_released && $is_released ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET seats_booked = GREATEST(seats_booked - %d, 0) WHERE id = %d', Schema::table( 'departures' ), $pax, $departure_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

					if ( '' !== $booking['coupon_code'] ) {
						$this->coupons->release_once( (string) $booking['coupon_code'] );
					}
				}

				if ( $was_released && ! $is_released ) {
					$check = $this->holds->lock_and_check( $departure_id, (int) $booking['trip_id'], $pax );

					if ( is_wp_error( $check ) ) {
						return $check;
					}

					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET seats_booked = seats_booked + %d WHERE id = %d', Schema::table( 'departures' ), $pax, $departure_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

					// Take the coupon use back too. Staff may reopen even when the
					// coupon has since run out; the history says so.
					$coupon = '' !== $booking['coupon_code'] ? $this->coupons->find_by_code( (string) $booking['coupon_code'] ) : null;

					if ( $coupon && ! $this->coupons->use_once( (int) $coupon['id'] ) ) {
						$this->bookings->log( $booking_id, 'coupon_over_limit', __( 'Reopened although the coupon has reached its usage limit.', 'wpinterface-travel' ) );
					}
				}

				$this->bookings->update( $booking_id, array( 'status' => $status ) );
				$this->bookings->log(
					$booking_id,
					'status_changed',
					$note,
					array(
						'from' => $old,
						'to'   => $status,
					)
				);

				return $old;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result !== $status ) {
			/**
			 * Fires after a booking's status changed.
			 *
			 * @param int    $booking_id Booking ID.
			 * @param string $status     New status.
			 * @param string $old        Previous status.
			 */
			do_action( 'wpitravel_booking_status_changed', $booking_id, $status, (string) $result );
		}

		return (array) $this->bookings->find( $booking_id );
	}

	/**
	 * Writes the booking. Runs inside a transaction.
	 *
	 * @param array<string, mixed>       $hold      Hold.
	 * @param array<string, mixed>       $quote     Quote.
	 * @param array<string, string>      $customer  Customer.
	 * @param list<array<string, mixed>> $travelers Travelers.
	 * @param int                        $user_id   User or 0.
	 * @param string                     $ip        IP.
	 * @param array<string, mixed>       $input     Raw input (note).
	 * @return int|WP_Error Booking ID.
	 */
	private function write_booking( array $hold, array $quote, array $customer, array $travelers, int $user_id, string $ip, array $input ) {
		global $wpdb;

		// The hold row must still exist: a parallel request may have used it.
		$still_held = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND expires_at > %s FOR UPDATE', Schema::table( 'holds' ), $hold['id'], Db::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; row lock.

		if ( ! $still_held ) {
			return new WP_Error( 'wpitravel_hold_expired', __( 'Your reserved seats have expired. Choose your date again.', 'wpinterface-travel' ), array( 'status' => 410 ) );
		}

		$check = $this->holds->lock_and_check( $hold['departure_id'], $hold['trip_id'], (int) $quote['pax_total'], $hold['id'] );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( $quote['coupon_id'] ) {
			if ( ! $this->coupons->use_once( (int) $quote['coupon_id'] ) ) {
				return new WP_Error( 'wpitravel_invalid_coupon', __( 'This coupon has been used up.', 'wpinterface-travel' ), array( 'status' => 400 ) );
			}

			// The coupon row is now locked until commit, so parallel bookings by
			// the same customer wait here and see each other's bookings.
			$coupon = $this->coupons->find( (int) $quote['coupon_id'] );

			if ( $coupon && null !== $coupon['per_customer_limit'] && $this->coupons->customer_uses( $coupon['code'], $customer['email'] ) >= $coupon['per_customer_limit'] ) {
				return new WP_Error( 'wpitravel_invalid_coupon', __( 'You have already used this coupon.', 'wpinterface-travel' ), array( 'status' => 400 ) );
			}
		}

		// The customer record joins the account only when the account's email
		// is the one typed at checkout, so nobody can claim someone else's.
		$account     = $user_id > 0 ? get_userdata( $user_id ) : false;
		$owner_id    = $account && strtolower( (string) $account->user_email ) === strtolower( $customer['email'] ) ? $user_id : 0;
		$customer_id = $this->bookings->upsert_customer( $customer, $owner_id );
		$now         = Db::now();
		$booking_id  = $this->bookings->insert(
			'bookings',
			array(
				'booking_key'    => Db::random_key(),
				'trip_id'        => $quote['trip_id'],
				'departure_id'   => $hold['departure_id'],
				'start_date'     => $quote['date'],
				'start_time'     => $quote['time'],
				'price_option'   => $quote['price_option'],
				'payment_method' => (string) ( $input['gateway'] ?? '' ),
				'customer_id'    => $customer_id,
				'user_id'        => $user_id,
				'status'         => 'pending',
				'payment_status' => Money::to_minor( $quote['total'] ) > 0 ? 'unpaid' : 'paid',
				'currency'       => $quote['currency'],
				'subtotal'       => $quote['subtotal'],
				'discount_total' => $quote['discount_total'],
				'tax_total'      => $quote['tax_total'],
				'total'          => $quote['total'],
				'amount_paid'    => '0',
				'pax'            => $quote['pax_total'],
				'coupon_code'    => (string) $quote['coupon'],
				'customer_note'  => sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ),
				'locale'         => determine_locale(),
				'ip'             => $ip,
				'created'        => $now,
				'updated'        => $now,
			)
		);

		if ( ! $booking_id ) {
			return new WP_Error( 'wpitravel_booking_failed', __( 'The booking could not be saved. Try again.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		foreach ( $quote['items'] as $item ) {
			$this->bookings->insert(
				'booking_items',
				array(
					'booking_id'  => $booking_id,
					'item_type'   => $item['type'],
					'object_key'  => $item['key'],
					'label'       => $item['label'],
					'quantity'    => $item['quantity'],
					'unit_amount' => $item['unit_amount'],
					'total'       => $item['total'],
					'meta'        => wp_json_encode(
						array(
							'price_option'       => $quote['price_option'],
							'price_option_label' => $quote['price_option_label'],
							'included'           => ! empty( $item['included'] ),
						)
					),
				)
			);
		}

		foreach ( $travelers as $traveler ) {
			$this->bookings->insert( 'travelers', array( 'booking_id' => $booking_id ) + $traveler );
		}

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET seats_booked = seats_booked + %d, updated = %s WHERE id = %d', Schema::table( 'departures' ), (int) $quote['pax_total'], $now, $hold['departure_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->delete( Schema::table( 'holds' ), array( 'id' => $hold['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		$staff_id = (int) ( $input['staff_id'] ?? 0 );

		$this->bookings->log(
			$booking_id,
			'created',
			$staff_id > 0 ? __( 'Booking entered by staff.', 'wpinterface-travel' ) : __( 'Booking created.', 'wpinterface-travel' ),
			array_filter(
				array(
					'total'    => $quote['total'],
					'staff_id' => $staff_id,
				)
			)
		);

		return $booking_id;
	}

	/**
	 * Creates a WordPress account for a guest and links the booking. Only
	 * when the site turned on "Offer to create an account" (off by default).
	 * The role is `subscriber`, changeable with the `wpitravel_customer_role`
	 * filter.
	 * Skipped when the email already has an account (the guest must log in).
	 *
	 * @param int                   $booking_id Booking ID.
	 * @param array<string, string> $customer   Customer.
	 */
	private function create_account( int $booking_id, array $customer ): void {
		if ( email_exists( $customer['email'] ) ) {
			return;
		}

		$base     = sanitize_user( (string) strstr( $customer['email'], '@', true ), true );
		$base     = '' !== $base ? $base : 'traveler';
		$username = $base;
		$suffix   = 2;

		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			++$suffix;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $customer['email'],
				'user_pass'  => wp_generate_password( 24 ),
				'first_name' => $customer['first_name'],
				'last_name'  => $customer['last_name'],
				/**
				 * Filters the role of accounts created at checkout. Always the
				 * lowest role by default, whatever the site's default role is.
				 *
				 * @param string $role Role.
				 */
				'role'       => (string) apply_filters( 'wpitravel_customer_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		global $wpdb;

		$this->bookings->update( $booking_id, array( 'user_id' => $user_id ) );
		$wpdb->update( Schema::table( 'customers' ), array( 'user_id' => $user_id ), array( 'email' => strtolower( $customer['email'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$this->bookings->log( $booking_id, 'account_created', __( 'Customer account created.', 'wpinterface-travel' ), array( 'user_id' => $user_id ) );

		// Sends the "set your password" email.
		wp_new_user_notification( $user_id, null, 'user' );
	}

	/**
	 * The chosen payment method, checked before any seats are taken.
	 *
	 * Free bookings need none. When no method is turned on at all, bookings
	 * are taken without one and staff arrange payment.
	 *
	 * @param string               $gateway_id Chosen gateway.
	 * @param array<string, mixed> $quote      Quote.
	 * @return string|WP_Error Gateway ID ('' for none).
	 */
	private static function check_gateway( string $gateway_id, array $quote ) {
		$gateway_id = sanitize_key( $gateway_id );

		if ( Money::to_minor( $quote['total'] ) <= 0 || ! Gateways::any_enabled() ) {
			return '';
		}

		if ( ! isset( Gateways::available( (string) $quote['currency'] )[ $gateway_id ] ) ) {
			return new WP_Error( 'wpitravel_gateway_unavailable', __( 'Choose one of the available payment methods.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		return $gateway_id;
	}

	/**
	 * Validates the contact person.
	 *
	 * @param array<string, mixed> $raw Raw fields.
	 * @return array<string, string>|WP_Error
	 */
	private static function clean_customer( array $raw ) {
		$customer = array(
			'email'      => sanitize_email( (string) ( $raw['email'] ?? '' ) ),
			'first_name' => sanitize_text_field( (string) ( $raw['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $raw['last_name'] ?? '' ) ),
			'phone'      => substr( sanitize_text_field( (string) ( $raw['phone'] ?? '' ) ), 0, 50 ),
			'country'    => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $raw['country'] ?? '' ) ), 0, 2 ) ),
		);

		$fields = array(
			'email'      => __( 'Enter a valid email address.', 'wpinterface-travel' ),
			'first_name' => __( 'Enter your first name.', 'wpinterface-travel' ),
			'last_name'  => __( 'Enter your last name.', 'wpinterface-travel' ),
		);

		foreach ( $fields as $field => $message ) {
			if ( '' === $customer[ $field ] || ( 'email' === $field && ! is_email( $customer['email'] ) ) ) {
				return new WP_Error(
					'wpitravel_invalid_customer',
					$message,
					array(
						'status' => 400,
						'field'  => 'customer.' . $field,
					)
				);
			}
		}

		return $customer;
	}

	/**
	 * Validates traveler details. Optional: when none are given, the contact
	 * person is saved as the lead traveler.
	 *
	 * @param array<mixed>          $raw      Raw travelers.
	 * @param array<string, int>    $pax      Counts per category.
	 * @param array<string, string> $customer Contact person.
	 * @return list<array<string, mixed>>|WP_Error
	 */
	private static function clean_travelers( array $raw, array $pax, array $customer ) {
		$remaining = $pax;
		$travelers = array();

		foreach ( $raw as $index => $traveler ) {
			if ( ! is_array( $traveler ) ) {
				continue;
			}

			$first = sanitize_text_field( (string) ( $traveler['first_name'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $traveler['last_name'] ?? '' ) );

			if ( '' === $first && '' === $last ) {
				continue;
			}

			$category = sanitize_key( (string) ( $traveler['category'] ?? '' ) );

			if ( '' === $category ) {
				$category = (string) key( array_filter( $remaining ) );
			}

			if ( empty( $remaining[ $category ] ) ) {
				return new WP_Error( 'wpitravel_invalid_traveler_count', __( 'There are more traveler names than travelers.', 'wpinterface-travel' ), array( 'status' => 400 ) );
			}

			--$remaining[ $category ];

			$travelers[] = array(
				'category'   => $category,
				'is_lead'    => 0,
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => sanitize_email( (string) ( $traveler['email'] ?? '' ) ),
				'phone'      => substr( sanitize_text_field( (string) ( $traveler['phone'] ?? '' ) ), 0, 50 ),
				'meta'       => null,
			);
		}

		if ( empty( $travelers ) ) {
			$travelers[] = array(
				'category'   => (string) key( array_filter( $pax ) ),
				'is_lead'    => 1,
				'first_name' => $customer['first_name'],
				'last_name'  => $customer['last_name'],
				'email'      => $customer['email'],
				'phone'      => $customer['phone'],
				'meta'       => null,
			);
		} else {
			$travelers[0]['is_lead'] = 1;
		}

		return $travelers;
	}
}
