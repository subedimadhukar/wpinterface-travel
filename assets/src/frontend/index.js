/**
 * Trip pages: loads the OpenStreetMap embed only when the visitor asks,
 * so no request goes to OpenStreetMap before that.
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
