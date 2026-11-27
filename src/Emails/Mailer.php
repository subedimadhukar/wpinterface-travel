<?php
/**
 * Email sending.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Emails;

use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template with merge tags into the HTML layout and sends it with
 * wp_mail(), so SMTP plugins work as usual.
 *
 * Customer emails are written in the language the customer booked in.
 * Every booking email is recorded in the booking's history.
 */
final class Mailer {

	/**
	 * Sends a booking email.
	 *
	 * @param string               $template Template ID.
	 * @param array<string, mixed> $booking  Booking.
	 * @param array<string, mixed> $extra    Extra tag values (payment_amount, cancellation_reason).
	 * @param string               $to       Override recipient (tests, "send test").
	 * @return bool Sent.
	 */
	public function send_booking( string $template, array $booking, array $extra = array(), string $to = '' ): bool {
		$definition = Templates::get( $template );

		if ( ! $definition || empty( $definition['enabled'] ) ) {
			return false;
		}

		/**
		 * Filters whether a booking email is sent (e.g. staff entering a
		 * booking by hand can leave the customer emails out).
		 *
		 * @param bool   $send     Whether to send.
		 * @param string $template Template ID.
		 * @param array  $booking  Booking.
		 */
		if ( ! apply_filters( 'wpitravel_send_booking_email', true, $template, $booking ) ) {
			return false;
		}

		$customer = 'customer' === $definition['recipient'];
		$switched = $customer && '' !== (string) $booking['locale'] && switch_to_locale( (string) $booking['locale'] );
		// WPML and Polylang: translated email texts (String Translation) and trip names.
		$language = $customer ? Multilingual::switch_language( (string) $booking['locale'] ) : null;

		// Templates are read again after switching, so default texts follow the customer's language.
		$definition = (array) Templates::get( $template );
		$values     = Tags::for_booking( $booking, $extra );
		$recipients = '' !== $to ? array( $to ) : ( $customer ? array( $values['customer_email'] ) : self::admin_recipients() );
		$reply_to   = $customer ? self::admin_recipients()[0] : $values['customer_email'];
		$sent       = $this->deliver( $template, $definition, $values, $recipients, $reply_to );

		if ( $switched ) {
			restore_previous_locale();
		}

		if ( null !== $language ) {
			Multilingual::switch_language( $language );
			wpitravel()->options()->flush();
		}

		if ( '' === $to ) {
			( new BookingRepository() )->log(
				(int) $booking['id'],
				$sent ? 'email_sent' : 'email_failed',
				sprintf(
					/* translators: %s: email name, e.g. Booking confirmed. */
					$sent ? __( 'Email sent: %s', 'wpinterface-travel' ) : __( 'Email could not be sent: %s', 'wpinterface-travel' ),
					$definition['title']
				),
				array(
					'template'  => $template,
					'recipient' => $definition['recipient'],
				)
			);
		}

		return $sent;
	}

	/**
	 * Sends an enquiry email.
	 *
	 * @param string               $template Template ID.
	 * @param array<string, mixed> $enquiry  Enquiry.
	 */
	public function send_enquiry( string $template, array $enquiry ): bool {
		$definition = Templates::get( $template );

		if ( ! $definition || empty( $definition['enabled'] ) ) {
			return false;
		}

		$values     = Tags::for_enquiry( $enquiry );
		$customer   = 'customer' === $definition['recipient'];
		$recipients = $customer ? array( (string) $enquiry['email'] ) : self::admin_recipients();
		$reply_to   = $customer ? self::admin_recipients()[0] : (string) $enquiry['email'];

		return $this->deliver( $template, $definition, $values, $recipients, $reply_to );
	}

	/**
	 * Sends a template with values an add-on provides (e.g. Pro's waitlist),
	 * in the same layout and with the same From settings. Customer templates
	 * go to $to; staff templates go to the staff recipients.
	 *
	 * @param string                $template Template ID.
	 * @param array<string, string> $values   Tag values (site tags are added).
	 * @param string                $to       Customer address.
	 */
	public function send_values( string $template, array $values, string $to = '' ): bool {
		$definition = Templates::get( $template );

		if ( ! $definition || empty( $definition['enabled'] ) ) {
			return false;
		}

		$values   = array_merge( Tags::site_values(), array_map( 'strval', $values ) );
		$customer = 'customer' === $definition['recipient'];

		return $this->deliver( $template, $definition, $values, $customer ? array( $to ) : self::admin_recipients(), $customer ? self::admin_recipients()[0] : $to );
	}

	/**
	 * Renders a template to subject and HTML (previews and sending).
	 *
	 * @param array<string, mixed>  $definition Template with subject and body.
	 * @param array<string, string> $values     Tag values.
	 * @return array{subject: string, html: string}
	 */
	public static function render( array $definition, array $values ): array {
		$subject = trim( preg_replace( '/\s+/', ' ', Tags::replace( (string) $definition['subject'], $values, false ) ) );
		$body    = wpautop( Tags::replace( (string) $definition['body'], $values, true ) );
		$footer  = (string) wpitravel()->options()->get( 'emails.footer', '' );

		return array(
			'subject' => $subject,
			'html'    => self::layout( $subject, $body, '' !== $footer ? wpautop( Tags::replace( $footer, $values, true ) ) : '' ),
		);
	}

	/**
	 * Staff recipients: the setting, or the site admin email.
	 *
	 * @return list<string>
	 */
	public static function admin_recipients(): array {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) wpitravel()->options()->get( 'emails.admin_recipients', '' ) ) ), 'is_email' ) );

		return $list ? $list : array( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Renders and sends.
	 *
	 * @param string                $template   Template ID.
	 * @param array<string, mixed>  $definition Template.
	 * @param array<string, string> $values     Tag values.
	 * @param string[]              $recipients Recipients.
	 * @param string                $reply_to   Reply-To address.
	 */
	private function deliver( string $template, array $definition, array $values, array $recipients, string $reply_to ): bool {
		$recipients = array_values( array_filter( $recipients, 'is_email' ) );

		if ( empty( $recipients ) ) {
			return false;
		}

		$email      = self::render( $definition, $values );
		$from_name  = (string) wpitravel()->options()->get( 'emails.from_name', '' );
		$from_email = (string) wpitravel()->options()->get( 'emails.from_email', '' );
		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $from_email ) {
			$headers[] = sprintf( 'From: %s <%s>', '' !== $from_name ? str_replace( array( "\r", "\n", '<', '>' ), '', $from_name ) : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $from_email );
		}

		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		/**
		 * Filters an email before it is sent.
		 *
		 * @param array  $email    to, subject, message, headers.
		 * @param string $template Template ID.
		 * @param array  $values   Tag values.
		 */
		$email = (array) apply_filters(
			'wpitravel_email',
			array(
				'to'      => $recipients,
				'subject' => $email['subject'],
				'message' => $email['html'],
				'headers' => $headers,
			),
			$template,
			$values
		);

		return (bool) wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * The HTML layout. Themes can override wpitravel/emails/layout.php.
	 *
	 * @param string $heading Heading.
	 * @param string $body    Body HTML.
	 * @param string $footer  Footer HTML.
	 */
	private static function layout( string $heading, string $body, string $footer ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Used by the included template.
		$template = locate_template( 'wpitravel/emails/layout.php' );

		if ( '' === $template ) {
			$template = WPITRAVEL_PATH . 'templates/emails/layout.php';
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}
}
