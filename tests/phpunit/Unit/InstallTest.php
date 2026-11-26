<?php
/**
 * Install, upgrade and schema tests.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Install\Upgrader;
use WPInterface\Travel\Options;
use WPInterface\Travel\Support\Capabilities;

/**
 * Covers table creation, DB versioning and capabilities on activation.
 */
class InstallTest extends WP_UnitTestCase {

	/**
	 * The core test suite turns CREATE TABLE into CREATE TEMPORARY TABLE;
	 * remove those filters so SHOW TABLES sees the plugin's tables.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_activation_creates_every_table(): void {
		Activator::activate_site();

		$this->assertSame( array(), Schema::missing_tables() );
	}

	public function test_activation_stores_db_version_and_default_option(): void {
		delete_option( Upgrader::DB_VERSION_OPTION );
		delete_option( Options::OPTION );

		Activator::activate_site();

		$this->assertSame( WPITRAVEL_DB_VERSION, (int) get_option( Upgrader::DB_VERSION_OPTION ) );
		$this->assertIsArray( get_option( Options::OPTION ) );
		$this->assertSame( WPITRAVEL_VERSION, get_option( Upgrader::VERSION_OPTION ) );
	}

	public function test_upgrade_is_idempotent(): void {
		Activator::activate_site();
		Upgrader::maybe_upgrade();
		Upgrader::maybe_upgrade();

		$this->assertSame( array(), Schema::missing_tables() );
		$this->assertSame( WPITRAVEL_DB_VERSION, (int) get_option( Upgrader::DB_VERSION_OPTION ) );
	}

	public function test_table_names_use_site_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'wpitravel_bookings', Schema::table( 'bookings' ) );
		$this->assertSame( Schema::table( 'payments' ), wpitravel_table( 'payments' ) );
	}

	public function test_payment_transaction_ids_are_unique_per_gateway(): void {
		global $wpdb;

		Activator::activate_site();

		$table = Schema::table( 'payments' );
		$row   = array(
			'booking_id'     => 1,
			'gateway'        => 'paypal',
			'transaction_id' => 'TXN-1',
			'amount'         => '10.0000',
			'currency'       => 'USD',
			'created'        => '2026-01-01 00:00:00',
			'updated'        => '2026-01-01 00:00:00',
		);

		$this->assertSame( 1, $wpdb->insert( $table, $row ) );

		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( $table, $row ), 'A replayed gateway transaction must be rejected by the database.' );
		$wpdb->suppress_errors( false );

		// Offline payments without a transaction ID may repeat.
		$row['transaction_id'] = null;
		$this->assertSame( 1, $wpdb->insert( $table, $row ) );
		$this->assertSame( 1, $wpdb->insert( $table, $row ) );

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_administrator_and_editor_capabilities(): void {
		Activator::activate_site();

		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), $cap );
		}

		$this->assertTrue( $editor->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		$this->assertFalse( $editor->has_cap( Capabilities::MANAGE_SETTINGS ) );
	}

	public function test_site_managers_always_get_plugin_capabilities(): void {
		$caps = ( new Capabilities() )->grant_to_site_managers( array( 'manage_options' => true ) );

		$this->assertTrue( $caps[ Capabilities::MANAGE_SETTINGS ] );
		$this->assertSame( array( 'read' => true ), ( new Capabilities() )->grant_to_site_managers( array( 'read' => true ) ) );
	}
}
