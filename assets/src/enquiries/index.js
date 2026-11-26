/**
 * Travel → Enquiries screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import EnquiriesApp from './EnquiriesApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-enquiries' );

	if ( container && window.wpitravelEnquiries ) {
		createRoot( container ).render(
			<EnquiriesApp config={ window.wpitravelEnquiries } />
		);
	}
} );
