/**
 * "My bookings" page.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import AccountApp from './AccountApp';
import '../booking/style.scss';
import './style.scss';

domReady( () => {
	const config = window.wpitravelAccount;

	if ( ! config ) {
		return;
	}

	document.querySelectorAll( '.wpitravel-account' ).forEach( ( element ) => {
		createRoot( element ).render( <AccountApp config={ config } /> );
	} );
} );
