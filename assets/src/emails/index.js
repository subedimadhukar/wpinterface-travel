/**
 * Travel → Emails screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import EmailsApp from './EmailsApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-emails' );

	if ( container && window.wpitravelEmails ) {
		createRoot( container ).render(
			<EmailsApp config={ window.wpitravelEmails } />
		);
	}
} );
