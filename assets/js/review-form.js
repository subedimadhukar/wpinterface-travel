/**
 * Sends the public review form on trip pages ("Anyone can review") to
 * the reviews REST route. The server checks, rate-limits and moderates.
 */
( function () {
	const config = window.wpitravelReviewForm || {};

	// Picking a star clears the "choose a rating" message.
	document.addEventListener( 'change', ( event ) => {
		if ( 'rating' === event.target.name ) {
			const message = event.target
				.closest( '[data-wpitravel-review-form]' )
				?.querySelector( '.wpitravel-review-form__message.is-error' );
			if ( message ) {
				message.textContent = '';
				message.classList.remove( 'is-error' );
			}
		}
	} );

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest?.( '[data-wpitravel-review-form]' );

		if ( ! form ) {
			return;
		}

		event.preventDefault();

		const message = form.querySelector( '.wpitravel-review-form__message' );
		const data = new FormData( form );
		const say = ( text, isError ) => {
			message.textContent = text;
			message.classList.toggle( 'is-error', !! isError );
		};

		if ( ! data.get( 'rating' ) ) {
			say( config.stars, true );
			form.querySelector( 'input[name="rating"]' )?.focus();
			return;
		}

		if ( ! form.reportValidity() ) {
			return;
		}

		const button = form.querySelector( 'button[type="submit"]' );

		button.disabled = true;
		say( '' );

		window
			.fetch(
				config.rest + form.dataset.wpitravelReviewForm + '/reviews',
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						rating: Number( data.get( 'rating' ) ),
						name: data.get( 'name' ),
						email: data.get( 'email' ),
						title: data.get( 'title' ),
						content: data.get( 'content' ),
					} ),
				}
			)
			.then( ( response ) =>
				response
					.json()
					.then( ( body ) => ( { ok: response.ok, body } ) )
			)
			.then( ( { ok, body } ) => {
				if ( ! ok ) {
					// Core messages can carry HTML entities (&#8217;); show them as text.
					const text = new window.DOMParser().parseFromString(
						body?.message || '',
						'text/html'
					).documentElement.textContent;
					throw new Error( text || config.failed );
				}
				form.reset();
				form.querySelectorAll( 'input, textarea, button' ).forEach(
					( field ) => ( field.disabled = true )
				);
				say( body.published ? config.live : config.thanks );
			} )
			.catch( ( error ) => {
				button.disabled = false;
				say( error.message || config.failed, true );
			} );
	} );
} )();
