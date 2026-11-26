/**
 * Trip details box: mounts into the meta box on trip edit screens.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import TripEditor from './TripEditor';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'wpitravel-trip-editor' );

	if ( container ) {
		createRoot( container ).render( <TripEditor /> );
	}
} );
