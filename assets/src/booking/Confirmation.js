/**
 * Shown after the booking was created.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import QuoteSummary from './QuoteSummary';
import PayAgain from './PayAgain';
import { formatDate } from './dates';

export default function Confirmation( { booking, trip, quote, config } ) {
	const [ instructions, setInstructions ] = useState( '' );

	return (
		<div className="wpitravel-confirmation" role="status">
			<h2 className="wpitravel-booking__title">
				{ __( 'Booking received', 'wpinterface-travel' ) }
			</h2>
			<p>
				{ sprintf(
					/* translators: %s: booking number. */
					__( 'Your booking number is %s.', 'wpinterface-travel' ),
					booking.number
				) }
			</p>
			<p className="wpitravel-booking__muted">
				{ trip.title } · { formatDate( booking.date ) }
				{ booking.time && ` · ${ booking.time }` }
			</p>
			{ quote && (
				<QuoteSummary quote={ quote } currency={ config.currency } />
			) }
			{ booking.payment?.action === 'error' && (
				<>
					<p className="wpitravel-booking__error" role="alert">
						{ booking.payment.message }
					</p>
					<PayAgain
						bookingKey={ booking.booking_key }
						gateways={ config.gateways }
						onInstructions={ setInstructions }
					/>
				</>
			) }
			{ ( instructions || booking.payment?.instructions ) && (
				<p className="wpitravel-instructions">
					{ instructions || booking.payment.instructions }
				</p>
			) }
			{ booking.payment?.action === 'none' &&
				! booking.payment?.instructions && (
					<p>
						{ __(
							'The organizer will confirm your booking and contact you about payment.',
							'wpinterface-travel'
						) }
					</p>
				) }
		</div>
	);
}
