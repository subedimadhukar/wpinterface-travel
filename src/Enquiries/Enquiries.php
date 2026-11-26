<?php
/**
 * Enquiries.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Enquiries;

use WP_Error;
use WP_Post;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Questions sent from trip pages, kept in an inbox for staff.
 */
final class Enquiries {

	public const STATUSES = array( 'new', 'replied', 'closed' );

	/**
	 * Saves a new enquiry and fires `wpitravel_enquiry_created` (emails).
	 *
	 * @param array<string, mixed> $data trip_id, name, email, phone, message.
	 * @param string               $ip   Sender IP.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $data, string $ip = '' ) {
		global $wpdb;

		$trip_id = absint( $data['trip_id'] ?? 0 );
		$trip    = $trip_id ? get_post( $trip_id ) : null;

		if ( $trip_id && ( ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type || 'publish' !== $trip->post_status ) ) {
			$trip_id = 0;
		}

		$clean = array(
			'trip_id' => $trip_id,
			'user_id' => get_current_user_id(),
			'name'    => substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 200 ),
			'email'   => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'phone'   => substr( sanitize_text_field( (string) ( $data['phone'] ?? '' ) ), 0, 50 ),
			'message' => trim( sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ) ),
			'status'  => 'new',
			'ip'      => $ip,
			'created' => Db::now(),
			'updated' => Db::now(),
		);

		$checks = array(
			'name'    => array( '' !== $clean['name'], __( 'Enter your name.', 'wpinterface-travel' ) ),
			'email'   => array( is_email( $clean['email'] ), __( 'Enter a valid email address.', 'wpinterface-travel' ) ),
			'message' => array( mb_strlen( $clean['message'] ) >= 10 && mb_strlen( $clean['message'] ) <= 5000, __( 'Write a message of 10 to 5000 characters.', 'wpinterface-travel' ) ),
		);

		foreach ( $checks as $field => $check ) {
			if ( ! $check[0] ) {
				return new WP_Error(
					'wpitravel_invalid_enquiry',
					$check[1],
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
		}

		if ( ! $wpdb->insert( Schema::table( 'enquiries' ), $clean ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			return new WP_Error( 'wpitravel_enquiry_failed', __( 'Your message could not be sent. Try again.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$enquiry = (array) $this->find( (int) $wpdb->insert_id );

		/**
		 * Fires after an enquiry was saved (emails, CRM integrations).
		 *
		 * @param array $enquiry Enquiry.
		 */
		do_action( 'wpitravel_enquiry_created', $enquiry );

		return $enquiry;
	}

	/**
	 * One enquiry.
	 *
	 * @param int $id Enquiry ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'enquiries' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * A page of enquiries, newest first.
	 *
	 * @param string $status   Status filter ('' for all).
	 * @param string $search   Name, email or message.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate( string $status = '', string $search = '', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table  = Schema::table( 'enquiries' );
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';
		$like   = '%' . $wpdb->esc_like( $search ) . '%';

		// One fixed query: an empty status or search matches everything.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR name LIKE %s OR email LIKE %s OR message LIKE %s)", $table, $status, $status, $search, $like, $like, $like ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR name LIKE %s OR email LIKE %s OR message LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $status, $status, $search, $like, $like, $like, $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Number of new enquiries (menu badge).
	 */
	public function count_new(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'new'", Schema::table( 'enquiries' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Changes the status.
	 *
	 * @param int    $id     Enquiry ID.
	 * @param string $status Status.
	 */
	public function set_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			Schema::table( 'enquiries' ),
			array(
				'status'  => $status,
				'updated' => Db::now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Deletes an enquiry.
	 *
	 * @param int $id Enquiry ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'enquiries' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Casts columns and adds the trip title.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['trip_id']    = (int) $row['trip_id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['trip_title'] = $row['trip_id'] ? get_the_title( $row['trip_id'] ) : '';
		$row['created']    = get_date_from_gmt( (string) $row['created'], 'Y-m-d H:i' );
		unset( $row['ip'] );

		return $row;
	}
}
