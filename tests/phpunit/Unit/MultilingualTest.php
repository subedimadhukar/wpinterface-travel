<?php
/**
 * WPML and Polylang: translations share the original trip.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Emails\Tags;
use WPInterface\Travel\Frontend\BookingWidget;
use WPInterface\Travel\Frontend\TripData;
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\Multilingual;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * WPML and Polylang are not installed in the test site, so a French
 * translation is linked to the trip through the plugin's own filters.
 */
class MultilingualTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * French translation of the trip.
	 *
	 * @var int
	 */
	private int $french;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();

		$this->french = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
				'post_title'  => 'Randonnée du lac',
			)
		);
		// Copied once, then translated: different names, and a price a translator changed by mistake.
		update_post_meta(
			$this->french,
			TripMeta::PRICING,
			array(
				'options' => array(
					array(
						'key'        => 'standard',
						'label'      => 'Classique',
						'categories' => array(
							array(
								'key'   => 'adult',
								'label' => 'Adulte',
								'price' => '1',
							),
							array(
								'key'   => 'child',
								'label' => 'Enfant',
								'price' => '1',
							),
						),
					),
				),
			)
		);

		$original = $this->trip;
		$french   = $this->french;
		add_filter( 'wpitravel_original_trip_id', static fn( int $id ): int => $french === $id ? $original : $id );
		add_filter( 'wpitravel_trip_translations', static fn( array $ids, int $id ): array => in_array( $id, array( $original, $french ), true ) ? array( $original, $french ) : $ids, 10, 2 );
		add_filter( 'wpitravel_translated_post_id', static fn( int $translated, int $id, string $language ): int => $original === $id && in_array( $language, array( 'fr_FR', 'fr' ), true ) ? $french : $translated, 10, 3 );
	}

	public function test_without_a_translation_plugin_ids_are_unchanged(): void {
		remove_all_filters( 'wpitravel_original_trip_id' );
		remove_all_filters( 'wpitravel_translated_post_id' );
		remove_all_filters( 'wpitravel_trip_translations' );

		$this->assertFalse( Multilingual::active() );
		$this->assertSame( $this->french, Multilingual::original_trip_id( $this->french ) );
		$this->assertSame( $this->trip, Multilingual::translated_id( $this->trip, 'fr_FR' ) );
		$this->assertSame( array( $this->trip ), Multilingual::translations( $this->trip ) );
		$this->assertNull( Multilingual::switch_language( 'fr_FR' ) );
	}

	public function test_a_translated_trip_books_the_original_with_translated_names(): void {
		$data = BookingWidget::trip_data( get_post( $this->french ) );

		$this->assertSame( $this->trip, $data['id'], 'Departures, seats and bookings belong to the original.' );
		$this->assertSame( 'Randonnée du lac', $data['title'] );
		$this->assertSame( 'Classique', $data['options'][0]['label'] );
		$this->assertSame( 'Adulte', $data['options'][0]['categories'][0]['label'] );
		$this->assertSame( '100.00', $data['options'][0]['categories'][0]['price'], 'Prices come from the original.' );

		$this->assertSame( '100.00', TripData::price( $this->french )['amount'], 'Trip cards show the original price.' );
	}

	public function test_reviews_of_a_translation_belong_to_the_original_and_every_translation_shows_the_rating(): void {
		update_option( 'wpitravel_settings', array( 'reviews' => array( 'who' => 'anyone' ) ) );
		wpitravel()->options()->flush();

		$result = ( new Reviews() )->create(
			$this->french,
			array(
				'rating'  => 4,
				'content' => 'Superbe randonnée, guide parfait.',
				'name'    => 'Amélie',
				'email'   => 'amelie@example.com',
			)
		);
		$this->assertIsArray( $result );

		$comment = get_comment( (int) $result['id'] );
		$this->assertSame( $this->trip, (int) $comment->comment_post_ID );

		wp_set_comment_status( $comment, 'approve' );
		Reviews::recalculate( $this->french );

		foreach ( array( $this->trip, $this->french ) as $id ) {
			$this->assertSame( 1, Reviews::summary( $id )['count'], (string) $id );
			$this->assertEquals( 4.0, Reviews::summary( $id )['average'] );
		}
	}

	public function test_bookings_keep_the_page_language_and_emails_name_the_trip_in_it(): void {
		add_filter( 'get_available_languages', static fn(): array => array( 'fr_FR' ) );

		$booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold(), array( 'locale' => 'fr_FR' ) )->get_data()['booking_key'] );

		$this->assertSame( $this->trip, (int) $booking['trip_id'] );
		$this->assertSame( 'fr_FR', $booking['locale'] );

		$values = Tags::for_booking( $booking );
		$this->assertSame( 'Randonnée du lac', $values['trip_title'] );
		$this->assertSame( get_permalink( $this->french ), $values['trip_link'] );
	}

	public function test_an_unknown_language_falls_back_to_the_site_language(): void {
		$booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold(), array( 'locale' => 'xx_XX' ) )->get_data()['booking_key'] );

		$this->assertSame( determine_locale(), $booking['locale'] );
	}

	public function test_polylang_copies_numbers_and_settings_and_copies_prices_once(): void {
		$integration = new Multilingual();

		$synced = $integration->polylang_copy_meta( array( 'other' ), true, $this->trip );
		$this->assertContains( 'other', $synced );
		$this->assertContains( TripMeta::SCHEDULE, $synced );
		$this->assertContains( TripMeta::DURATION, $synced );
		$this->assertNotContains( TripMeta::PRICING, $synced, 'Price option names are translated after the first copy.' );
		$this->assertNotContains( TripMeta::ITINERARY, $synced );

		$this->assertContains( TripMeta::PRICING, $integration->polylang_copy_meta( array(), false, $this->trip ) );
		$this->assertSame( array( 'other' ), $integration->polylang_copy_meta( array( 'other' ), true, self::factory()->post->create() ), 'Other post types are left alone.' );

		$this->assertArrayHasKey( PostTypes::TRIP, $integration->polylang_post_types( array(), false ) );
		$this->assertArrayHasKey( PostTypes::DESTINATION, $integration->polylang_taxonomies( array(), false ) );
	}

	public function test_wpml_config_ships_and_covers_every_trip_field(): void {
		$file = dirname( __DIR__, 3 ) . '/wpml-config.xml';
		$xml  = simplexml_load_file( $file );

		$this->assertNotFalse( $xml );

		$fields = array();
		foreach ( $xml->{'custom-fields'}->{'custom-field'} as $field ) {
			$fields[ (string) $field ] = (string) $field['action'];
		}

		foreach ( Multilingual::COPY_META as $key ) {
			$this->assertSame( 'copy', $fields[ $key ] ?? '', $key );
		}
		foreach ( Multilingual::COPY_ONCE_META as $key ) {
			$this->assertSame( 'copy-once', $fields[ $key ] ?? '', $key );
		}
		foreach ( Multilingual::TRANSLATE_META as $key ) {
			$this->assertSame( 'translate', $fields[ $key ] ?? '', $key );
		}

		$reflection = new \ReflectionClass( TripMeta::class );
		foreach ( $reflection->getConstants() as $value ) {
			if ( is_string( $value ) && 0 === strpos( $value, '_wpitravel_' ) ) {
				$this->assertArrayHasKey( $value, $fields, $value . ' is missing from wpml-config.xml.' );
			}
		}

		$package = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/package.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$this->assertContains( 'wpml-config.xml', $package['files'], 'The release zip includes it.' );
	}
}
