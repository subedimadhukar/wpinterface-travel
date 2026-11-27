<?php
/**
 * Enquiries, reviews and personal data tools.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Privacy\PersonalData;
use WPInterface\Travel\Reviews\Reviews;

/**
 * Covers /enquiries, /trips/{id}/reviews and the privacy exporter/eraser.
 */
class EnquiriesReviewsTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		add_filter( 'comment_flood_filter', '__return_false' );
		reset_phpmailer_instance();
		wp_set_current_user( 0 );
	}

	/**
	 * A booking whose trip ended yesterday, and its review credentials.
	 *
	 * @return array<string, mixed>
	 */
	private function finished_booking(): array {
		global $wpdb;

		$booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold() )->get_data()['booking_key'] );
		$wpdb->update(
			Schema::table( 'bookings' ),
			array(
				'status'     => 'completed',
				'start_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			),
			array( 'id' => $booking['id'] )
		);

		return ( new BookingRepository() )->find( $booking['id'] );
	}

	public function test_enquiries_are_saved_and_emailed(): void {
		$response = $this->api(
			'POST',
			'/enquiries',
			array(
				'trip_id' => $this->trip,
				'name'    => 'Sam',
				'email'   => 'sam@example.com',
				'message' => 'Is this trip okay for children?',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( get_option( 'admin_email' ), 'sam@example.com' ), array_map( static fn( $mail ) => $mail['to'][0][0], tests_retrieve_phpmailer_instance()->mock_sent ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$list = $this->api( 'GET', '/enquiries' )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( 'Lake Trek', $list[0]['trip_title'] );
		$this->assertArrayNotHasKey( 'ip', $list[0] );

		$this->assertSame( 'replied', $this->api( 'PATCH', '/enquiries/' . $list[0]['id'], array( 'status' => 'replied' ) )->get_data()['status'] );
		$new_only = new \WP_REST_Request( 'GET', '/wpinterface-travel/v1/enquiries' );
		$new_only->set_query_params( array( 'status' => 'new' ) );
		$this->assertCount( 0, rest_do_request( $new_only )->get_data() );
		$this->assertSame( 200, $this->api( 'DELETE', '/enquiries/' . $list[0]['id'] )->get_status() );
	}

	public function test_enquiry_validation_and_honeypot(): void {
		$short = $this->api(
			'POST',
			'/enquiries',
			array(
				'name'    => 'Sam',
				'email'   => 'sam@example.com',
				'message' => 'Hi',
			)
		);
		$this->assertSame( 'message', $short->get_data()['data']['field'] );

		$bot = $this->api(
			'POST',
			'/enquiries',
			array(
				'name'    => 'Bot',
				'email'   => 'bot@example.com',
				'message' => 'Buy cheap watches now!!!',
				'website' => 'http://spam.example',
			)
		);
		$this->assertSame( 201, $bot->get_status(), 'Bots are told it worked…' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertCount( 0, $this->api( 'GET', '/enquiries' )->get_data(), '…but nothing is saved.' );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->api( 'GET', '/enquiries' )->get_status() );
	}

	public function test_verified_reviews(): void {
		$booking = $this->finished_booking();
		$token   = BookingAccess::token( $booking );
		$path    = "/trips/{$this->trip}/reviews";

		$this->assertTrue( Reviews::can_review( $booking ) );
		$this->assertSame(
			403,
			$this->api(
				'POST',
				$path,
				array(
					'rating'      => 5,
					'content'     => 'Wonderful lakes and a great guide.',
					'booking_key' => $booking['booking_key'],
					'token'       => 'forged',
				)
			)->get_status()
		);

		// Staff can see the booking, but cannot write a review in the customer's name.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( BookingAccess::can_access( $booking ) );
		$this->assertSame(
			403,
			$this->api(
				'POST',
				$path,
				array(
					'rating'      => 5,
					'content'     => 'Written by staff, not the customer.',
					'booking_key' => $booking['booking_key'],
				)
			)->get_status()
		);
		wp_set_current_user( 0 );

		$review = $this->api(
			'POST',
			$path,
			array(
				'rating'      => 4,
				'title'       => 'Great',
				'content'     => 'Wonderful lakes and a great guide.',
				'booking_key' => $booking['booking_key'],
				'token'       => $token,
			)
		);
		$this->assertSame( 201, $review->get_status(), wp_json_encode( $review->get_data() ) );
		$this->assertTrue( $review->get_data()['published'] );

		$listed = $this->api( 'GET', $path )->get_data();
		$this->assertSame( 4.0, (float) $listed['summary']['average'] );
		$this->assertSame( 1, $listed['summary']['count'] );
		$this->assertSame( 'Ada L.', $listed['reviews'][0]['author'], 'First name and initial only.' );
		$this->assertTrue( $listed['reviews'][0]['verified'] );

		$this->assertFalse( Reviews::can_review( $booking ), 'One review per booking.' );
		$this->assertSame(
			409,
			$this->api(
				'POST',
				$path,
				array(
					'rating'      => 1,
					'content'     => 'Trying to review twice here.',
					'booking_key' => $booking['booking_key'],
					'token'       => $token,
				)
			)->get_status()
		);
	}

	public function test_reviews_need_a_finished_trip_or_the_anyone_setting(): void {
		$upcoming = ( new BookingRepository() )->find_by_key( $this->book( $this->hold() )->get_data()['booking_key'] );
		$path     = "/trips/{$this->trip}/reviews";

		$early = $this->api(
			'POST',
			$path,
			array(
				'rating'      => 5,
				'content'     => 'Reviewing before going.',
				'booking_key' => $upcoming['booking_key'],
				'token'       => BookingAccess::token( $upcoming ),
			)
		);
		$this->assertSame( 409, $early->get_status() );

		$guest = array(
			'rating'  => 2,
			'content' => 'I heard it was average.',
			'name'    => 'Visitor',
			'email'   => 'visitor@example.com',
		);
		$this->assertSame( 403, $this->api( 'POST', $path, $guest )->get_status(), 'Verified only by default.' );

		wpitravel()->options()->update( array( 'reviews' => array( 'who' => 'anyone' ) ) );
		$held = $this->api( 'POST', $path, $guest );
		$this->assertSame( 201, $held->get_status() );
		$this->assertFalse( $held->get_data()['published'] );
		$this->assertSame( 0, Reviews::summary( $this->trip )['count'], 'Held reviews do not count.' );

		wp_set_comment_status( $held->get_data()['id'], 'approve' );
		$this->assertSame( 1, Reviews::summary( $this->trip )['count'], 'Approving updates the rating.' );
		$this->assertSame( 2.0, (float) Reviews::summary( $this->trip )['average'] );

		$this->assertFalse( comments_open( $this->trip ), 'The theme comment form stays closed on trips.' );
	}

	public function test_personal_data_export_and_erase(): void {
		$booking = ( new BookingRepository() )->find_by_key(
			$this->book(
				$this->hold(),
				array(
					'note'      => 'Allergic to nuts',
					'travelers' => array(
						array(
							'category'   => 'adult',
							'first_name' => 'Ada',
							'last_name'  => 'Lovelace',
						),
					),
				)
			)->get_data()['booking_key']
		);
		$this->api(
			'POST',
			'/enquiries',
			array(
				'name'    => 'Ada',
				'email'   => 'ada@example.com',
				'message' => 'Question before booking.',
			)
		);

		$privacy = new PersonalData();
		$export  = $privacy->export( 'ADA@example.com' );
		$groups  = array_column( $export['data'], 'group_id' );
		$this->assertSame( array( 'wpitravel-bookings', 'wpitravel-enquiries' ), $groups );
		$this->assertStringContainsString( 'Allergic to nuts', wp_json_encode( $export ) );

		$erased = $privacy->erase( 'ada@example.com' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'], 'Amounts are kept for accounting.' );

		$after    = ( new BookingRepository() )->find( $booking['id'] );
		$customer = ( new BookingRepository() )->customer( $after );
		$this->assertSame( '200.00', $after['total'] );
		$this->assertSame( '', $after['customer_note'] );
		$this->assertSame( '', $customer['first_name'] );
		$this->assertStringEndsWith( '.invalid', $customer['email'] );
		$this->assertSame( array( '' ), array_column( ( new BookingRepository() )->travelers( $booking['id'] ), 'last_name' ) );
		$this->assertSame( array(), $privacy->export( 'ada@example.com' )['data'], 'Nothing is left to export.' );
	}

	public function test_trip_page_shows_a_review_form_when_anyone_can_review(): void {
		$display = new \WPInterface\Travel\Frontend\ReviewsDisplay();

		$this->assertSame( '', $display->render( $this->trip ), 'Verified-only reviews: nothing to show before the first one.' );

		wpitravel()->options()->update( array( 'reviews' => array( 'who' => 'anyone' ) ) );
		$html = $display->render( $this->trip );

		$this->assertStringContainsString( 'No reviews yet', $html );
		$this->assertStringContainsString( 'data-wpitravel-review-form="' . $this->trip . '"', $html );
		$this->assertSame( 5, substr_count( $html, 'name="rating"' ) );
		$this->assertTrue( wp_script_is( 'wpitravel-review-form', 'enqueued' ) );

		// The form sends to the existing route; the review waits for moderation.
		$request = new \WP_REST_Request( 'POST', '/wpinterface-travel/v1/trips/' . $this->trip . '/reviews' );
		$request->set_body_params(
			array(
				'rating'  => 5,
				'name'    => 'Sam',
				'email'   => 'sam@example.com',
				'content' => 'Wonderful guides and views.',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['published'] );
	}

	public function test_add_ons_can_add_trip_parts_and_booking_links(): void {
		$part  = static fn( int $trip_id ): string => '<p>extra ' . $trip_id . '</p>';
		add_filter(
			'wpitravel_trip_parts',
			static function ( $parts ) use ( $part ) {
				$parts['extra']  = $part;
				$parts['broken'] = 'not a callable';
				return $parts;
			}
		);
		$parts = \WPInterface\Travel\Frontend\Parts::sections();
		$this->assertSame( '<p>extra 7</p>', call_user_func( $parts['extra'], 7, array() ) );
		$this->assertArrayNotHasKey( 'broken', $parts );

		add_filter(
			'wpitravel_customer_booking_links',
			static fn() => array(
				array(
					'label' => 'Guide',
					'url'   => 'https://example.com/guide.pdf',
					'note'  => '<b>PDF</b>',
				),
				array( 'label' => 'No address' ),
				array(
					'label' => 'Bad',
					'url'   => 'javascript:alert(1)',
				),
			)
		);
		$booking = $this->finished_booking();
		$view    = \WPInterface\Travel\Booking\BookingView::for_customer( $booking );
		$this->assertSame(
			array(
				array(
					'label' => 'Guide',
					'url'   => 'https://example.com/guide.pdf',
					'note'  => 'PDF',
				),
			),
			$view['links']
		);
	}
}
