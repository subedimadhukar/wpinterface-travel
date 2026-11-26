/**
 * Trip pages: loads the OpenStreetMap embed only when the visitor asks,
 * so no request goes to OpenStreetMap before that.
 */
import './style.scss';

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest?.( '.wpitravel-map__load' );
	const frame = button?.closest( '[data-wpitravel-map]' );

	if ( ! frame ) {
		return;
	}

	const iframe = document.createElement( 'iframe' );
	iframe.src = frame.dataset.wpitravelMap;
	iframe.title = button.firstChild?.textContent?.trim() || 'Map';
	iframe.loading = 'lazy';
	iframe.referrerPolicy = 'no-referrer-when-downgrade';
	frame.replaceChildren( iframe );
	frame.classList.add( 'is-loaded' );
} );
