/**
 * Setup wizard.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import SetupWizard from './SetupWizard';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-setup' );

	if ( container && window.wpitravelSetup ) {
		createRoot( container ).render(
			<SetupWizard config={ window.wpitravelSetup } />
		);
	}
} );
