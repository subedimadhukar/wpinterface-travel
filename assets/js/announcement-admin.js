/* global jQuery */
/**
 * WPInterface Travel: announcement bar settings, live preview.
 *
 * @param {Function} $ jQuery.
 */
( function ( $ ) {
	'use strict';

	const schemes = window.wpitravelBarSchemes || {};

	$( '[data-bar-editor]' ).each( function () {
		const editor = this;
		const preview = editor.querySelector( '[data-preview]' );
		const custom = editor.querySelector( '[data-custom-colors]' );

		const value = function ( field ) {
			const el = editor.querySelector(
				'[data-field="' +
					field +
					'"]:checked, [data-field="' +
					field +
					'"]:not([type="radio"]):not([type="checkbox"])'
			);
			return el ? el.value : '';
		};

		const first = function () {
			const boxes = editor.querySelectorAll( '[data-message]' );
			for ( let i = 0; i < boxes.length; i++ ) {
				const text = boxes[ i ].querySelector(
					'[data-field="text"]'
				).value;
				const label = boxes[ i ].querySelector(
					'[data-field="label"]'
				).value;
				if ( text || label ) {
					return { text, label };
				}
			}
			return { text: '', label: '' };
		};

		const update = function () {
			const scheme = value( 'scheme' ) || 'dark';
			const colors = schemes[ scheme ] || [
				value( 'bg' ),
				value( 'fg' ),
				value( 'button_bg' ),
				value( 'button_fg' ),
			];
			const message = first();
			const close = editor.querySelector( '[data-field="dismissible"]' );

			custom.hidden = 'custom' !== scheme;
			preview.style.setProperty( '--wpitravel-bar-bg', colors[ 0 ] );
			preview.style.setProperty( '--wpitravel-bar-fg', colors[ 1 ] );
			preview.style.setProperty(
				'--wpitravel-bar-button-bg',
				colors[ 2 ]
			);
			preview.style.setProperty(
				'--wpitravel-bar-button-fg',
				colors[ 3 ]
			);

			preview.className =
				'wpitravel-bar wpitravel-bar--preview wpitravel-bar--' +
				( value( 'size' ) || 'medium' ) +
				' wpitravel-bar--align-' +
				( value( 'align' ) || 'center' );

			/* The preview shows text only: tags typed in the message are not rendered here. */
			preview.querySelector( '[data-preview-text]' ).textContent =
				message.text.replace( /<[^>]*>/g, '' );
			preview.querySelector( '[data-preview-button]' ).textContent =
				message.label;
			preview.querySelector( '[data-preview-count]' ).hidden =
				! value( 'countdown' );
			preview.querySelector( '[data-preview-count-label]' ).textContent =
				value( 'count_label' );
			preview.querySelector( '[data-preview-close]' ).hidden = ! (
				close && close.checked
			);
		};

		$( editor )
			.find( '.wpitravel-color' )
			.wpColorPicker( {
				change() {
					setTimeout( update );
				},
			} );

		editor.addEventListener( 'input', update );
		editor.addEventListener( 'change', update );
		update();
	} );
} )( jQuery );
