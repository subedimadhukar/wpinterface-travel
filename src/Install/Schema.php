<?php
/**
 * Custom database tables.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Definitions of the plugin's custom tables, created with dbDelta().
 *
 * Trips are a post type; transactional data lives here. All datetimes are UTC.
 * Money is decimal(19,4) next to an ISO 4217 currency code, never a float.
 * The list is static so uninstall.php can drop the tables without booting the
 * plugin. Bump WPITRAVEL_DB_VERSION after changing a definition.
 *
 * See docs/data-model.md for what each column means.
 */
final class Schema {

	/**
	 * Prefix added after $wpdb->prefix.
	 */
	public const PREFIX = 'wpitravel_';

	/**
	 * Table definitions: key => column/index SQL (dbDelta format).
	 *
	 * Format rules for dbDelta(): one column per line, two spaces after
	 * PRIMARY KEY, use KEY not INDEX, lowercase types.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		return array(
			'departures'    => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
trip_id bigint(20) unsigned NOT NULL,
start_date date NOT NULL,
end_date date DEFAULT NULL,
start_time varchar(5) NOT NULL DEFAULT '',
capacity int(10) unsigned DEFAULT NULL,
seats_booked int(10) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'open',
source varchar(20) NOT NULL DEFAULT 'fixed',
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY trip_slot (trip_id,start_date,start_time),
KEY start_date (start_date,status)",
			'holds'         => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
hold_key char(32) NOT NULL,
departure_id bigint(20) unsigned NOT NULL,
trip_id bigint(20) unsigned NOT NULL DEFAULT 0,
seats int(10) unsigned NOT NULL,
selection longtext NULL,
expires_at datetime NOT NULL,
client char(32) NOT NULL DEFAULT \'\',
created datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY hold_key (hold_key),
KEY departure_id (departure_id,expires_at),
KEY client (client,expires_at)',
			'customers'     => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
email varchar(191) NOT NULL,
first_name varchar(100) NOT NULL DEFAULT '',
last_name varchar(100) NOT NULL DEFAULT '',
phone varchar(50) NOT NULL DEFAULT '',
country char(2) NOT NULL DEFAULT '',
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY email (email),
KEY user_id (user_id)",
			'bookings'      => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_key char(32) NOT NULL,
trip_id bigint(20) unsigned NOT NULL,
departure_id bigint(20) unsigned NOT NULL DEFAULT 0,
start_date date NOT NULL,
start_time varchar(5) NOT NULL DEFAULT '',
price_option varchar(64) NOT NULL DEFAULT '',
payment_method varchar(40) NOT NULL DEFAULT '',
customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'pending',
payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
currency char(3) NOT NULL,
subtotal decimal(19,4) NOT NULL DEFAULT 0,
discount_total decimal(19,4) NOT NULL DEFAULT 0,
tax_total decimal(19,4) NOT NULL DEFAULT 0,
total decimal(19,4) NOT NULL DEFAULT 0,
amount_paid decimal(19,4) NOT NULL DEFAULT 0,
pax int(10) unsigned NOT NULL DEFAULT 0,
coupon_code varchar(64) NOT NULL DEFAULT '',
customer_note text NULL,
locale varchar(20) NOT NULL DEFAULT '',
ip varchar(45) NOT NULL DEFAULT '',
meta longtext NULL,
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY booking_key (booking_key),
KEY trip_date (trip_id,start_date),
KEY departure_id (departure_id),
KEY customer_id (customer_id),
KEY user_id (user_id),
KEY status (status,created)",
			'booking_items' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
item_type varchar(20) NOT NULL,
object_key varchar(64) NOT NULL DEFAULT '',
label varchar(255) NOT NULL DEFAULT '',
quantity int(10) unsigned NOT NULL DEFAULT 1,
unit_amount decimal(19,4) NOT NULL DEFAULT 0,
total decimal(19,4) NOT NULL DEFAULT 0,
meta longtext NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id,item_type)",
			'travelers'     => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
category varchar(64) NOT NULL DEFAULT '',
is_lead tinyint(1) unsigned NOT NULL DEFAULT 0,
first_name varchar(100) NOT NULL DEFAULT '',
last_name varchar(100) NOT NULL DEFAULT '',
email varchar(191) NOT NULL DEFAULT '',
phone varchar(50) NOT NULL DEFAULT '',
birth_date date DEFAULT NULL,
gender varchar(20) NOT NULL DEFAULT '',
nationality char(2) NOT NULL DEFAULT '',
meta longtext NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id)",
			'payments'      => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
gateway varchar(40) NOT NULL,
transaction_id varchar(191) DEFAULT NULL,
reference varchar(191) DEFAULT NULL,
parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
payment_type varchar(10) NOT NULL DEFAULT 'payment',
status varchar(20) NOT NULL DEFAULT 'pending',
amount decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
mode varchar(10) NOT NULL DEFAULT 'live',
note varchar(255) NOT NULL DEFAULT '',
gateway_data longtext NULL,
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY gateway_txn (gateway,transaction_id),
KEY gateway_reference (gateway,reference),
KEY booking_id (booking_id),
KEY parent_id (parent_id),
KEY status (status,created)",
			'coupons'       => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
code varchar(64) NOT NULL,
description varchar(255) NOT NULL DEFAULT '',
discount_type varchar(10) NOT NULL DEFAULT 'percent',
amount decimal(19,4) NOT NULL DEFAULT 0,
currency char(3) NOT NULL DEFAULT '',
min_subtotal decimal(19,4) NOT NULL DEFAULT 0,
usage_limit int(10) unsigned DEFAULT NULL,
usage_count int(10) unsigned NOT NULL DEFAULT 0,
per_customer_limit int(10) unsigned DEFAULT NULL,
trip_ids text NULL,
starts_at datetime DEFAULT NULL,
expires_at datetime DEFAULT NULL,
status varchar(20) NOT NULL DEFAULT 'active',
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY code (code),
KEY status (status)",
			'enquiries'     => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
trip_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
name varchar(200) NOT NULL DEFAULT '',
email varchar(191) NOT NULL,
phone varchar(50) NOT NULL DEFAULT '',
message text NOT NULL,
status varchar(20) NOT NULL DEFAULT 'new',
ip varchar(45) NOT NULL DEFAULT '',
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
KEY trip_id (trip_id),
KEY status (status,created)",
			'booking_log'   => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
event varchar(40) NOT NULL,
message text NULL,
context longtext NULL,
actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
created datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id,created)',
		);
	}

	/**
	 * Full table name for the current site.
	 *
	 * @param string $key Table key from definitions().
	 */
	public static function table( string $key ): string {
		global $wpdb;

		return $wpdb->prefix . self::PREFIX . $key;
	}

	/**
	 * Full names of every table for the current site, keyed by table key.
	 *
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		$names = array();

		foreach ( array_keys( self::definitions() ) as $key ) {
			$names[ $key ] = self::table( $key );
		}

		return $names;
	}

	/**
	 * Keys of tables that do not exist on the current site.
	 *
	 * @return list<string>
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$missing = array();

		foreach ( self::table_names() as $key => $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check on the plugin's own tables.

			if ( $found !== $table ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Creates or updates all tables for the current site.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::table_names() as $key => $table ) {
			$columns = self::definitions()[ $key ];
			dbDelta( "CREATE TABLE {$table} (\n{$columns}\n) {$charset_collate};" );
		}
	}

	/**
	 * Drops all tables for the current site (uninstall only).
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables.
		}
	}
}
