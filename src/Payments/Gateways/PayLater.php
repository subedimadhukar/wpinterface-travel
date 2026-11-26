<?php
/**
 * Pay later.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Payments\Gateways;

use WPInterface\Travel\Payments\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Book now, pay later (on arrival or as agreed). Staff record the payment.
 */
class PayLater extends Gateway {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'pay_later';
	}

	/**
	 * Staff-facing name.
	 */
	public function admin_title(): string {
		return __( 'Pay later', 'wpinterface-travel' );
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'      => false,
			'title'        => __( 'Pay later', 'wpinterface-travel' ),
			'description'  => __( 'Book now and pay later, as agreed with us.', 'wpinterface-travel' ),
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
				'label' => __( 'Instructions after booking', 'wpinterface-travel' ),
				'type'  => 'textarea',
				'help'  => __( 'e.g. "Pay in cash or by card when you arrive at our office."', 'wpinterface-travel' ),
			),
		);
	}

	/**
	 * Shows the instructions.
	 *
	 * @param array<string, mixed>      $booking Booking.
	 * @param array<string, mixed>|null $payment Unused.
	 * @return array{action: string, instructions: string}
	 */
	public function start( array $booking, ?array $payment ): array {
		unset( $booking, $payment );

		return array(
			'action'       => 'none',
			'instructions' => trim( (string) $this->settings()['instructions'] ),
		);
	}
}
