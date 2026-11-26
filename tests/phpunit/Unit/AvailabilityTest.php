<?php
/**
 * Schedule expansion and the availability engine.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Install\Schema;

/**
 * Covers Schedule::slots() and Availability::for_trip().
 *
 * "Now" is Monday 1 June 2026, 10:00 UTC, with the site timezone set to UTC.
 */
class AvailabilityTest extends WP_UnitTestCase {

	/**
	 * Trip under test.
	 *
	 * @var int
	 */
	private int $trip;

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();
		( new TripMeta() )->register_meta();

		update_option( 'timezone_string', 'UTC' );
		update_option( 'gmt_offset', 0 );

		$this->trip = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );
		update_post_meta(
			$this->trip,
			TripMeta::PRICING,
			array(
				'options' => array(
					array(
						'label'      => 'Standard',
						'categories' => array(
							array(
								'label' => 'Adult',
								'price' => '100',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Availability fixed at Monday 1 June 2026, 10:00 UTC.
	 */
	private function availability(): Availability {
		return new Availability( null, new DateTimeImmutable( '2026-06-01 10:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Saves a schedule on the trip.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 */
	private function schedule( array $schedule ): void {
		update_post_meta( $this->trip, TripMeta::SCHEDULE, $schedule + array( 'advance_days' => 0 ) );
	}

	/**
	 * Inserts a departure row.
	 *
	 * @param array<string, mixed> $row Columns.
	 */
	private function insert_departure( array $row ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'departures' ),
			$row + array(
				'trip_id'    => $this->trip,
				'start_time' => '',
				'status'     => 'open',
				'source'     => 'fixed',
				'created'    => '2026-01-01 00:00:00',
				'updated'    => '2026-01-01 00:00:00',
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_schedule_expands_rules_and_removes_excluded_dates(): void {
		$schedule = Schedule::sanitize(
			array(
				'fixed'     => array(
					array( 'date' => '2026-06-03' ),
					array( 'date' => '2026-06-03' ),
					array( 'date' => 'not-a-date' ),
				),
				'recurring' => array(
					array(
						'start'    => '2026-06-01',
						'end'      => '2026-07-31',
						'weekdays' => array( 6, 9 ),
						'months'   => array( 7 ),
						'times'    => array( '14:00', '08:00', '25:00' ),
					),
				),
				'excluded'  => array( '2026-07-11' ),
			)
		);

		$this->assertCount( 1, $schedule['fixed'], 'Duplicate and invalid fixed dates are dropped.' );
		$this->assertSame( array( 6 ), $schedule['recurring'][0]['weekdays'] );
		$this->assertSame( array( '08:00', '14:00' ), $schedule['recurring'][0]['times'] );

		$dates = array_map(
			static fn( array $slot ): string => $slot['date'] . ' ' . $slot['time'],
			array_values( Schedule::slots( $schedule, '2026-06-01', '2026-07-20' ) )
		);

		$this->assertSame(
			array(
				'2026-06-03 ',
				'2026-07-04 08:00',
				'2026-07-04 14:00',
				'2026-07-18 08:00',
				'2026-07-18 14:00',
			),
			$dates,
			'Saturdays in July only, without the excluded 11 July.'
		);
	}

	public function test_cutoff_and_booking_window(): void {
		$this->schedule(
			array(
				'fixed'        => array(
					array(
						'date' => '2026-06-02',
						'time' => '08:00',
					),
					array(
						'date' => '2026-06-03',
						'time' => '08:00',
					),
					array( 'date' => '2026-06-20' ),
				),
				'cutoff_hours' => 24,
				'advance_days' => 10,
			)
		);

		$staff = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30', true );
		$this->assertSame( array( 'past_cutoff', 'open', 'not_yet_open' ), array_column( $staff, 'status' ) );

		$public = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30' );
		$this->assertSame( array( '2026-06-03' ), array_column( $public, 'date' ) );
		$this->assertTrue( $public[0]['bookable'] );
		$this->assertSame( '100.00', $public[0]['from_price'] );
	}

	public function test_seats_count_bookings_and_active_holds_only(): void {
		global $wpdb;

		$this->schedule(
			array(
				'fixed'    => array( array( 'date' => '2026-06-10' ), array( 'date' => '2026-06-11' ) ),
				'capacity' => 10,
			)
		);

		$june_10 = $this->insert_departure(
			array(
				'start_date'   => '2026-06-10',
				'seats_booked' => 6,
			)
		);
		$june_11 = $this->insert_departure(
			array(
				'start_date'   => '2026-06-11',
				'seats_booked' => 8,
				'capacity'     => 10,
			)
		);

		$future = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$past   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		foreach ( array( array( $june_10, 3, $future ), array( $june_10, 5, $past ), array( $june_11, 2, $future ) ) as $index => $hold ) {
			$wpdb->insert(
				Schema::table( 'holds' ),
				array(
					'hold_key'     => str_repeat( (string) $index, 32 ),
					'departure_id' => $hold[0],
					'seats'        => $hold[1],
					'expires_at'   => $hold[2],
					'created'      => $past,
				)
			);
		}

		$slots = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30', true );

		$this->assertSame( 1, $slots[0]['seats_left'], '10 - 6 booked - 3 held; the expired hold is ignored.' );
		$this->assertSame( 3, $slots[0]['seats_held'] );
		$this->assertSame( 0, $slots[1]['seats_left'] );
		$this->assertSame( 'sold_out', $slots[1]['status'] );
		$this->assertFalse( $slots[1]['bookable'] );
	}

	public function test_overrides_manual_dates_and_unscheduled_rows(): void {
		$this->schedule( array( 'fixed' => array( array( 'date' => '2026-06-10' ), array( 'date' => '2026-06-12' ) ) ) );

		$this->insert_departure(
			array(
				'start_date' => '2026-06-10',
				'status'     => 'closed',
			)
		);
		$this->insert_departure(
			array(
				'start_date' => '2026-06-12',
				'capacity'   => 4,
			)
		);
		$this->insert_departure(
			array(
				'start_date' => '2026-06-15',
				'source'     => 'manual',
			)
		);
		$this->insert_departure(
			array(
				'start_date'   => '2026-06-20',
				'source'       => 'fixed',
				'seats_booked' => 2,
			)
		);

		$public = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30' );
		$this->assertSame( array( '2026-06-12', '2026-06-15' ), array_column( $public, 'date' ) );
		$this->assertSame( 4, $public[0]['seats_left'], 'A per-date capacity overrides the unlimited default.' );
		$this->assertNull( $public[1]['seats_left'] );

		$staff = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30', true );
		$this->assertSame(
			array( 'closed', 'open', 'open', 'unscheduled' ),
			array_column( $staff, 'status' ),
			'Staff also see the closed date and a removed date that still has bookings.'
		);
	}

	public function test_trip_without_prices_is_not_bookable(): void {
		delete_post_meta( $this->trip, TripMeta::PRICING );
		$this->schedule( array( 'fixed' => array( array( 'date' => '2026-06-10' ) ) ) );

		$slots = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-06-30' );

		$this->assertSame( 'open', $slots[0]['status'] );
		$this->assertFalse( $slots[0]['bookable'] );
		$this->assertNull( $slots[0]['from_price'] );
	}

	public function test_end_date_follows_duration_and_today_uses_site_timezone(): void {
		update_post_meta( $this->trip, TripMeta::DURATION, array( 'days' => 3 ) );
		$this->schedule( array( 'fixed' => array( array( 'date' => '2026-06-30' ) ) ) );

		$slots = $this->availability()->for_trip( $this->trip, '2026-06-01', '2026-07-31' );
		$this->assertSame( '2026-07-02', $slots[0]['end_date'] );

		update_option( 'timezone_string', 'Pacific/Auckland' );
		$late_evening_utc = new Availability( null, new DateTimeImmutable( '2026-06-01 20:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( '2026-06-02', $late_evening_utc->today(), 'It is already 2 June in Auckland.' );
	}

	public function test_repository_ensure_is_idempotent_and_delete_protects_bookings(): void {
		$repository = new DepartureRepository();

		$first  = $repository->ensure( $this->trip, '2026-08-01', '09:00', 'manual' );
		$second = $repository->ensure( $this->trip, '2026-08-01', '09:00', 'manual' );
		$this->assertSame( $first['id'], $second['id'] );

		$booked = $this->insert_departure(
			array(
				'start_date'   => '2026-08-02',
				'seats_booked' => 1,
			)
		);

		$this->assertFalse( $repository->delete_unbooked( $booked ) );
		$this->assertTrue( $repository->delete_unbooked( $first['id'] ) );
		$this->assertNull( $repository->find( $first['id'] ) );
	}
}
