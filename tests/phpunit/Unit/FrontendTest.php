<?php
/**
 * Frontend: listings, filters, templates, blocks, shortcodes and schema.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_Block_Templates_Registry;
use WP_Block_Type_Registry;
use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripIndex;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Frontend\Blocks;
use WPInterface\Travel\Frontend\BlockTemplates;
use WPInterface\Travel\Frontend\BookingWidget;
use WPInterface\Travel\Frontend\Parts;
use WPInterface\Travel\Frontend\Schema;
use WPInterface\Travel\Frontend\Templates;
use WPInterface\Travel\Frontend\TripQuery;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Options;

/**
 * Covers what visitors see.
 */
class FrontendTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();
		( new TripMeta() )->register_meta();
		delete_option( Options::OPTION );
		wpitravel()->options()->flush();
		Templates::set_plugin_template( false );
	}

	public function tear_down(): void {
		Templates::set_plugin_template( false );
		parent::tear_down();
	}

	/**
	 * Creates a published trip.
	 *
	 * @param string               $title Title.
	 * @param string|null          $price Adult price, or null for none.
	 * @param int                  $days  Days.
	 * @param array<string, mixed> $meta  More meta.
	 */
	private function trip( string $title, ?string $price, int $days, array $meta = array() ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( null !== $price ) {
			update_post_meta(
				$id,
				TripMeta::PRICING,
				array(
					'options' => array(
						array(
							'key'        => 'standard',
							'label'      => 'Standard',
							'categories' => array(
								array(
									'key'   => 'adult',
									'label' => 'Adult',
									'price' => $price,
								),
							),
						),
					),
				)
			);
		}

		update_post_meta( $id, TripMeta::DURATION, array( 'days' => $days ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Titles of a query's posts, in order.
	 *
	 * @param array<string, mixed> $raw Raw filters.
	 * @return string[]
	 */
	private function titles( array $raw ): array {
		return wp_list_pluck( TripQuery::query( TripQuery::filters( $raw ), 20 )->posts, 'post_title' );
	}

	public function test_index_follows_pricing_and_duration(): void {
		$id = $this->trip( 'Indexed', '250', 5 );

		$this->assertEqualsWithDelta( 250.0, (float) get_post_meta( $id, TripIndex::PRICE, true ), 0.001 );
		$this->assertSame( '5', get_post_meta( $id, TripIndex::DAYS, true ) );

		update_post_meta( $id, TripMeta::DURATION, array( 'hours' => 4 ) );
		$this->assertSame( '1', get_post_meta( $id, TripIndex::DAYS, true ), 'A trip of a few hours counts as one day.' );

		delete_post_meta( $id, TripMeta::PRICING );
		$this->assertSame( '', get_post_meta( $id, TripIndex::PRICE, true ) );
	}

	public function test_filters_are_cleaned(): void {
		$filters = TripQuery::filters(
			array(
				'trip_q'      => '<b>Everest</b>',
				'destination' => 'Nepal Himalaya',
				'min_price'   => '-5',
				'max_days'    => 'abc',
				'sort'        => 'DROP TABLE',
				'difficulty'  => 'impossible',
			)
		);

		$this->assertSame( 'Everest', $filters['q'] );
		$this->assertSame( 'nepal-himalaya', $filters['destination'] );
		$this->assertNull( $filters['min_price'] );
		$this->assertNull( $filters['max_days'] );
		$this->assertSame( '', $filters['sort'] );
		$this->assertSame( '', $filters['difficulty'] );
	}

	public function test_price_duration_and_destination_filters(): void {
		$cheap = $this->trip( 'Cheap', '100', 2 );
		$this->trip( 'Mid', '500', 7 );
		$this->trip( 'Dear', '1200', 14 );
		$this->trip( 'Unpriced', null, 3 );
		wp_set_object_terms( $cheap, 'nepal', PostTypes::DESTINATION );

		$this->assertEqualsCanonicalizing( array( 'Mid', 'Dear' ), $this->titles( array( 'min_price' => '200' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Cheap', 'Mid' ), $this->titles( array( 'max_price' => '999.99' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Mid', 'Dear' ), $this->titles( array( 'min_days' => '5' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Cheap', 'Unpriced' ), $this->titles( array( 'max_days' => '3' ) ) );
		$this->assertSame( array( 'Cheap' ), $this->titles( array( 'destination' => 'nepal' ) ) );
		$this->assertSame( array( 'Cheap' ), $this->titles( array( 'trip_q' => 'Cheap' ) ) );
	}

	public function test_sorting_keeps_trips_without_the_value_last(): void {
		$this->trip( 'Mid', '500', 7 );
		$this->trip( 'Unpriced', null, 3 );
		$this->trip( 'Dear', '1200', 14 );
		$this->trip( 'Cheap', '100', 10 );

		$this->assertSame( array( 'Cheap', 'Mid', 'Dear', 'Unpriced' ), $this->titles( array( 'sort' => 'price' ) ) );
		$this->assertSame( array( 'Dear', 'Mid', 'Cheap' ), array_slice( $this->titles( array( 'sort' => 'price-desc' ) ), 0, 3 ) );
		$this->assertSame( array( 'Unpriced', 'Mid', 'Cheap', 'Dear' ), $this->titles( array( 'sort' => 'duration' ) ) );
		$this->assertSame( array( 'Cheap', 'Dear', 'Mid', 'Unpriced' ), $this->titles( array( 'sort' => 'title' ) ) );
	}

	public function test_parts_escape_trip_content(): void {
		$id = $this->trip(
			'Escaped',
			'100',
			3,
			array(
				TripMeta::ITINERARY  => array(
					array(
						'title'       => 'Arrive <script>alert(1)</script>',
						'description' => '<p>Walk <strong>far</strong></p><script>alert(2)</script>',
						'meals'       => array( 'breakfast' ),
					),
				),
				TripMeta::HIGHLIGHTS => array( 'Views & "lakes"' ),
			)
		);

		$itinerary = Parts::itinerary( $id );

		$this->assertStringNotContainsString( '<script>', $itinerary );
		$this->assertStringContainsString( '<strong>far</strong>', $itinerary );
		$this->assertStringContainsString( 'Day 1', $itinerary );
		$this->assertStringContainsString( 'Views &amp; &quot;lakes&quot;', Parts::highlights( $id ) );
		$this->assertSame( '', Parts::faqs( $id ), 'Empty sections render nothing.' );
	}

	public function test_map_waits_for_the_visitor(): void {
		$id = $this->trip(
			'Mapped',
			'100',
			3,
			array(
				TripMeta::MAP => array(
					'lat'  => 27.7,
					'lng'  => 85.3,
					'zoom' => 9,
				),
			)
		);

		$html = Parts::map( $id );

		$this->assertStringContainsString( 'data-wpitravel-map="https://www.openstreetmap.org/export/embed.html?', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertSame( '', Parts::map( $this->trip( 'No map', '100', 3 ) ) );
	}

	public function test_theme_can_override_a_part(): void {
		$file = get_temp_dir() . 'wpitravel-highlights-override.php';
		file_put_contents( $file, '<p>OVERRIDE <?php echo count( $args[\'items\'] ); ?></p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$filter = static fn( string $template, string $name ): string => 'parts/highlights' === $name ? $file : $template;
		add_filter( 'wpitravel_template_part', $filter, 10, 2 );

		$html = Parts::highlights( $this->trip( 'Override', '100', 1, array( TripMeta::HIGHLIGHTS => array( 'a', 'b' ) ) ) );

		remove_filter( 'wpitravel_template_part', $filter );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( '<p>OVERRIDE 2</p>', $html );
	}

	public function test_blocks_and_block_templates_are_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array_merge( array( 'trips', 'trip-search' ), array_keys( Blocks::TRIP_BLOCKS ) ) as $block ) {
			$this->assertTrue( $registry->is_registered( 'wpitravel/' . $block ), $block );
		}

		foreach ( array_keys( BlockTemplates::templates() ) as $slug ) {
			$this->assertTrue( WP_Block_Templates_Registry::get_instance()->is_registered( BlockTemplates::PLUGIN . '//' . $slug ), $slug );
		}
	}

	public function test_trip_blocks_render_the_given_trip_and_hide_drafts(): void {
		$id    = $this->trip( 'Block trip', '100', 4, array( TripMeta::GROUP_SIZE => array( 'max' => 12 ) ) );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'draft',
			)
		);
		update_post_meta( $draft, TripMeta::DURATION, array( 'days' => 9 ) );

		$html = do_blocks( '<!-- wp:wpitravel/trip-facts {"tripId":' . $id . '} /-->' );

		$this->assertStringContainsString( 'wp-block-wpitravel-trip-facts', $html );
		$this->assertStringContainsString( '4 days', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:wpitravel/trip-facts {"tripId":' . $draft . '} /-->' ) ) );
	}

	public function test_trips_block_and_shortcode_list_published_trips(): void {
		$this->trip( 'Alpha', '100', 2 );
		$this->trip( 'Beta', '200', 3 );
		self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'draft',
				'post_title'  => 'Hidden',
			)
		);

		$block     = do_blocks( '<!-- wp:wpitravel/trips {"sort":"price","columns":2} /-->' );
		$shortcode = do_shortcode( '[wpitravel_trips sort="price-desc" number="1"]' );

		$this->assertMatchesRegularExpression( '/Alpha.*Beta/s', $block );
		$this->assertStringNotContainsString( 'Hidden', $block );
		$this->assertStringContainsString( '--wpitravel-columns: 2', $block );
		$this->assertStringContainsString( 'Beta', $shortcode );
		$this->assertStringNotContainsString( 'Alpha', $shortcode );
		$this->assertStringContainsString( 'trips_page=2', $shortcode, 'One trip per page leaves a second page.' );
	}

	public function test_search_form_keeps_current_values_escaped(): void {
		$_GET = array(
			'trip_q'    => '"><script>x</script>',
			'min_price' => '50',
		);

		$html = do_shortcode( '[wpitravel_trip_search fields="q,price"]' );
		$_GET = array();

		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( 'name="min_price" value="50"', $html );
		$this->assertStringNotContainsString( 'name="destination"', $html );
	}

	public function test_schema_describes_the_trip(): void {
		$id   = $this->trip( 'Schema </script> trek', '1100', 6, array( TripMeta::ITINERARY => array( array( 'title' => 'Arrive' ) ) ) );
		$data = Schema::data( get_post( $id ) );

		$this->assertSame( 'TouristTrip', $data['@type'] );
		$this->assertSame( 'USD', $data['offers']['priceCurrency'] );
		$this->assertEqualsWithDelta( 1100.0, (float) $data['offers']['price'], 0.001 );
		$this->assertSame( 'Arrive', $data['itinerary']['itemListElement'][0]['item']['name'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $data, 'No rating without reviews.' );

		$this->go_to( get_permalink( $id ) );
		ob_start();
		( new Schema() )->print_head();
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'application/ld+json', $head );
		$this->assertSame( 1, substr_count( $head, '</script>' ), 'The title cannot close the script tag.' );
		$this->assertStringContainsString( 'og:title', $head );

		add_filter( 'wpitravel_seo_plugin_active', '__return_true' );
		ob_start();
		( new Schema() )->print_head();
		$head = (string) ob_get_clean();
		remove_filter( 'wpitravel_seo_plugin_active', '__return_true' );

		$this->assertStringNotContainsString( 'og:title', $head );
	}

	public function test_classic_themes_get_the_plugin_template_and_no_duplicate_widget(): void {
		if ( ! wp_get_theme( 'twentytwentyone' )->exists() ) {
			$this->markTestSkipped( 'Needs the Twenty Twenty-One theme.' );
		}

		switch_theme( 'twentytwentyone' );
		$id = $this->trip( 'Classic', '100', 3 );
		$this->go_to( get_permalink( $id ) );

		$template = ( new Templates() )->template_include( get_stylesheet_directory() . '/single.php' );

		$this->assertSame( WPITRAVEL_PATH . 'templates/single-trip.php', $template );
		$this->assertTrue( Templates::renders_parts() );

		$theme_template = '/theme/single-' . PostTypes::TRIP . '.php';
		$this->assertSame( $theme_template, ( new Templates() )->template_include( $theme_template ), 'A theme template for trips wins.' );

		// The template places the booking form itself, so the_content must not add another.
		$GLOBALS['wp_query']->in_the_loop = true;
		$this->assertSame( 'Body', ( new BookingWidget() )->append_to_trip( 'Body' ) );

		switch_theme( WP_DEFAULT_THEME );
	}
}
