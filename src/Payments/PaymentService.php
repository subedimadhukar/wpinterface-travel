<?php
/**
 * Payments and refunds.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments;

use WP_Error;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Db;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Starts payments, records completed payments and refunds, and keeps each
 * booking's amount paid and payment status in step with its ledger.
 *
 * Rules:
 * - Money only counts once the provider confirmed it (return capture or a
 *   verified webhook) or staff recorded it.
 * - Completing is idempotent: a payment completes once, and a provider
 *   transaction ID can only exist once per gateway.
 * - A payment in another currency than the booking is rejected.
 * - A booking is confirmed automatically when fully paid
 *   (Settings → Payments → "Confirm bookings when fully paid").
 */
final class PaymentService {

	/**
	 * Ledger.
	 *
	 * @var PaymentRepository
	 */
	private PaymentRepository $payments;

	/**
	 * Bookings.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Constructor.
	 *
	 * @param PaymentRepository|null $payments Ledger.
	 * @param BookingRepository|null $bookings Bookings.
	 */
	public function __construct( ?PaymentRepository $payments = null, ?BookingRepository $bookings = null ) {
		$this->payments = $payments ?? new PaymentRepository();
		$this->bookings = $bookings ?? new BookingRepository();
	}

	/**
	 * Starts paying the balance of a booking with a gateway.
	 *
	 * @param array<string, mixed> $booking    Booking.
	 * @param string               $gateway_id Gateway ID.
	 * @return array<string, mixed>|WP_Error Payment action for the browser.
	 */
	public function start( array $booking, string $gateway_id ) {
		$decimals = Currencies::decimals( (string) $booking['currency'] );

		if ( Money::to_minor( $booking['balance'], $decimals ) <= 0 ) {
			return array(
				'action'       => 'none',
				'instructions' => '',
			);
		}

		if ( in_array( $booking['status'], BookingRepository::RELEASED_STATUSES, true ) ) {
			return new WP_Error( 'wpitravel_booking_closed', __( 'This booking was cancelled and can no longer be paid.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$gateway = Gateways::get( $gateway_id );

		if ( ! $gateway || ! $gateway->is_available( (string) $booking['currency'] ) ) {
			return new WP_Error( 'wpitravel_gateway_unavailable', __( 'Choose one of the available payment methods.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		if ( $booking['payment_method'] !== $gateway_id ) {
			$this->bookings->update( (int) $booking['id'], array( 'payment_method' => $gateway_id ) );
			$booking['payment_method'] = $gateway_id;
		}

		$due    = $this->due( $booking, $gateway_id );
		$group  = $this->group( $booking, $gateway_id );
		$total  = Money::to_minor( $due, $decimals );
		$covers = array();

		foreach ( $group as $member ) {
			$total   += Money::to_minor( $member['due'], $decimals );
			$covers[] = array(
				'booking_id' => (int) $member['booking']['id'],
				'amount'     => $member['due'],
			);

			if ( $member['booking']['payment_method'] !== $gateway_id ) {
				$this->bookings->update( (int) $member['booking']['id'], array( 'payment_method' => $gateway_id ) );
			}
		}

		$booking = array_merge(
			$booking,
			array(
				'amount_due' => Money::from_minor( $total, $decimals ),
				'group'      => array_map( static fn( array $member ): string => (string) $member['booking']['number'], $group ),
			)
		);

		if ( ! $gateway->is_online() ) {
			return $gateway->start( $booking, null );
		}

		$this->cancel_pending( (int) $booking['id'] );

		foreach ( $group as $member ) {
			$this->cancel_pending( (int) $member['booking']['id'] );
		}

		$payment_id = $this->payments->insert(
			array_filter(
				array(
					'booking_id'   => $booking['id'],
					'gateway'      => $gateway_id,
					'amount'       => $booking['amount_due'],
					'currency'     => $booking['currency'],
					'mode'         => $gateway->mode(),
					// One charge for several bookings; complete() gives each its share.
					'gateway_data' => $covers ? array( 'covers' => $covers ) : null,
				),
				static fn( $value ): bool => null !== $value
			)
		);
		$payment    = $this->payments->find( $payment_id );

		if ( ! $payment ) {
			return new WP_Error( 'wpitravel_payment_failed', __( 'The payment could not be started. Try again.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$result = $gateway->start( $booking, $payment );

		if ( is_wp_error( $result ) ) {
			$this->payments->update(
				$payment_id,
				array(
					'status' => 'failed',
					'note'   => substr( $result->get_error_message(), 0, 255 ),
				)
			);
		}

		return $result;
	}

	/**
	 * Marks a pending payment completed with what the provider reported.
	 *
	 * @param int                  $payment_id     Pending payment.
	 * @param string               $transaction_id Provider capture ID.
	 * @param string               $amount         Amount received.
	 * @param string               $currency       Currency received.
	 * @param array<string, mixed> $data           Provider data to keep (no card data).
	 * @return array<string, mixed>|WP_Error The payment.
	 */
	public function complete( int $payment_id, string $transaction_id, string $amount, string $currency, array $data = array() ) {
		// The currency check runs first, outside the transaction, so the rejection is kept.
		$pending = $this->payments->find( $payment_id );
		$booking = $pending ? $this->bookings->find( $pending['booking_id'] ) : null;

		if ( $pending && $booking && 'completed' !== $pending['status'] && strtoupper( $currency ) !== $booking['currency'] ) {
			$this->payments->update(
				$payment_id,
				array(
					'status'       => 'failed',
					'note'         => 'Currency mismatch: received ' . strtoupper( substr( $currency, 0, 3 ) ),
					'gateway_data' => array_merge( $pending['gateway_data'], $data ),
				)
			);
			$this->bookings->log( $booking['id'], 'payment_rejected', __( 'Payment rejected: wrong currency.', 'wpinterface-travel' ), array( 'currency' => $currency ) );

			return new WP_Error( 'wpitravel_payment_currency', __( 'The payment currency does not match the booking.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$result = Db::transaction(
			function () use ( $payment_id, $transaction_id, $amount, $data ) {
				$payment = $this->payments->find( $payment_id, true );

				if ( ! $payment ) {
					return new WP_Error( 'wpitravel_payment_not_found', __( 'Payment not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
				}

				if ( 'completed' === $payment['status'] ) {
					return array( $payment, false );
				}

				$duplicate = $this->payments->find_by_transaction( $payment['gateway'], $transaction_id );

				if ( $duplicate ) {
					return array( $duplicate, false );
				}

				$booking = $this->lock_booking( $payment['booking_id'] );

				if ( ! $booking ) {
					return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
				}

				// The provider says money arrived for a row we had closed (e.g. a
				// delayed capture). The money is real: record it and ask staff to
				// refund it or reopen the booking, rather than lose track of it.
				$late = 'pending' !== $payment['status'];

				$decimals = Currencies::decimals( $booking['currency'] );
				$received = Money::normalize( $amount, $decimals );

				$updated = $this->payments->update(
					$payment_id,
					array(
						'status'         => 'completed',
						'transaction_id' => $transaction_id,
						'amount'         => $received,
						'gateway_data'   => array_merge( $payment['gateway_data'], $data ),
					)
				);

				if ( ! $updated ) {
					return new WP_Error( 'wpitravel_payment_failed', __( 'The payment could not be saved.', 'wpinterface-travel' ), array( 'status' => 500 ) );
				}

				$members = $this->split( $payment, $booking, $received, $decimals );

				$this->recalculate( $booking['id'] );

				if ( $late || in_array( $booking['status'], BookingRepository::RELEASED_STATUSES, true ) ) {
					$this->bookings->log(
						$booking['id'],
						'payment_needs_review',
						__( 'Money was received after this payment or booking was closed. Refund it, or reopen the booking.', 'wpinterface-travel' ),
						array( 'payment_id' => $payment_id )
					);
				}

				$this->bookings->log(
					$booking['id'],
					'payment_completed',
					sprintf(
						/* translators: 1: amount, 2: currency, 3: payment method. */
						__( 'Payment of %1$s %2$s received via %3$s.', 'wpinterface-travel' ),
						$received,
						$booking['currency'],
						$payment['gateway']
					),
					array(
						'payment_id'     => $payment_id,
						'transaction_id' => $transaction_id,
						'expected'       => $payment['amount'],
					)
				);

				return array( $this->payments->find( $payment_id ), true, $members );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $payment, $is_new ) = $result;

		if ( $is_new ) {
			$this->after_payment( (int) $payment['booking_id'], $payment );

			foreach ( (array) ( $result[2] ?? array() ) as $member_payment ) {
				$this->after_payment( (int) $member_payment['booking_id'], $member_payment );
			}
		}

		return (array) $payment;
	}

	/**
	 * Marks a pending payment failed or cancelled (no money moved).
	 *
	 * @param int    $payment_id Payment.
	 * @param string $status     failed or cancelled.
	 * @param string $note       Reason.
	 */
	public function close_pending( int $payment_id, string $status, string $note = '' ): void {
		$payment = $this->payments->find( $payment_id );

		if ( $payment && 'pending' === $payment['status'] && in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			$this->payments->update(
				$payment_id,
				array(
					'status' => $status,
					'note'   => substr( $note, 0, 255 ),
				)
			);
		}
	}

	/**
	 * Records money received outside a gateway (cash, bank transfer) or by
	 * staff for any method.
	 *
	 * @param int    $booking_id     Booking.
	 * @param string $amount         Amount.
	 * @param string $method         Gateway ID or "manual".
	 * @param string $note           Note.
	 * @param string $transaction_id Optional bank or provider reference.
	 * @return array<string, mixed>|WP_Error The payment.
	 */
	public function record_manual( int $booking_id, string $amount, string $method, string $note = '', string $transaction_id = '' ) {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$decimals = Currencies::decimals( $booking['currency'] );
		$amount   = Money::normalize( $amount, $decimals );

		if ( '' === $amount || Money::to_minor( $amount, $decimals ) <= 0 ) {
			return new WP_Error( 'wpitravel_invalid_amount', __( 'Enter an amount above 0.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$method     = '' !== sanitize_key( $method ) ? sanitize_key( $method ) : 'manual';
		$payment_id = $this->payments->insert(
			array(
				'booking_id'     => $booking_id,
				'gateway'        => $method,
				'transaction_id' => '' !== $transaction_id ? substr( $transaction_id, 0, 191 ) : null,
				'amount'         => $amount,
				'currency'       => $booking['currency'],
				'status'         => 'completed',
				'note'           => substr( $note, 0, 255 ),
				'gateway_data'   => array( 'recorded_by' => get_current_user_id() ),
			)
		);

		if ( ! $payment_id ) {
			return new WP_Error( 'wpitravel_duplicate_transaction', __( 'A payment with this reference is already recorded.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$this->recalculate( $booking_id );
		$this->bookings->log(
			$booking_id,
			'payment_recorded',
			/* translators: 1: amount, 2: currency. */
			sprintf( __( 'Payment of %1$s %2$s recorded by staff.', 'wpinterface-travel' ), $amount, $booking['currency'] ),
			array(
				'payment_id' => $payment_id,
				'method'     => $method,
			)
		);

		$payment = (array) $this->payments->find( $payment_id );
		$this->after_payment( $booking_id, $payment );

		return $payment;
	}

	/**
	 * Refunds part or all of a completed payment.
	 *
	 * Online payments are refunded through the provider when it supports
	 * refunds; otherwise (or when $manual is true) the refund is only recorded,
	 * for money sent back by other means.
	 *
	 * @param int    $payment_id Payment to refund.
	 * @param string $amount     Amount.
	 * @param string $reason     Reason.
	 * @param bool   $manual     Record only, without calling the provider.
	 * @return array<string, mixed>|WP_Error The refund row.
	 */
	public function refund( int $payment_id, string $amount, string $reason = '', bool $manual = false ) {
		$payment = $this->payments->find( $payment_id );

		if ( ! $payment || 'payment' !== $payment['payment_type'] || 'completed' !== $payment['status'] ) {
			return new WP_Error( 'wpitravel_refund_invalid', __( 'Only completed payments can be refunded.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		$decimals   = Currencies::decimals( $payment['currency'] );
		$amount     = Money::normalize( $amount, $decimals );
		$refundable = Money::to_minor( $payment['amount'], $decimals ) - $this->payments->refunded_from( $payment_id, $decimals );

		if ( '' === $amount || Money::to_minor( $amount, $decimals ) <= 0 || Money::to_minor( $amount, $decimals ) > $refundable ) {
			return new WP_Error(
				'wpitravel_refund_amount',
				sprintf(
					/* translators: %s: refundable amount. */
					__( 'Enter an amount between 0 and %s.', 'wpinterface-travel' ),
					Money::from_minor( $refundable, $decimals )
				),
				array( 'status' => 400 )
			);
		}

		$gateway        = Gateways::get( $payment['gateway'] );
		$transaction_id = null;
		$data           = array( 'refunded_by' => get_current_user_id() );

		// A share of a charge that paid several bookings is refunded through that charge.
		$source = $payment;

		if ( ! empty( $payment['gateway_data']['paid_with'] ) ) {
			$parent = $this->payments->find( (int) $payment['gateway_data']['paid_with'] );
			$source = $parent && 'completed' === $parent['status'] && $parent['gateway'] === $payment['gateway'] ? $parent : $payment;
		}

		if ( ! $manual && $gateway && $gateway->supports_refunds() && null !== $source['transaction_id'] ) {
			$result = $gateway->refund( $source, $amount, $reason );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$transaction_id = $result['transaction_id'];
			$data           = array_merge( $data, (array) ( $result['data'] ?? array() ) );
		} else {
			$data['manual'] = true;
		}

		return $this->record_refund( $payment, $amount, $reason, $transaction_id, $data );
	}

	/**
	 * Records a refund that already happened at the provider (webhook), once.
	 *
	 * @param array<string, mixed> $payment        Refunded payment.
	 * @param string               $amount         Amount.
	 * @param string               $transaction_id Provider refund ID.
	 * @param array<string, mixed> $data           Provider data.
	 * @return array<string, mixed>|WP_Error
	 */
	public function record_provider_refund( array $payment, string $amount, string $transaction_id, array $data = array() ) {
		$existing = $this->payments->find_by_transaction( $payment['gateway'], $transaction_id );

		if ( $existing ) {
			return $existing;
		}

		return $this->record_refund( $payment, $amount, __( 'Refunded at the payment provider.', 'wpinterface-travel' ), $transaction_id, $data );
	}

	/**
	 * Recomputes amount paid and payment status from the ledger.
	 *
	 * @param int $booking_id Booking.
	 */
	public function recalculate( int $booking_id ): void {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$decimals = Currencies::decimals( $booking['currency'] );
		$totals   = $this->payments->totals( $booking_id, $decimals );
		$net      = $totals['paid'] - $totals['refunded'];
		$total    = Money::to_minor( $booking['total'], $decimals );

		if ( $totals['refunded'] > 0 ) {
			$status = $net <= 0 ? 'refunded' : 'partially_refunded';
		} elseif ( $total <= 0 || $net >= $total ) {
			$status = 'paid';
		} elseif ( $net > 0 ) {
			$status = 'partial';
		} else {
			$status = 'unpaid';
		}

		$this->bookings->update(
			$booking_id,
			array(
				'amount_paid'    => Money::from_minor( max( 0, $net ), $decimals ),
				'payment_status' => $status,
			)
		);
	}

	/**
	 * Cancels unpaid bookings whose online payment was never completed, so
	 * an abandoned checkout does not keep seats. Runs hourly.
	 *
	 * @return int Bookings cancelled.
	 */
	public function cancel_abandoned(): int {
		global $wpdb;

		$minutes = (int) wpitravel()->options()->get( 'payments.unpaid_timeout_minutes', 60 );
		$online  = array_keys( array_filter( Gateways::all(), static fn( Gateway $gateway ): bool => $gateway->is_online() ) );

		if ( $minutes <= 0 || empty( $online ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $online ), '%s' ) );
		$before       = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; one %s per gateway is built above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status = 'pending' AND payment_status = 'unpaid' AND created < %s AND payment_method IN ({$placeholders}) LIMIT 200",
				...array_merge( array( Schema::table( 'bookings' ), $before ), $online )
			)
		);
		// phpcs:enable

		$service = new BookingService();

		$cancelled = 0;

		foreach ( (array) $ids as $id ) {
			if ( $this->has_capture_in_progress( (int) $id ) || $this->group_capture_in_progress( (int) $id ) ) {
				continue;
			}

			++$cancelled;
			$this->cancel_pending( (int) $id );
			$service->set_status( (int) $id, 'cancelled', __( 'Cancelled automatically: the online payment was not completed in time.', 'wpinterface-travel' ) );
		}

		return $cancelled;
	}

	/**
	 * Whether a booking paid together with others waits on one of their captures.
	 *
	 * @param int $booking_id Booking.
	 */
	private function group_capture_in_progress( int $booking_id ): bool {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		foreach ( self::group_ids( $booking ) as $id ) {
			if ( $id !== $booking_id && $this->has_capture_in_progress( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What paying this booking now asks for: its own part plus what is due
	 * on bookings paid together with it.
	 *
	 * @param array<string, mixed> $booking    Booking.
	 * @param string               $gateway_id Gateway ('' when not chosen yet).
	 */
	public function amount_due( array $booking, string $gateway_id = '' ): string {
		$decimals = Currencies::decimals( (string) $booking['currency'] );
		$total    = Money::to_minor( $this->due( $booking, $gateway_id ), $decimals );

		foreach ( $this->group( $booking, $gateway_id ) as $member ) {
			$total += Money::to_minor( $member['due'], $decimals );
		}

		return Money::from_minor( $total, $decimals );
	}

	/**
	 * How much a payment for one booking asks for now.
	 *
	 * @param array<string, mixed> $booking    Booking.
	 * @param string               $gateway_id Gateway.
	 */
	private function due( array $booking, string $gateway_id ): string {
		$decimals = Currencies::decimals( (string) $booking['currency'] );

		/**
		 * Filters how much this payment asks for (Pro: a deposit or the next
		 * installment). Kept between 0.01 and the balance.
		 *
		 * @param string $amount     Amount due now (default: the balance).
		 * @param array  $booking    Booking.
		 * @param string $gateway_id Gateway.
		 */
		$due = Money::normalize( (string) apply_filters( 'wpitravel_payment_due', $booking['balance'], $booking, $gateway_id ), $decimals );

		return '' === $due || Money::to_minor( $due, $decimals ) <= 0 || Money::to_minor( $due, $decimals ) > Money::to_minor( $booking['balance'], $decimals ) ? (string) $booking['balance'] : $due;
	}

	/**
	 * IDs of bookings paid together with this one.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return list<int>
	 */
	private static function group_ids( array $booking ): array {
		/**
		 * Filters the other bookings that are paid together with this one,
		 * in one charge (Pro: a multi-trip cart). Bookings that are
		 * cancelled, paid, or in another currency are left out.
		 *
		 * @param int[] $ids     Booking IDs.
		 * @param array $booking Booking being paid.
		 */
		$ids = array_map( 'intval', (array) apply_filters( 'wpitravel_payment_group', array(), $booking ) );

		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 20 );
	}

	/**
	 * Other unpaid bookings to pay with this one, with what each owes now.
	 *
	 * @param array<string, mixed> $booking    Booking being paid.
	 * @param string               $gateway_id Gateway.
	 * @return list<array{booking: array<string, mixed>, due: string}>
	 */
	private function group( array $booking, string $gateway_id ): array {
		$members = array();

		foreach ( self::group_ids( $booking ) as $id ) {
			$member = $id !== (int) $booking['id'] ? $this->bookings->find( $id ) : null;

			if (
				! $member
				|| $member['currency'] !== $booking['currency']
				|| in_array( $member['status'], BookingRepository::RELEASED_STATUSES, true )
				|| Money::to_minor( (string) $member['balance'], Currencies::decimals( (string) $member['currency'] ) ) <= 0
			) {
				continue;
			}

			$members[] = array(
				'booking' => $member,
				'due'     => $this->due( $member, $gateway_id ),
			);
		}

		return $members;
	}

	/**
	 * Gives each booking its share of a charge that paid several (inside
	 * the completing transaction). If a different amount arrived, nothing is
	 * split and staff are asked to record the shares by hand.
	 *
	 * @param array<string, mixed> $payment  The pending row as it was (amount = expected total).
	 * @param array<string, mixed> $booking  Booking the charge was made on.
	 * @param string               $received Amount received.
	 * @param int                  $decimals Currency decimals.
	 * @return list<array<string, mixed>> The members' new payment rows.
	 */
	private function split( array $payment, array $booking, string $received, int $decimals ): array {
		$covers = (array) ( $payment['gateway_data']['covers'] ?? array() );

		if ( ! $covers ) {
			return array();
		}

		$others = 0;

		foreach ( $covers as $cover ) {
			$others += Money::to_minor( (string) ( $cover['amount'] ?? '0' ), $decimals );
		}

		$expected = Money::to_minor( (string) $payment['amount'], $decimals );

		if ( Money::to_minor( $received, $decimals ) !== $expected || $others >= $expected ) {
			$this->bookings->log(
				(int) $booking['id'],
				'payment_needs_review',
				__( 'This payment was for several bookings, but a different amount arrived. Record each booking\'s share by hand.', 'wpinterface-travel' ),
				array( 'payment_id' => $payment['id'] )
			);

			return array();
		}

		$this->payments->update( (int) $payment['id'], array( 'amount' => Money::from_minor( $expected - $others, $decimals ) ) );

		$rows = array();

		foreach ( $covers as $cover ) {
			$member = $this->lock_booking( (int) ( $cover['booking_id'] ?? 0 ) );

			if ( ! $member ) {
				continue;
			}

			$amount = Money::from_minor( Money::to_minor( (string) $cover['amount'], $decimals ), $decimals );
			$row_id = $this->payments->insert(
				array(
					'booking_id'   => $member['id'],
					'gateway'      => $payment['gateway'],
					'status'       => 'completed',
					'amount'       => $amount,
					'currency'     => $payment['currency'],
					'mode'         => $payment['mode'],
					/* translators: %s: booking number. */
					'note'         => substr( sprintf( __( 'Paid together with %s', 'wpinterface-travel' ), $booking['number'] ), 0, 255 ),
					'gateway_data' => array( 'paid_with' => (int) $payment['id'] ),
				)
			);

			if ( ! $row_id ) {
				continue;
			}

			$this->recalculate( (int) $member['id'] );
			$this->bookings->log(
				(int) $member['id'],
				'payment_completed',
				sprintf(
					/* translators: 1: amount, 2: currency, 3: booking number. */
					__( 'Payment of %1$s %2$s received, paid together with %3$s.', 'wpinterface-travel' ),
					$amount,
					$member['currency'],
					$booking['number']
				),
				array(
					'payment_id' => $row_id,
					'paid_with'  => (int) $payment['id'],
				)
			);

			if ( in_array( $member['status'], BookingRepository::RELEASED_STATUSES, true ) ) {
				$this->bookings->log(
					(int) $member['id'],
					'payment_needs_review',
					__( 'Money was received after this payment or booking was closed. Refund it, or reopen the booking.', 'wpinterface-travel' ),
					array( 'payment_id' => $row_id )
				);
			}

			$rows[] = (array) $this->payments->find( $row_id );
		}

		return $rows;
	}

	/**
	 * Whether a booking has a payment the provider is still capturing.
	 *
	 * @param int $booking_id Booking.
	 */
	private function has_capture_in_progress( int $booking_id ): bool {
		foreach ( $this->payments->for_booking( $booking_id ) as $row ) {
			if ( 'pending' === $row['status'] && ! empty( $row['gateway_data']['capture_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Writes a refund row, updates the booking and logs it.
	 *
	 * @param array<string, mixed> $payment        Refunded payment.
	 * @param string               $amount         Amount.
	 * @param string               $reason         Reason.
	 * @param string|null          $transaction_id Provider refund ID, or null for a manual refund.
	 * @param array<string, mixed> $data           Extra data.
	 * @return array<string, mixed>|WP_Error
	 */
	private function record_refund( array $payment, string $amount, string $reason, ?string $transaction_id, array $data ) {
		$refund_id = $this->payments->insert(
			array(
				'booking_id'     => $payment['booking_id'],
				'gateway'        => $payment['gateway'],
				'transaction_id' => $transaction_id,
				'parent_id'      => $payment['id'],
				'payment_type'   => 'refund',
				'status'         => 'completed',
				'amount'         => $amount,
				'currency'       => $payment['currency'],
				'mode'           => $payment['mode'],
				'note'           => substr( sanitize_text_field( $reason ), 0, 255 ),
				'gateway_data'   => $data,
			)
		);

		if ( ! $refund_id ) {
			return new WP_Error( 'wpitravel_refund_failed', __( 'The refund could not be recorded.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$this->recalculate( (int) $payment['booking_id'] );
		$this->bookings->log(
			(int) $payment['booking_id'],
			'refund_completed',
			/* translators: 1: amount, 2: currency. */
			sprintf( __( 'Refund of %1$s %2$s.', 'wpinterface-travel' ), $amount, $payment['currency'] ),
			array(
				'payment_id' => $payment['id'],
				'refund_id'  => $refund_id,
				'reason'     => $reason,
			)
		);

		$refund = (array) $this->payments->find( $refund_id );

		/**
		 * Fires after a refund was recorded.
		 *
		 * @param array $refund  Refund row.
		 * @param array $payment Refunded payment.
		 */
		do_action( 'wpitravel_refund_completed', $refund, $payment );

		return $refund;
	}

	/**
	 * Confirms a fully paid booking and fires the payment action.
	 *
	 * @param int                  $booking_id Booking.
	 * @param array<string, mixed> $payment    Payment.
	 */
	private function after_payment( int $booking_id, array $payment ): void {
		$booking = $this->bookings->find( $booking_id );

		/**
		 * Filters whether this payment confirms a pending booking (default:
		 * when fully paid). Pro confirms on a paid deposit.
		 *
		 * @param bool  $confirm Whether to confirm.
		 * @param array $booking Booking after the payment.
		 * @param array $payment Payment.
		 */
		$confirm = $booking && (bool) apply_filters( 'wpitravel_confirm_on_payment', 'paid' === $booking['payment_status'], $booking, $payment );

		if ( $booking && $confirm && 'pending' === $booking['status'] && wpitravel()->options()->get( 'payments.confirm_on_payment', true ) ) {
			( new BookingService() )->set_status( $booking_id, 'confirmed', __( 'Confirmed automatically: fully paid.', 'wpinterface-travel' ) );
		}

		/**
		 * Fires after a payment was completed or recorded.
		 *
		 * @param array $payment    Payment row.
		 * @param int   $booking_id Booking ID.
		 */
		do_action( 'wpitravel_payment_completed', $payment, $booking_id );
	}

	/**
	 * Cancels earlier unfinished online payments of a booking.
	 *
	 * @param int $booking_id Booking.
	 */
	private function cancel_pending( int $booking_id ): void {
		foreach ( $this->payments->for_booking( $booking_id ) as $row ) {
			// A capture already started at the provider (e.g. an eCheck) is money on its way.
			if ( 'pending' === $row['status'] && empty( $row['gateway_data']['capture_id'] ) ) {
				$this->close_pending( $row['id'], 'cancelled', 'Replaced by a new payment attempt.' );
			}
		}
	}

	/**
	 * Locks and returns a booking row (inside a transaction).
	 *
	 * @param int $booking_id Booking.
	 * @return array<string, mixed>|null
	 */
	private function lock_booking( int $booking_id ): ?array {
		global $wpdb;

		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', Schema::table( 'bookings' ), $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; row lock.

		return $locked ? $this->bookings->find( $booking_id ) : null;
	}
}
