<?php
/**
 * Regression tests for the WordPress.org review checklist audit.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use DOMDocument;
use DOMElement;
use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Booking\Holds;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Frontend\Parts;
use WPInterface\Travel\Frontend\Schema;
use WPInterface\Travel\Frontend\Shortcodes;
use WPInterface\Travel\Frontend\Templates;
use WPInterface\Travel\Frontend\TripData;
use WPInterface\Travel\Frontend\TripQuery;
use WPInterface\Travel\Install\Schema as DbSchema;
use WPInterface\Travel\Rest\Controller;
use WPInterface\Travel\Setup\SampleData;

/**
 * Covers each fix from the permissions, input and output audits, plus
 * source scans so the same kind of problem cannot come back.
 */
class ReviewChecklistTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Shipped PHP files (as in the release zip).
	 *
	 * @return list<string>
	 */
	private static function shipped_php(): array {
		$files = array( WPITRAVEL_PATH . 'wpinterface-travel.php', WPITRAVEL_PATH . 'uninstall.php' );

		foreach ( array( 'src', 'templates' ) as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WPITRAVEL_PATH . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * Makes the fixture trip password-protected.
	 */
	private function protect_trip(): void {
		wp_update_post(
			array(
				'ID'            => $this->trip,
				'post_password' => 'secret',
			)
		);
		update_post_meta(
			$this->trip,
			'_wpitravel_itinerary',
			array(
				array(
					'title'       => 'Secret day',
					'description' => 'Hidden plan',
				),
			)
		);
	}

	// Permissions.

	public function test_password_protected_trips_show_nothing_until_unlocked(): void {
		$this->protect_trip();
		$trip = get_post( $this->trip );

		$this->assertFalse( TripData::is_viewable( $trip ) );
		$this->assertSame( '', ( new Shortcodes() )->section( 'itinerary', array( 'trip' => (string) $this->trip ) ) );
		$this->assertSame( 404, $this->api( 'GET', '/trips/' . $this->trip . '/reviews' )->get_status() );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/trips/' . $this->trip ) );
		$this->assertSame( array(), $response->get_data()['meta'], 'Trip meta is hidden over REST.' );

		$this->go_to( get_permalink( $this->trip ) );
		ob_start();
		( new Schema() )->print_head();
		( new Schema() )->print_json_ld();
		$this->assertSame( '', (string) ob_get_clean(), 'No description or JSON-LD leaks the trip.' );

		$template = (string) file_get_contents( WPITRAVEL_PATH . 'templates/single-trip.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.
		$this->assertStringContainsString( 'post_password_required()', $template, 'The page template shows only the password form.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( TripData::is_viewable( $trip ), 'Staff who can edit the trip still see it.' );
		$this->assertNotSame( '', ( new Shortcodes() )->section( 'itinerary', array( 'trip' => (string) $this->trip ) ) );
	}

	public function test_a_logged_in_user_cannot_claim_someone_elses_customer_record(): void {
		global $wpdb;

		$this->book( $this->hold() );
		$attacker = self::factory()->user->create( array( 'user_email' => 'mallory@example.com' ) );
		wp_set_current_user( $attacker );

		$this->book(
			$this->hold(),
			array(
				'customer' => array(
					'email'      => 'ada@example.com',
					'first_name' => 'Mallory',
					'last_name'  => 'Evil',
					'phone'      => '+1 555 0100',
				),
			)
		);

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, first_name, phone FROM %i WHERE email = %s', DbSchema::table( 'customers' ), 'ada@example.com' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.

		$this->assertSame( '0', (string) $row['user_id'], 'The record is not linked to another account.' );
		$this->assertSame( 'Ada', $row['first_name'], 'Name and phone stay as the customer gave them.' );
		$this->assertSame( '+44 20 1234', $row['phone'] );
	}

	// Input.

	public function test_validation_regexes_reject_a_trailing_newline(): void {
		$this->assertSame( '2030-05-01', Schedule::date( '2030-05-01' ) );
		$this->assertSame( '', Schedule::date( "2030-05-01\n" ) );
		$this->assertSame( '', Schedule::time( "10:00\n" ) );
		$this->assertNull( ( new Holds() )->find( str_repeat( 'a', 32 ) . "\n" ) );

		$flagged = array();

		foreach ( self::shipped_php() as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

			if ( preg_match_all( "/preg_(?:match|match_all)\\(\\s*'([^']*)'/", $source, $matches ) ) {
				foreach ( $matches[1] as $pattern ) {
					if ( preg_match( '/\$[\/#~][a-zA-Z]*\z/', $pattern ) ) {
						$flagged[] = basename( $file ) . ': ' . $pattern;
					}
				}
			}
		}

		$this->assertSame( array(), $flagged, 'Validation patterns end with \z, not $.' );
	}

	public function test_search_filters_are_read_one_by_one_and_sanitized(): void {
		$_GET = array(
			'trip_q'      => "<b>Lakes</b>\n",
			'destination' => 'Nepal <script>',
			'sort'        => 'PRICE_ASC"',
			'other'       => 'ignored',
		);

		$values = TripQuery::from_request();

		$this->assertSame( 'Lakes', $values['trip_q'] );
		$this->assertSame( 'nepal', $values['destination'] );
		$this->assertSame( 'price_asc', $values['sort'] );
		$this->assertArrayNotHasKey( 'other', $values );
	}

	public function test_every_superglobal_read_is_unslashed_and_sanitized(): void {
		$flagged = array();

		foreach ( self::shipped_php() as $file ) {
			foreach ( file( $file ) as $number => $line ) {
				if ( ! preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/', $line ) ) {
					continue;
				}

				// Allowed: isset/empty/is_string checks, key listing, and reads wrapped in wp_unslash().
				$stripped = preg_replace( '/(isset|empty|is_string)\(\s*\$_[A-Z]+(\[[^\]]+\])?\s*\)|array_keys\(\s*\$_GET\s*\)|wp_unslash\(\s*\$_[A-Z]+\[[^\]]+\]\s*\)/', '', $line );

				if ( preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/', (string) $stripped ) ) {
					$flagged[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $flagged );
	}

	public function test_every_rest_string_argument_has_a_sanitizer(): void {
		$missing = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/wpinterface-travel/v1' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				foreach ( (array) ( $handler['args'] ?? array() ) as $name => $arg ) {
					$types = (array) ( $arg['type'] ?? array() );

					if ( ! array_intersect( $types, array( 'string', 'object' ) ) && ! ( in_array( 'array', $types, true ) && 'object' === ( $arg['items']['type'] ?? '' ) ) ) {
						continue;
					}

					$enum_ok = ( isset( $arg['enum'] ) || isset( $arg['format'] ) ) && ! isset( $arg['pattern'] );

					if ( ! isset( $arg['sanitize_callback'] ) && ! $enum_ok ) {
						$missing[] = $route . ' ' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $missing ) ) );
	}

	public function test_rest_sanitizers_clean_nested_values(): void {
		$this->assertSame( array( 'adult' => 2 ), Controller::sanitize_counts( array( 'Adult' => '2abc' ) ) );
		$this->assertSame(
			array(
				'first_name' => 'Ada',
				'questions'  => array( 'diet' => "Vegan\nno nuts" ),
				'accepted'   => true,
			),
			Controller::sanitize_object(
				array(
					'first_name' => '<b>Ada</b>',
					'questions'  => array( 'diet' => "Vegan\nno nuts<script>x</script>" ),
					'accepted'   => true,
					'bad'        => new \stdClass(),
				)
			)
		);
		$this->assertSame( '49.50', Controller::sanitize_amount( '49.50' ) );
		$this->assertSame( '49.5', Controller::sanitize_amount( 49.5 ) );
	}

	public function test_paypal_webhook_values_are_sanitized_when_read(): void {
		$source = (string) file_get_contents( WPITRAVEL_PATH . 'src/Payments/Gateways/PayPal.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

		preg_match_all( '/[^\n]*get_header\([^\n]*/', $source, $lines );
		$this->assertNotEmpty( $lines[0] );

		foreach ( $lines[0] as $line ) {
			$this->assertMatchesRegularExpression( '/sanitize_text_field\(|esc_url_raw\(/', $line );
		}
	}

	// Output.

	public function test_no_escaping_suppressions_or_literal_script_and_style_tags(): void {
		foreach ( self::shipped_php() as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

			$this->assertStringNotContainsString( 'EscapeOutput', $source, basename( $file ) . ' suppresses output escaping.' );

			if ( false !== strpos( $file, '/templates/' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/<(script|style)[\s>]/i', $source, basename( $file ) . ' prints a literal script or style tag.' );
				$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+=/', $source, basename( $file ) . ' has an inline event handler.' );
			}
		}
	}

	public function test_inline_json_is_hex_escaped(): void {
		foreach ( self::shipped_php() as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

			if ( ! preg_match_all( '/(?:wp_add_inline_script|wp_print_inline_script_tag)\((.*?);\s*$/ms', $source, $calls ) ) {
				continue;
			}

			foreach ( $calls[1] as $call ) {
				if ( false !== strpos( $call, 'wp_json_encode' ) ) {
					$this->assertStringContainsString( 'JSON_HEX_TAG', $call, basename( $file ) );
					$this->assertStringContainsString( 'JSON_HEX_AMP', $call, basename( $file ) );
				}
			}
		}
	}

	public function test_the_allow_list_keeps_every_tag_and_attribute_the_parts_print(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = SampleData::create();
		$this->assertNotInstanceOf( \WP_Error::class, $created );
		wp_set_current_user( 0 );

		// The first sample trip (the trek) fills every section. ids() must keep
		// creation order: the trips share one timestamp, so date order is random.
		$ids = SampleData::ids();
		$this->assertSame( $created, $ids );
		$trip  = (int) $ids[0];
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $trip );
		set_post_thumbnail( $trip, $image );
		update_post_meta( $trip, '_wpitravel_gallery', array( $image ) );
		$review = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $trip,
				'comment_type'     => \WPInterface\Travel\Reviews\Reviews::TYPE,
				'comment_approved' => 1,
				'comment_content'  => 'Lovely lakes.',
				'comment_author'   => 'Ada L.',
			)
		);
		add_comment_meta( $review, 'rating', 5 );
		\WPInterface\Travel\Reviews\Reviews::recalculate( $trip );

		$parts = array(
			'search' => Parts::search(
				array(
					'fields' => array( 'destination', 'activity', 'trip_type', 'difficulty', 'duration', 'price', 'sort' ),
					'target' => 'current',
				)
			),
			'trips'  => Parts::trips( array( 'columns' => 3 ) ),
		);

		foreach ( Parts::sections() as $name => $callback ) {
			$parts[ $name ] = (string) call_user_func( $callback, $trip, array() );
		}

		$this->assertSame( array(), array_keys( array_filter( $parts, static fn( string $html ): bool => '' === $html ) ), 'Every part renders something to compare.' );
		$this->assertStringContainsString( 'srcset', $parts['gallery'] . $parts['trips'], 'Responsive images are covered.' );

		foreach ( $parts as $name => $html ) {
			$this->assertSame( self::shape( $html ), self::shape( wp_kses( $html, Templates::allowed_html() ) ), $name . ' loses markup through the allow-list.' );
		}
	}

	/**
	 * Tag and attribute names of an HTML fragment, in order.
	 *
	 * @param string $html HTML.
	 * @return list<string>
	 */
	private static function shape( string $html ): array {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$shape = array();

		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( ! $element instanceof DOMElement ) {
				continue;
			}

			$attributes = array();

			foreach ( $element->attributes as $attribute ) {
				$attributes[] = $attribute->nodeName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			}

			sort( $attributes );
			$shape[] = $element->tagName . '[' // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				. implode( ',', $attributes ) . ']';
		}

		return $shape;
	}

	// Guidelines and packaging.

	public function test_every_asset_folder_the_code_loads_ships_in_the_zip(): void {
		$package = json_decode( (string) file_get_contents( WPITRAVEL_PATH . 'package.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.
		$shipped = (array) $package['files'];

		foreach ( self::shipped_php() as $file ) {
			preg_match_all( "/WPITRAVEL_URL \. 'assets\/([a-z]+)\//", (string) file_get_contents( $file ), $folders ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

			foreach ( $folders[1] as $folder ) {
				$this->assertContains( 'assets/' . $folder, $shipped, basename( $file ) . ' loads assets/' . $folder . '/, which is not in the zip.' );
			}
		}
	}

	public function test_readme_lists_what_is_sent_to_each_service(): void {
		$readme = (string) file_get_contents( WPITRAVEL_PATH . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.

		foreach ( array( 'refund reason', 'departure date', 'client ID and secret', 'webhook ID', 'no referrer' ) as $words ) {
			$this->assertStringContainsString( $words, $readme );
		}

		$this->assertStringNotContainsString( 'early development version', $readme );

		$map = (string) file_get_contents( WPITRAVEL_PATH . 'assets/src/frontend/index.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source scan.
		$this->assertStringContainsString( "referrerPolicy = 'no-referrer'", $map );
		$this->assertStringContainsString( 'https://www.openstreetmap.org/export/embed.html', $map, 'Only OpenStreetMap embeds are loaded.' );
	}
}
