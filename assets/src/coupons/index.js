/**
 * Travel → Coupons screen.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import CouponsApp from './CouponsApp';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-coupons' );

	if ( container && window.wpitravelCoupons ) {
		createRoot( container ).render(
			<CouponsApp config={ window.wpitravelCoupons } />
		);
	}
} );
