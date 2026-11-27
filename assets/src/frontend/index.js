/**
 * Trip pages: loads the OpenStreetMap embed only when the visitor asks,
 * so no request goes to OpenStreetMap before that; opens or closes every
 * itinerary day at once.
 */
import './style.scss';

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest?.( '.wpitravel-map__load' );
	const frame = button?.closest( '[data-wpitravel-map]' );

	// Only OpenStreetMap's embed is loaded, and it is not told which page asked.
	if (
		! frame ||
		! frame.dataset.wpitravelMap.startsWith(
			'https://www.openstreetmap.org/export/embed.html'
		)
	) {
		return;
	}

	const iframe = document.createElement( 'iframe' );
	iframe.src = frame.dataset.wpitravelMap;
	iframe.title = button.firstChild?.textContent?.trim() || 'Map';
	iframe.loading = 'lazy';
	iframe.referrerPolicy = 'no-referrer';
	frame.replaceChildren( iframe );
	frame.classList.add( 'is-loaded' );
} );

// "Expand all days": shown only when this script runs.
const itineraryToggles = () =>
	document
		.querySelectorAll( '.wpitravel-itinerary__toggle[hidden]' )
		.forEach( ( button ) => {
			const days = () =>
				button
					.closest( '.wpitravel-itinerary' )
					?.querySelectorAll( 'details' ) || [];
			const sync = () => {
				const open = [ ...days() ].every( ( day ) => day.open );
				button.textContent = open
					? button.dataset.wpitravelCollapse
					: button.dataset.wpitravelExpand;
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			};

			button.hidden = false;
			sync();
			button.addEventListener( 'click', () => {
				const open = button.getAttribute( 'aria-expanded' ) !== 'true';
				days().forEach( ( day ) => ( day.open = open ) );
				sync();
			} );
			days().forEach( ( day ) => day.addEventListener( 'toggle', sync ) );
		} );

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', itineraryToggles );
} else {
	itineraryToggles();
}
