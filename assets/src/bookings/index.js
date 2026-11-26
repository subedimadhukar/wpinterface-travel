/**
 * Travel → Bookings screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import BookingsApp from './BookingsApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-bookings' );

	if ( container && window.wpitravelBookings ) {
		createRoot( container ).render(
			<BookingsApp config={ window.wpitravelBookings } />
		);
	}
} );
