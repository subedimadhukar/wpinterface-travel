<?php
/**
 * Traveler list of a departure.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\Pickups;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Money;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → (hidden) Traveler list: everyone booked on one departure, ready
 * to print for guides, drivers and suppliers. Opened from the trip
 * editor's upcoming departures and from a booking.
 */
final class ManifestPage {

	public const SLUG = 'wpitravel-manifest';

	/**
	 * Most bookings listed.
	 */
	private const MAX = 500;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
	}

	/**
	 * Registers the page without a menu entry.
	 */
	public function menu(): void {
		$parent = 'edit.php?post_type=' . PostTypes::TRIP;
		$hook   = add_submenu_page( $parent, __( 'Traveler list', 'wpinterface-travel' ), __( 'Traveler list', 'wpinterface-travel' ), Capabilities::VIEW_BOOKINGS, self::SLUG, array( $this, 'render' ) );
		remove_submenu_page( $parent, self::SLUG );

		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'styles' ) );
			// Without a menu entry WordPress has no title for the browser tab.
			add_action(
				'load-' . $hook,
				static function (): void {
					$GLOBALS['title'] = __( 'Traveler list', 'wpinterface-travel' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin page title.
				}
			);
		}
	}

	/**
	 * Address of a departure's list.
	 *
	 * @param int    $trip_id Trip.
	 * @param string $date    Y-m-d.
	 * @param string $time    HH:MM or ''.
	 */
	public static function url( int $trip_id, string $date, string $time = '' ): string {
		// A page without a menu entry opens under its parent's address.
		return add_query_arg(
			array_filter(
				array(
					'post_type' => PostTypes::TRIP,
					'page'      => self::SLUG,
					'trip'      => $trip_id,
					'date'      => $date,
					'time'      => $time,
				)
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Print styles (only the list on paper) and the Print button.
	 */
	public function styles(): void {
		wp_register_style( 'wpitravel-manifest', false, array(), WPITRAVEL_VERSION );
		wp_enqueue_style( 'wpitravel-manifest' );
		wp_register_script( 'wpitravel-manifest', false, array(), WPITRAVEL_VERSION, true );
		wp_enqueue_script( 'wpitravel-manifest' );
		wp_add_inline_script( 'wpitravel-manifest', 'document.addEventListener("click",function(e){if(e.target.closest("[data-wpitravel-print]")){window.print();}});' );
		wp_add_inline_style(
			'wpitravel-manifest',
			'.wpitravel-manifest table{margin-top:12px}.wpitravel-manifest td,.wpitravel-manifest th{vertical-align:top}.wpitravel-manifest__booking td{border-top:2px solid #c3c4c7}.wpitravel-manifest__summary{display:flex;flex-wrap:wrap;gap:6px 24px;margin:12px 0}.wpitravel-manifest__summary p{margin:0}'
			. '@media print{#adminmenumain,#wpadminbar,#wpfooter,#screen-meta-links,.notice,.wpitravel-manifest__actions{display:none!important}#wpcontent,#wpbody-content{margin:0!important;padding:0!important}html.wp-toolbar{padding-top:0!important}.wpitravel-manifest table{font-size:11px}.wpitravel-manifest a{color:inherit;text-decoration:none}}'
		);
	}

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see bookings.', 'wpinterface-travel' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view, checked by capability.
		$trip_id = Multilingual::original_trip_id( isset( $_GET['trip'] ) ? absint( wp_unslash( $_GET['trip'] ) ) : 0 );
		$date    = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$time    = isset( $_GET['time'] ) ? sanitize_text_field( wp_unslash( $_GET['time'] ) ) : '';
		// phpcs:enable

		$date = preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $date ) ? $date : '';
		$time = preg_match( '/^\d{2}:\d{2}\z/', $time ) ? $time : '';

		if ( ! $trip_id || PostTypes::TRIP !== get_post_type( $trip_id ) || '' === $date ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Traveler list', 'wpinterface-travel' ) . '</h1><p>' . esc_html__( 'Open a traveler list from a departure in the trip editor.', 'wpinterface-travel' ) . '</p></div>';
			return;
		}

		$list     = self::data( $trip_id, $date, $time );
		$when     = date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $date . ' 12:00:00' ) ) . ( '' !== $time ? ' · ' . $time : '' );
		$currency = (array) wpitravel()->options()->get( 'currency', array() );
		$meeting  = (string) get_post_meta( $trip_id, TripMeta::MEETING_POINT, true );
		?>
		<div class="wrap wpitravel-manifest">
			<h1><?php echo esc_html( get_the_title( $trip_id ) ); ?> <span style="font-weight:400">— <?php echo esc_html( $when ); ?></span></h1>
			<p class="wpitravel-manifest__actions">
				<button type="button" class="button button-primary" data-wpitravel-print><?php esc_html_e( 'Print', 'wpinterface-travel' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'trip_id' => $trip_id ), admin_url( 'admin.php?page=' . BookingsPage::SLUG ) ) ); ?>"><?php esc_html_e( 'All bookings of this trip', 'wpinterface-travel' ); ?></a>
			</p>
			<div class="wpitravel-manifest__summary">
				<p>
					<?php
					/* translators: 1: bookings, 2: travelers. */
					echo esc_html( sprintf( __( '%1$s bookings · %2$s travelers', 'wpinterface-travel' ), number_format_i18n( count( $list['bookings'] ) ), number_format_i18n( $list['travelers'] ) ) );
					?>
				</p>
				<?php if ( '' !== $meeting ) : ?>
					<p>
						<?php
						/* translators: %s: meeting point. */
						echo esc_html( sprintf( __( 'Meeting point: %s', 'wpinterface-travel' ), $meeting ) );
						?>
					</p>
				<?php endif; ?>
				<?php foreach ( $list['pickups'] as $wpitravel_pickup => $wpitravel_count ) : ?>
					<p>
						<?php
						/* translators: 1: pickup point, 2: travelers. */
						echo esc_html( sprintf( __( 'Pickup %1$s: %2$s', 'wpinterface-travel' ), $wpitravel_pickup, number_format_i18n( $wpitravel_count ) ) );
						?>
					</p>
				<?php endforeach; ?>
				<?php if ( $list['due'] > 0 ) : ?>
					<p>
						<?php
						/* translators: %s: amount. */
						echo esc_html( sprintf( __( 'Still to collect: %s', 'wpinterface-travel' ), Money::format( Money::from_minor( $list['due'] ), $currency ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! $list['bookings'] ) : ?>
				<p><?php esc_html_e( 'Nobody has booked this departure yet.', 'wpinterface-travel' ); ?></p>
			<?php else : ?>
				<table class="widefat">
					<caption class="screen-reader-text"><?php esc_html_e( 'Travelers on this departure', 'wpinterface-travel' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Booking', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traveler', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Phone', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pickup', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'To collect', 'wpinterface-travel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Notes', 'wpinterface-travel' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $list['bookings'] as $wpitravel_booking ) : ?>
							<?php foreach ( $wpitravel_booking['travelers'] as $wpitravel_index => $wpitravel_traveler ) : ?>
								<tr class="<?php echo 0 === $wpitravel_index ? 'wpitravel-manifest__booking' : ''; ?>">
									<?php if ( 0 === $wpitravel_index ) : ?>
										<td rowspan="<?php echo esc_attr( (string) count( $wpitravel_booking['travelers'] ) ); ?>">
											<a href="<?php echo esc_url( $wpitravel_booking['url'] ); ?>"><?php echo esc_html( $wpitravel_booking['number'] ); ?></a><br>
											<?php echo esc_html( $wpitravel_booking['status'] ); ?>
										</td>
									<?php endif; ?>
									<td><?php echo esc_html( $wpitravel_traveler['name'] ); ?></td>
									<td><?php echo esc_html( $wpitravel_traveler['type'] ); ?></td>
									<td><?php echo esc_html( $wpitravel_traveler['phone'] ); ?></td>
									<?php if ( 0 === $wpitravel_index ) : ?>
										<td rowspan="<?php echo esc_attr( (string) count( $wpitravel_booking['travelers'] ) ); ?>"><?php echo esc_html( $wpitravel_booking['pickup'] ); ?></td>
										<td rowspan="<?php echo esc_attr( (string) count( $wpitravel_booking['travelers'] ) ); ?>"><?php echo esc_html( $wpitravel_booking['due'] > 0 ? Money::format( Money::from_minor( $wpitravel_booking['due'] ), $currency ) : '—' ); ?></td>
										<td rowspan="<?php echo esc_attr( (string) count( $wpitravel_booking['travelers'] ) ); ?>"><?php echo nl2br( esc_html( $wpitravel_booking['note'] ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Bookings and travelers of one departure (cancelled and failed left
	 * out), sorted by pickup time, then booking number.
	 *
	 * @param int    $trip_id Original trip.
	 * @param string $date    Y-m-d.
	 * @param string $time    HH:MM, or '' for every start time.
	 * @return array{bookings: list<array<string, mixed>>, travelers: int, due: int, pickups: array<string, int>} Each booking has id, number, status, url, travelers, pickup, pickup_time, due and note.
	 */
	public static function data( int $trip_id, string $date, string $time = '' ): array {
		global $wpdb;

		$ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE trip_id = %d AND start_date = %s AND ( %s = '' OR start_time = %s ) AND status NOT IN ('cancelled', 'failed') ORDER BY id LIMIT %d",
				Schema::table( 'bookings' ),
				$trip_id,
				$date,
				$time,
				$time,
				self::MAX
			)
		);

		$repository = new BookingRepository();
		$types      = self::types( $trip_id );
		$bookings   = array();
		$count      = 0;
		$due        = 0;
		$pickups    = array();

		foreach ( $ids as $id ) {
			$booking = $repository->find( (int) $id );

			if ( ! $booking ) {
				continue;
			}

			$customer  = $repository->customer( $booking );
			$people    = array();
			$pickup    = Pickups::of_booking( $booking );
			$balance   = max( 0, Money::to_minor( (string) $booking['total'] ) - Money::to_minor( (string) $booking['amount_paid'] ) );
			$travelers = $repository->travelers( (int) $id );

			foreach ( $travelers as $traveler ) {
				$name = trim( $traveler['first_name'] . ' ' . $traveler['last_name'] );

				$people[] = array(
					'name'  => '' !== $name ? $name : __( '(name not given yet)', 'wpinterface-travel' ),
					'type'  => $types[ (string) $traveler['category'] ] ?? (string) $traveler['category'],
					'phone' => '' !== (string) $traveler['phone'] ? (string) $traveler['phone'] : (string) $customer['phone'],
				);
			}

			// Names not sent yet: the contact, then one line per remaining seat.
			if ( ! $people ) {
				$people[] = array(
					'name'  => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
					'type'  => '',
					'phone' => (string) $customer['phone'],
				);
			}

			for ( $n = count( $people ); $n < (int) $booking['pax']; $n++ ) {
				$people[] = array(
					'name'  => __( '(name not given yet)', 'wpinterface-travel' ),
					'type'  => '',
					'phone' => '',
				);
			}

			$label = Pickups::label( $pickup );

			if ( '' !== $label ) {
				$pickups[ $label ] = ( $pickups[ $label ] ?? 0 ) + (int) $booking['pax'];
			}

			$count += (int) $booking['pax'];
			$due   += $balance;

			$bookings[] = array(
				'id'          => (int) $booking['id'],
				'number'      => (string) $booking['number'],
				'status'      => BookingAccess::status_label( (string) $booking['status'] ),
				'url'         => add_query_arg( array( 'booking' => (int) $booking['id'] ), admin_url( 'admin.php?page=' . BookingsPage::SLUG ) ),
				'travelers'   => $people,
				'pickup'      => $label,
				'pickup_time' => $pickup ? $pickup['time'] : '',
				'due'         => $balance,
				'note'        => (string) $booking['customer_note'],
			);
		}

		usort(
			$bookings,
			static fn( array $a, array $b ): int => array( '' === $a['pickup_time'] ? '99' : $a['pickup_time'], $a['number'] ) <=> array( '' === $b['pickup_time'] ? '99' : $b['pickup_time'], $b['number'] )
		);
		ksort( $pickups );

		return array(
			'bookings'  => $bookings,
			'travelers' => $count,
			'due'       => $due,
			'pickups'   => $pickups,
		);
	}

	/**
	 * Traveler type names of a trip, by key.
	 *
	 * @param int $trip_id Trip.
	 * @return array<string, string>
	 */
	private static function types( int $trip_id ): array {
		$types = array();

		foreach ( Pricing::sanitize( get_post_meta( $trip_id, TripMeta::PRICING, true ) )['options'] as $option ) {
			foreach ( $option['categories'] as $category ) {
				$types[ $category['key'] ] = $category['label'];
			}
		}

		return $types;
	}
}
