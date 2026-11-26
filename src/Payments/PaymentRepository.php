<?php
/**
 * Payment ledger rows.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments;

use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Db;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `payments` table: one row per payment or refund.
 *
 * - `reference`: the provider's checkout/order ID, known before payment.
 * - `transaction_id`: the provider's capture/refund ID, unique per gateway,
 *   so a repeated notification can never record the same money twice.
 * - `parent_id`: for refunds, the payment being refunded.
 */
final class PaymentRepository {

	/**
	 * One row by ID.
	 *
	 * @param int  $id         Payment ID.
	 * @param bool $for_update Lock the row (inside a transaction).
	 * @return array<string, mixed>|null
	 */
	public function find( int $id, bool $for_update = false ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.
		$row = $for_update
			? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Schema::table( 'payments' ), $id ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'payments' ), $id ), ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * A row by the provider's checkout reference.
	 *
	 * @param string $gateway   Gateway ID.
	 * @param string $reference Reference.
	 * @return array<string, mixed>|null
	 */
	public function find_by_reference( string $gateway, string $reference ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE gateway = %s AND reference = %s ORDER BY id DESC LIMIT 1', Schema::table( 'payments' ), $gateway, $reference ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * A row by the provider's transaction ID.
	 *
	 * @param string $gateway        Gateway ID.
	 * @param string $transaction_id Transaction ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_transaction( string $gateway, string $transaction_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE gateway = %s AND transaction_id = %s', Schema::table( 'payments' ), $gateway, $transaction_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * Every row of a booking, oldest first.
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<array<string, mixed>>
	 */
	public function for_booking( int $booking_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY id', Schema::table( 'payments' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Inserts a row.
	 *
	 * @param array<string, mixed> $data Columns.
	 * @return int ID, 0 on failure (e.g. duplicate transaction ID).
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$now  = Db::now();
		$data = array_merge(
			array(
				'payment_type' => 'payment',
				'status'       => 'pending',
				'mode'         => 'live',
				'note'         => '',
				'created'      => $now,
				'updated'      => $now,
			),
			$data
		);

		if ( isset( $data['gateway_data'] ) && is_array( $data['gateway_data'] ) ) {
			$data['gateway_data'] = wp_json_encode( $data['gateway_data'] );
		}

		$wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( Schema::table( 'payments' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->suppress_errors( false );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a row.
	 *
	 * @param int                  $id   Payment ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( isset( $data['gateway_data'] ) && is_array( $data['gateway_data'] ) ) {
			$data['gateway_data'] = wp_json_encode( $data['gateway_data'] );
		}

		$data['updated'] = Db::now();

		$wpdb->suppress_errors( true );
		$updated = $wpdb->update( Schema::table( 'payments' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->suppress_errors( false );

		return false !== $updated;
	}

	/**
	 * Completed payments and refunds of a booking, in minor units.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $decimals   Currency decimals.
	 * @return array{paid: int, refunded: int}
	 */
	public function totals( int $booking_id, int $decimals ): array {
		$paid     = 0;
		$refunded = 0;

		foreach ( $this->for_booking( $booking_id ) as $row ) {
			if ( 'completed' !== $row['status'] ) {
				continue;
			}

			if ( 'refund' === $row['payment_type'] ) {
				$refunded += Money::to_minor( $row['amount'], $decimals );
			} else {
				$paid += Money::to_minor( $row['amount'], $decimals );
			}
		}

		return array(
			'paid'     => $paid,
			'refunded' => $refunded,
		);
	}

	/**
	 * Amount already refunded from one payment, in minor units.
	 *
	 * @param int $payment_id Payment ID.
	 * @param int $decimals   Currency decimals.
	 */
	public function refunded_from( int $payment_id, int $decimals ): int {
		global $wpdb;

		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT amount FROM %i WHERE parent_id = %d AND payment_type = 'refund' AND status = 'completed'", Schema::table( 'payments' ), $payment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array_sum( array_map( static fn( $amount ) => Money::to_minor( (string) $amount, $decimals ), (array) $rows ) );
	}

	/**
	 * Casts columns; amounts get the currency's decimals.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['booking_id']   = (int) $row['booking_id'];
		$row['parent_id']    = (int) $row['parent_id'];
		$row['amount']       = Money::normalize( (string) $row['amount'], Currencies::decimals( (string) $row['currency'] ) );
		$row['gateway_data'] = json_decode( (string) $row['gateway_data'], true ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Empty data becomes [].

		return $row;
	}
}
