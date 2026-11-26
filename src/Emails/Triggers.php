<?php
/**
 * When emails are sent.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Emails;

use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Connects booking events to emails, and runs the daily reminders, review
 * requests and completion of past trips.
 *
 * Scheduled emails are sent once per booking: the booking history records
 * every email, and a booking that already got one is skipped.
 */
final class Triggers {

	/**
	 * Bookings confirmed during this request; they get the confirmation
	 * email instead of a separate "payment received" email.
	 *
	 * @var array<int, bool>
	 */
	private array $confirmed_now = array();

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Mailer|null $mailer Mailer.
	 */
	public function __construct( ?Mailer $mailer = null ) {
		$this->mailer = $mailer ?? new Mailer();
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wpitravel_booking_created', array( $this, 'on_booking_created' ), 20 );
		add_action( 'wpitravel_booking_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( 'wpitravel_payment_completed', array( $this, 'on_payment_completed' ), 20, 2 );
		add_action( 'wpitravel_enquiry_created', array( $this, 'on_enquiry_created' ), 20 );
		add_action( 'wpitravel_daily', array( $this, 'daily' ) );
	}

	/**
	 * New booking: customer and staff.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public function on_booking_created( $booking_id ): void {
		$booking = ( new BookingRepository() )->find( (int) $booking_id );

		if ( $booking ) {
			$this->mailer->send_booking( 'customer_booking_received', $booking );
			$this->mailer->send_booking( 'admin_new_booking', $booking );
		}
	}

	/**
	 * Status changes: confirmed, cancelled, cancellation requested.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $status     New status.
	 * @param string $old        Previous status.
	 */
	public function on_status_changed( $booking_id, $status, $old ): void {
		$booking = ( new BookingRepository() )->find( (int) $booking_id );

		if ( ! $booking ) {
			return;
		}

		if ( 'confirmed' === $status && in_array( $old, array( 'pending', 'cancel_requested' ), true ) ) {
			$this->confirmed_now[ (int) $booking_id ] = true;

			// Going back from "cancellation requested" is not a new confirmation.
			if ( 'pending' === $old ) {
				$this->mailer->send_booking( 'customer_booking_confirmed', $booking );
			}
		}

		if ( 'cancelled' === $status ) {
			$this->mailer->send_booking( 'customer_booking_cancelled', $booking );
		}

		if ( 'cancel_requested' === $status ) {
			$this->mailer->send_booking( 'admin_cancel_request', $booking, array( 'cancellation_reason' => self::last_cancel_reason( (int) $booking_id ) ) );
		}
	}

	/**
	 * Payments: staff always; the customer unless this payment confirmed the booking.
	 *
	 * @param array<string, mixed> $payment    Payment.
	 * @param int                  $booking_id Booking ID.
	 */
	public function on_payment_completed( $payment, $booking_id ): void {
		$booking = ( new BookingRepository() )->find( (int) $booking_id );

		if ( ! $booking ) {
			return;
		}

		$extra = array( 'payment_amount' => (string) ( $payment['amount'] ?? '0' ) );

		$this->mailer->send_booking( 'admin_payment_received', $booking, $extra );

		if ( empty( $this->confirmed_now[ (int) $booking_id ] ) ) {
			$this->mailer->send_booking( 'customer_payment_received', $booking, $extra );
		}
	}

	/**
	 * New enquiry: staff and the sender.
	 *
	 * @param array<string, mixed> $enquiry Enquiry.
	 */
	public function on_enquiry_created( $enquiry ): void {
		$this->mailer->send_enquiry( 'admin_new_enquiry', (array) $enquiry );
		// At most one copy per address every 10 minutes.
		$key = 'wpitravel_enq_' . md5( strtolower( (string) ( $enquiry['email'] ?? '' ) ) );

		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
			$this->mailer->send_enquiry( 'customer_enquiry_received', (array) $enquiry );
		}
	}

	/**
	 * Cron callback.
	 */
	public function daily(): void {
		$this->run_daily();
	}

	/**
	 * Daily: reminders, review requests, completing past trips.
	 *
	 * @param string|null $today Y-m-d in the site timezone (tests).
	 * @return array{reminders: int, reviews: int, completed: int}
	 */
	public function run_daily( $today = null ): array {
		$today = is_string( $today ) ? $today : current_time( 'Y-m-d' );

		return array(
			'reminders' => $this->send_reminders( $today ),
			'reviews'   => $this->send_review_requests( $today ),
			'completed' => $this->complete_past_trips( $today ),
		);
	}

	/**
	 * Reminders to confirmed bookings departing in N days.
	 *
	 * @param string $today Y-m-d.
	 */
	private function send_reminders( string $today ): int {
		$days = (int) wpitravel()->options()->get( 'emails.reminder_days', 3 );

		if ( $days <= 0 ) {
			return 0;
		}

		$target = gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . $days . ' days' ) );
		$sent   = 0;

		// Dates up to the target, so a missed cron run still sends late reminders.
		foreach ( $this->bookings( array( 'confirmed' ), $today, $target ) as $booking ) {
			if ( ! $this->already_sent( $booking['id'], 'customer_trip_reminder' ) && $this->mailer->send_booking( 'customer_trip_reminder', $booking ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Review requests N days after the trip ended.
	 *
	 * @param string $today Y-m-d.
	 */
	private function send_review_requests( string $today ): int {
		if ( ! wpitravel()->options()->get( 'reviews.enabled', true ) ) {
			return 0;
		}

		$delay = (int) wpitravel()->options()->get( 'emails.review_delay_days', 1 );
		$last  = gmdate( 'Y-m-d', (int) strtotime( $today . ' -' . $delay . ' days' ) );
		$first = gmdate( 'Y-m-d', (int) strtotime( $last . ' -30 days' ) );
		$sent  = 0;

		// Trips that started up to 30 days before the cut-off; the end date decides.
		foreach ( $this->bookings( array( 'confirmed', 'completed' ), gmdate( 'Y-m-d', (int) strtotime( $first . ' -60 days' ) ), $last ) as $booking ) {
			$end = self::end_date( $booking );

			if ( $end > $last || $end < $first || $this->already_sent( $booking['id'], 'customer_review_request' ) ) {
				continue;
			}

			if ( $this->mailer->send_booking( 'customer_review_request', $booking ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Confirmed bookings whose trip ended become completed.
	 *
	 * @param string $today Y-m-d.
	 */
	private function complete_past_trips( string $today ): int {
		$service   = new BookingService();
		$completed = 0;

		foreach ( $this->bookings( array( 'confirmed' ), '2000-01-01', $today ) as $booking ) {
			if ( self::end_date( $booking ) < $today ) {
				$service->set_status( $booking['id'], 'completed', __( 'Trip ended.', 'wpinterface-travel' ) );
				++$completed;
			}
		}

		return $completed;
	}

	/**
	 * Bookings with a status, departing between two dates.
	 *
	 * @param string[] $statuses Statuses.
	 * @param string   $from     Y-m-d.
	 * @param string   $to       Y-m-d.
	 * @return list<array<string, mixed>>
	 */
	private function bookings( array $statuses, string $from, string $to ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; one %s per status is built above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status IN ({$placeholders}) AND start_date BETWEEN %s AND %s ORDER BY start_date LIMIT 500",
				...array_merge( array( Schema::table( 'bookings' ) ), $statuses, array( $from, $to ) )
			)
		);
		// phpcs:enable

		$repository = new BookingRepository();

		return array_values( array_filter( array_map( static fn( $id ) => $repository->find( (int) $id ), (array) $ids ) ) );
	}

	/**
	 * Whether a booking already got an email.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $template   Template ID.
	 */
	private function already_sent( int $booking_id, string $template ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE booking_id = %d AND event = 'email_sent' AND context LIKE %s LIMIT 1",
				Schema::table( 'booking_log' ),
				$booking_id,
				'%' . $wpdb->esc_like( '"template":"' . $template . '"' ) . '%'
			)
		);
	}

	/**
	 * Last date of the trip, from its duration.
	 *
	 * @param array<string, mixed> $booking Booking.
	 */
	public static function end_date( array $booking ): string {
		$days = TripMeta::sanitize_duration( get_post_meta( (int) $booking['trip_id'], TripMeta::DURATION, true ) )['days'];

		return $days > 1 ? gmdate( 'Y-m-d', (int) strtotime( $booking['start_date'] . ' +' . ( $days - 1 ) . ' days' ) ) : (string) $booking['start_date'];
	}

	/**
	 * The reason of the most recent cancellation request.
	 *
	 * @param int $booking_id Booking ID.
	 */
	private static function last_cancel_reason( int $booking_id ): string {
		$reason = '';

		foreach ( ( new BookingRepository() )->history( $booking_id ) as $entry ) {
			$context = json_decode( (string) $entry['context'], true );

			if ( 'status_changed' === $entry['event'] && 'cancel_requested' === ( $context['to'] ?? '' ) ) {
				$reason = (string) $entry['message'];
			}
		}

		return $reason;
	}
}
