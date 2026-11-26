<?php
/**
 * Bank transfer.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments\Gateways;

use WPInterface\Travel\Payments\Gateway;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Shows bank details after booking. Staff record the payment when it arrives.
 */
class BankTransfer extends Gateway {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'bank_transfer';
	}

	/**
	 * Staff-facing name.
	 */
	public function admin_title(): string {
		return __( 'Bank transfer', 'wpinterface-travel' );
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'      => false,
			'title'        => __( 'Bank transfer', 'wpinterface-travel' ),
			'description'  => __( 'Pay by bank transfer. We send the details with your booking.', 'wpinterface-travel' ),
			'instructions' => '',
		);
	}

	/**
	 * Extra fields.
	 *
	 * @return list<array{key: string, label: string, type: string, help?: string}>
	 */
	public function settings_fields(): array {
		return array(
			array(
				'key'   => 'instructions',
				'label' => __( 'Bank details and instructions', 'wpinterface-travel' ),
				'type'  => 'textarea',
				'help'  => __( 'Shown after booking. Include the account holder, IBAN or account number, bank, SWIFT/BIC, and ask customers to use their booking number as the reference.', 'wpinterface-travel' ),
			),
		);
	}

	/**
	 * Shows the instructions with the booking number.
	 *
	 * @param array<string, mixed>      $booking Booking.
	 * @param array<string, mixed>|null $payment Unused.
	 * @return array{action: string, instructions: string}
	 */
	public function start( array $booking, ?array $payment ): array {
		unset( $payment );

		$instructions = trim( (string) $this->settings()['instructions'] );

		return array(
			'action'       => 'none',
			'instructions' => sprintf(
				/* translators: 1: amount with currency, 2: booking number. */
				__( 'Please transfer %1$s and use %2$s as the payment reference.', 'wpinterface-travel' ),
				Money::format( (string) ( $booking['amount_due'] ?? $booking['balance'] ), (array) wpitravel()->options()->get( 'currency', array() ) ),
				$booking['number']
			) . ( '' !== $instructions ? "\n\n" . $instructions : '' ),
		);
	}
}
