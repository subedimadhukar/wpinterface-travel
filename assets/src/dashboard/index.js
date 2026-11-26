/**
 * Travel → Dashboard screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import DashboardApp from './DashboardApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-dashboard' );

	if ( container && window.wpitravelDashboard ) {
		createRoot( container ).render(
			<DashboardApp config={ window.wpitravelDashboard } />
		);
	}
} );
