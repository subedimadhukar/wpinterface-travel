/**
 * Booking widget: mounts on every .wpitravel-booking element.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import BookingApp from './BookingApp';
import EnquiryForm from './EnquiryForm';
import './style.scss';

domReady( () => {
	const config = window.wpitravelBooking;

	if ( ! config ) {
		return;
	}

	document
		.querySelectorAll(
			'.wpitravel-booking[data-trip-id]:not(.wpitravel-enquiry)'
		)
		.forEach( ( element ) => {
			const trip = config.trips?.[ element.dataset.tripId ];

			if ( trip ) {
				createRoot( element ).render(
					<BookingApp trip={ trip } config={ config } />
				);
			}
		} );

	document.querySelectorAll( '.wpitravel-enquiry' ).forEach( ( element ) => {
		createRoot( element ).render(
			<EnquiryForm
				tripId={ Number( element.dataset.tripId || 0 ) }
				user={ config.user }
			/>
		);
	} );
} );
