/**
 * WPInterface Travel: announcement bar. Start and end times (so cached
 * pages still change on time), the countdown, taking turns between
 * messages (with pause, previous and next), and the close button.
 */
( function () {
	'use strict';

	const l10n = window.wpitravelBar || {};
	const units = l10n.units || [ 'd', 'h', 'm', 's' ];
	const reduce =
		window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* Other scripts (Pro) can veto a bar: push function ( bar, rules ) { return bool; }. */
	const filters = ( window.wpitravelBarFilters =
		window.wpitravelBarFilters || [] );

	const store = function ( key, days ) {
		try {
			if ( days > 0 ) {
				window.localStorage.setItem(
					key,
					String( Date.now() + days * 864e5 )
				);
			} else {
				window.sessionStorage.setItem( key, 's' );
			}
		} catch ( e ) {}
	};

	const emit = function ( bar, name, detail ) {
		bar.dispatchEvent(
			new CustomEvent( 'wpitravel:bar-' + name, {
				bubbles: true,
				detail: detail || {},
			} )
		);
	};

	const pad = function ( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	};

	const setHeight = function () {
		const top = document.querySelector(
			'.wpitravel-bar--top.is-sticky:not(.is-hidden)'
		);
		document.documentElement.style.setProperty(
			'--wpitravel-bar-h',
			top ? top.offsetHeight + 'px' : '0px'
		);
	};

	const init = function ( bar ) {
		const now = Date.now() / 1000;
		const start = parseInt( bar.getAttribute( 'data-start' ) || '0', 10 );
		const end = parseInt( bar.getAttribute( 'data-end' ) || '0', 10 );
		let rules = {};

		try {
			rules = JSON.parse( bar.getAttribute( 'data-rules' ) || '{}' );
		} catch ( e ) {}

		if (
			( start && now < start ) ||
			( end && now >= end ) ||
			filters.some( function ( f ) {
				return false === f( bar, rules );
			} )
		) {
			bar.classList.add( 'is-hidden' );
			return;
		}

		if ( 'top' === bar.getAttribute( 'data-move' ) ) {
			document.body.insertBefore( bar, document.body.firstChild );
		}

		if (
			bar.classList.contains( 'wpitravel-bar--bottom' ) &&
			bar.classList.contains( 'is-sticky' )
		) {
			document.body.style.paddingBottom = bar.offsetHeight + 'px';
		}

		emit( bar, 'view' );

		/* Countdown. */
		bar.querySelectorAll( '[data-countdown]' ).forEach( function ( box ) {
			const target =
				parseInt( box.getAttribute( 'data-countdown' ), 10 ) * 1000;
			const out = box.querySelector( '.wpitravel-bar__count' );
			const tick = function () {
				const left = Math.max(
					0,
					Math.floor( ( target - Date.now() ) / 1000 )
				);
				const d = Math.floor( left / 86400 );
				const h = Math.floor( ( left % 86400 ) / 3600 );
				const m = Math.floor( ( left % 3600 ) / 60 );
				const s = left % 60;

				out.textContent =
					( d ? d + units[ 0 ] + ' ' : '' ) +
					pad( h ) +
					units[ 1 ] +
					' ' +
					pad( m ) +
					units[ 2 ] +
					' ' +
					pad( s ) +
					units[ 3 ];

				if ( ! left ) {
					box.hidden = true;
					return false;
				}
				return true;
			};

			if ( tick() ) {
				var timer = setInterval( function () {
					if ( ! tick() ) {
						clearInterval( timer );
					}
				}, 1000 );
			}
		} );

		/* Messages take turns. */
		const messages = bar.querySelectorAll( '.wpitravel-bar__message' );
		let seconds = parseInt( bar.getAttribute( 'data-rotate' ) || '6', 10 );

		/* data-rotate="0" shows every message at once (Pro's ticker style). */
		if ( messages.length > 1 && seconds > 0 ) {
			let current = 0;
			seconds = Math.max( 3, seconds );
			const pauseButton = bar.querySelector( '.wpitravel-bar__pause' );
			let paused = reduce;
			let held = false;
			let timer = null;

			bar.querySelectorAll( '.wpitravel-bar__nav' ).forEach(
				function ( button ) {
					button.hidden = false;
				}
			);

			const show = function ( index ) {
				messages[ current ].hidden = true;
				messages[ current ].classList.remove(
					'is-current',
					'is-entering'
				);
				current = ( index + messages.length ) % messages.length;
				messages[ current ].hidden = false;
				messages[ current ].classList.add(
					'is-current',
					'is-entering'
				);
				setHeight();
			};

			const schedule = function () {
				clearInterval( timer );
				timer = setInterval( function () {
					if ( ! paused && ! held && ! document.hidden ) {
						show( current + 1 );
					}
				}, seconds * 1000 );
			};

			const paint = function () {
				pauseButton.setAttribute(
					'aria-pressed',
					paused ? 'true' : 'false'
				);
				pauseButton.querySelector( '.screen-reader-text' ).textContent =
					paused ? l10n.play || 'Play' : l10n.pause || 'Pause';
			};

			bar.addEventListener( 'click', function ( event ) {
				const step = event.target.closest( '[data-bar-step]' );

				if ( step ) {
					show(
						current +
							parseInt( step.getAttribute( 'data-bar-step' ), 10 )
					);
					schedule();
				} else if ( event.target.closest( '.wpitravel-bar__pause' ) ) {
					paused = ! paused;
					paint();
				}
			} );

			/* Hovering or focusing the bar holds the current message. */
			bar.addEventListener( 'mouseenter', function () {
				held = true;
			} );
			bar.addEventListener( 'mouseleave', function () {
				held = false;
			} );
			bar.addEventListener( 'focusin', function () {
				held = true;
			} );
			bar.addEventListener( 'focusout', function () {
				held = false;
			} );

			paint();
			schedule();
		}

		/* Close and remember. */
		bar.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-bar-close]' ) ) {
				bar.classList.add( 'is-hidden' );
				store(
					bar.getAttribute( 'data-wpitravel-bar' ),
					parseInt(
						bar.getAttribute( 'data-dismiss-days' ) || '0',
						10
					)
				);
				document.body.style.paddingBottom = '';
				setHeight();
				emit( bar, 'close' );
			} else if ( event.target.closest( '[data-bar-click]' ) ) {
				emit( bar, 'click', {
					href: event.target.closest( 'a' ).href,
				} );
			}
		} );
	};

	const run = function () {
		document.querySelectorAll( '.wpitravel-bar' ).forEach( init );
		setHeight();
		window.addEventListener( 'resize', setHeight, { passive: true } );
	};

	/* Wait for DOMContentLoaded even in a deferred script, so other deferred
	   scripts (Pro's rules and counters) are ready first. */
	if ( 'complete' === document.readyState ) {
		run();
	} else {
		document.addEventListener( 'DOMContentLoaded', run );
		window.addEventListener( 'load', run );
	}
} )();
