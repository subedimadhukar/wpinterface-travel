/**
 * Shown when the customer comes back from an online payment page.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import PayAgain from './PayAgain';

export default function PaymentResult( { result, config, onDone } ) {
	const [ instructions, setInstructions ] = useState( '' );
	const { status, number, bookingKey } = result;

	const messages = {
		paid: sprintf(
			/* translators: %s: booking number. */
			__(
				'Thank you! We received your payment for booking %s.',
				'wpinterface-travel'
			),
			number
		),
		pending: sprintf(
			/* translators: %s: booking number. */
			__(
				'Your payment for booking %s is being processed. We will let you know when it is complete.',
				'wpinterface-travel'
			),
			number
		),
		unpaid: sprintf(
			/* translators: %s: booking number. */
			__(
				'The payment was not completed. Booking %s is saved but not paid yet.',
				'wpinterface-travel'
			),
			number
		),
	};
	const kind = [ 'paid', 'pending' ].includes( status ) ? status : 'unpaid';

	return (
		<div className="wpitravel-confirmation" role="status">
			<h2 className="wpitravel-booking__title">
				{ kind === 'unpaid'
					? __( 'Payment not completed', 'wpinterface-travel' )
					: __( 'Booking received', 'wpinterface-travel' ) }
			</h2>
			<p>{ messages[ kind ] }</p>
			{ kind === 'unpaid' && ! instructions && (
				<PayAgain
					bookingKey={ bookingKey }
					gateways={ config.gateways }
					onInstructions={ setInstructions }
				/>
			) }
			{ instructions && (
				<p className="wpitravel-instructions">{ instructions }</p>
			) }
			<button type="button" className="wpitravel-link" onClick={ onDone }>
				{ __( 'Book another date', 'wpinterface-travel' ) }
			</button>
		</div>
	);
}
