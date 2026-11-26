/**
 * "Pay with …" buttons for a saved booking whose payment did not complete.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { postPay } from './api';

export default function PayAgain( { bookingKey, gateways, onInstructions } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const pay = async ( gateway ) => {
		setBusy( gateway );
		setError( '' );
		try {
			const { payment } = await postPay( bookingKey, gateway );
			if ( payment.action === 'redirect' && payment.url ) {
				window.location.assign( payment.url );
				return;
			}
			onInstructions( payment.instructions || '' );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	if ( gateways.length === 0 ) {
		return null;
	}

	return (
		<div className="wpitravel-pay-again">
			{ gateways.map( ( gateway ) => (
				<button
					key={ gateway.id }
					type="button"
					className="wpitravel-button"
					disabled={ !! busy }
					onClick={ () => pay( gateway.id ) }
				>
					{ busy === gateway.id
						? __( 'Please wait…', 'wpinterface-travel' )
						: sprintf(
								/* translators: %s: payment method name. */
								__( 'Pay with %s', 'wpinterface-travel' ),
								gateway.title
						  ) }
				</button>
			) ) }
			{ error && (
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
