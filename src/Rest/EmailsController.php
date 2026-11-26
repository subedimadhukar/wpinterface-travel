<?php
/**
 * Email preview, test and resend endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Emails\Mailer;
use WPInterface\Travel\Emails\Tags;
use WPInterface\Travel\Emails\Templates;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * POST /emails/{template}/preview and /test (settings screen) and
 * POST /bookings/{id}/emails (resend an email of a booking).
 */
final class EmailsController extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$draft = array(
			'subject' => array(
				'type'    => 'string',
				'default' => '',
			),
			'body'    => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		foreach ( array( 'preview', 'test' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/emails/(?P<template>[a-z_]+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SETTINGS ),
					'args'                => $draft,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/emails',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => $this->require_capability( Capabilities::MANAGE_BOOKINGS ),
				'args'                => array(
					'template' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Renders a template (with unsaved edits) using sample data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$definition = $this->draft( $request );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		return new WP_REST_Response( Mailer::render( $definition, self::sample( (string) $definition['context'] ) ) );
	}

	/**
	 * Sends a template with sample data to the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$definition = $this->draft( $request );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$to    = wp_get_current_user()->user_email;
		$email = Mailer::render( $definition, self::sample( (string) $definition['context'] ) );
		$sent  = wp_mail( $to, '[' . __( 'Test', 'wpinterface-travel' ) . '] ' . $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $sent ) {
			return new WP_Error( 'wpitravel_email_failed', __( 'The test email could not be sent. Check your site\'s email (SMTP) settings.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'sent_to' => $to ) );
	}

	/**
	 * Sends one email of a booking again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend( WP_REST_Request $request ) {
		$booking    = ( new BookingRepository() )->find( (int) $request['id'] );
		$definition = Templates::definitions()[ (string) $request['template'] ] ?? null;

		if ( ! $booking ) {
			return new WP_Error( 'wpitravel_booking_not_found', __( 'Booking not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		if ( ! $definition || 'booking' !== $definition['context'] ) {
			return new WP_Error( 'wpitravel_invalid_template', __( 'Unknown email.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		if ( ! ( new Mailer() )->send_booking( (string) $request['template'], $booking ) ) {
			return new WP_Error( 'wpitravel_email_failed', __( 'The email could not be sent. It may be turned off, or the site cannot send email.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'sent' => true ) );
	}

	/**
	 * Template with the subject and body from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function draft( WP_REST_Request $request ) {
		$definition = Templates::get( (string) $request['template'] );

		if ( ! $definition ) {
			return new WP_Error( 'wpitravel_invalid_template', __( 'Unknown email.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		if ( '' !== (string) $request['subject'] ) {
			$definition['subject'] = sanitize_text_field( (string) $request['subject'] );
		}

		if ( '' !== (string) $request['body'] ) {
			$definition['body'] = wp_kses_post( (string) $request['body'] );
		}

		return $definition;
	}

	/**
	 * Example values for previews and test emails (no real customer data).
	 *
	 * @param string $context booking or enquiry.
	 * @return array<string, string>
	 */
	public static function sample( string $context ): array {
		$values = array();

		foreach ( array_keys( Tags::describe( $context ) ) as $tag ) {
			$values[ $tag ] = '[' . $tag . ']';
		}

		$examples = array(
			'site_name'            => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'             => home_url( '/' ),
			'booking_number'       => 'TRV-1042',
			'booking_status'       => __( 'Confirmed', 'wpinterface-travel' ),
			'booking_link'         => home_url( '/my-bookings/' ),
			'voucher_link'         => home_url( '/?wpitravel_voucher=example' ),
			'review_link'          => home_url( '/my-bookings/' ),
			'booking_summary'      => '<p><strong>' . esc_html__( 'Sample trip', 'wpinterface-travel' ) . '</strong><br>12 May 2030</p><table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:4px 0">Adult × 2</td><td style="padding:4px 0;text-align:right">$900.00</td></tr><tr><td style="padding:8px 0 4px;border-top:1px solid #ddd"><strong>Total</strong></td><td style="padding:8px 0 4px;border-top:1px solid #ddd;text-align:right"><strong>$900.00</strong></td></tr></table>',
			'customer_name'        => 'Alex Morgan',
			'customer_first_name'  => 'Alex',
			'customer_email'       => 'alex@example.com',
			'customer_phone'       => '+44 20 7946 0000',
			'customer_note'        => esc_html__( 'Vegetarian meals, please.', 'wpinterface-travel' ),
			'trip_title'           => __( 'Sample trip', 'wpinterface-travel' ),
			'trip_link'            => home_url( '/' ),
			'start_date'           => wp_date( (string) get_option( 'date_format' ), (int) strtotime( '2030-05-12 12:00:00 UTC' ) ),
			'start_time'           => '07:30',
			'end_date'             => wp_date( (string) get_option( 'date_format' ), (int) strtotime( '2030-05-16 12:00:00 UTC' ) ),
			'meeting_point'        => __( 'Hotel lobby', 'wpinterface-travel' ),
			'travelers'            => '2',
			'total'                => '$900.00',
			'amount_paid'          => '$300.00',
			'balance'              => '$600.00',
			'payment_amount'       => '$300.00',
			'payment_method'       => __( 'Bank transfer', 'wpinterface-travel' ),
			'payment_instructions' => esc_html__( 'Please transfer $600.00 and use TRV-1042 as the payment reference.', 'wpinterface-travel' ),
			'cancellation_reason'  => __( 'Change of plans.', 'wpinterface-travel' ),
			'enquiry_name'         => 'Alex Morgan',
			'enquiry_email'        => 'alex@example.com',
			'enquiry_phone'        => '+44 20 7946 0000',
			'enquiry_message'      => esc_html__( 'Is this trip suitable for a 10-year-old?', 'wpinterface-travel' ),
		);

		return array_intersect_key( $examples, $values ) + $values;
	}
}
