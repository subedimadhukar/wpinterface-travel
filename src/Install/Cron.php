<?php
/**
 * Scheduled tasks.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Install;

use WPInterface\Travel\Booking\Holds;
use WPInterface\Travel\Payments\PaymentService;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the plugin's recurring events and runs the free plugin's tasks.
 */
final class Cron {

	public const HOURLY = 'wpitravel_hourly';
	public const DAILY  = 'wpitravel_daily';

	/**
	 * Registers task callbacks and makes sure the events are scheduled.
	 */
	public function register(): void {
		add_action( self::HOURLY, array( $this, 'run_hourly' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the events if missing (cheap: reads the cron option).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
		}

		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	/**
	 * Hourly: delete expired seat holds and cancel abandoned online payments.
	 */
	public function run_hourly(): void {
		( new Holds() )->purge_expired();
		( new PaymentService() )->cancel_abandoned();
	}
}
