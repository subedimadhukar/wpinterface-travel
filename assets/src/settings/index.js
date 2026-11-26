/**
 * Travel → Settings screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import SettingsApp from './SettingsApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-settings' );

	if ( container && window.wpitravelSettings ) {
		createRoot( container ).render(
			<SettingsApp initial={ window.wpitravelSettings } />
		);
	}
} );
