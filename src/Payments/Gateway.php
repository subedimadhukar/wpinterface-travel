<?php
/**
 * Payment gateway base.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for payment methods. Pro and add-ons register subclasses with
 * the `wpitravel_payment_gateways` filter.
 *
 * Offline methods (bank transfer, pay later) only show instructions; staff
 * record the payment when it arrives. Online methods (is_online() true) get
 * a pending ledger row before start() and must complete it only after the
 * provider confirms the money, either on return or by a verified webhook.
 */
abstract class Gateway {

	/**
	 * Unique ID, e.g. "paypal". Used in settings, the ledger and webhook URLs.
	 */
	abstract public function id(): string;

	/**
	 * Name shown to staff.
	 */
	abstract public function admin_title(): string;

	/**
	 * Starts a payment.
	 *
	 * @param array<string, mixed>      $booking Booking.
	 * @param array<string, mixed>|null $payment Pending ledger row (online gateways), else null.
	 * @return array{action: string, url?: string, client_data?: array<string, mixed>, instructions?: string}|WP_Error
	 */
	abstract public function start( array $booking, ?array $payment );

	/**
	 * Whether the customer pays on the provider's side (redirect or card form).
	 */
	public function is_online(): bool {
		return false;
	}

	/**
	 * "live", or "test" when the gateway uses the provider's sandbox.
	 */
	public function mode(): string {
		return 'live';
	}

	/**
	 * Default settings. Every gateway has enabled, title and description.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'     => false,
			'title'       => $this->admin_title(),
			'description' => '',
		);
	}

	/**
	 * Extra settings fields for the settings screen (beyond enabled, title, description).
	 *
	 * @return list<array{key: string, label: string, type: string, help?: string}>
	 */
	public function settings_fields(): array {
		return array();
	}

	/**
	 * Settings keys holding secrets. They are never sent back to the browser.
	 *
	 * @return list<string>
	 */
	public function secret_fields(): array {
		return array();
	}

	/**
	 * Current settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return array_merge(
			$this->defaults(),
			(array) wpitravel()->options()->get( 'payments.gateways.' . $this->id(), array() )
		);
	}

	/**
	 * Whether staff turned it on.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->settings()['enabled'] );
	}

	/**
	 * Whether customers can use it for a payment in this currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public function is_available( string $currency ): bool {
		return $this->is_enabled() && '' !== $currency;
	}

	/**
	 * Name shown to customers.
	 */
	public function title(): string {
		$title = (string) $this->settings()['title'];

		return '' !== $title ? $title : $this->admin_title();
	}

	/**
	 * Short text shown under the name at checkout.
	 */
	public function description(): string {
		return (string) $this->settings()['description'];
	}

	/**
	 * Handles the customer coming back from the provider.
	 *
	 * @param array<string, string> $params Query parameters.
	 * @return string paid, pending, cancelled or failed.
	 */
	public function handle_return( array $params ): string {
		unset( $params );

		return 'pending';
	}

	/**
	 * Handles a provider notification at POST /payments/{id}/webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		unset( $request );

		return new WP_Error( 'wpitravel_webhook_unsupported', __( 'This payment method has no webhook.', 'wpinterface-travel' ), array( 'status' => 404 ) );
	}

	/**
	 * Whether refunds go back through the provider.
	 */
	public function supports_refunds(): bool {
		return false;
	}

	/**
	 * Refunds part or all of a completed payment through the provider.
	 *
	 * @param array<string, mixed> $payment Completed payment.
	 * @param string               $amount  Amount to refund.
	 * @param string               $reason  Reason.
	 * @return array{transaction_id: string, data?: array<string, mixed>}|WP_Error
	 */
	public function refund( array $payment, string $amount, string $reason ) {
		unset( $payment, $amount, $reason );

		return new WP_Error( 'wpitravel_refund_unsupported', __( 'This payment method cannot refund automatically.', 'wpinterface-travel' ), array( 'status' => 400 ) );
	}

	/**
	 * Fields for the settings screen, including the shared ones.
	 *
	 * @return array<string, mixed>
	 */
	public function to_settings_schema(): array {
		return array(
			'id'          => $this->id(),
			'title'       => $this->admin_title(),
			'online'      => $this->is_online(),
			'webhook_url' => $this->is_online() ? rest_url( 'wpinterface-travel/v1/payments/' . $this->id() . '/webhook' ) : '',
			'fields'      => array_merge(
				array(
					array(
						'key'   => 'title',
						'label' => __( 'Name at checkout', 'wpinterface-travel' ),
						'type'  => 'text',
					),
					array(
						'key'   => 'description',
						'label' => __( 'Description at checkout', 'wpinterface-travel' ),
						'type'  => 'textarea',
					),
				),
				$this->settings_fields()
			),
		);
	}
}
