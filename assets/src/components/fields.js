/**
 * Small field wrappers with consistent sizing.
 */
import { useEffect, useState } from '@wordpress/element';
import { TextControl, TextareaControl } from '@wordpress/components';

/**
 * Whole-number input. Empty or invalid input becomes `min`.
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    Value.
 * @param {Function} props.onChange Receives a number.
 * @param {number}   props.min      Minimum.
 * @param {number}   props.max      Maximum.
 * @param {string}   props.help     Help text.
 */
export function IntegerField( { label, value, onChange, min = 0, max, help } ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type="number"
			label={ label }
			help={ help }
			min={ min }
			max={ max }
			step={ 1 }
			value={ Number.isFinite( value ) ? String( value ) : '' }
			onChange={ ( raw ) => {
				const parsed = parseInt( raw, 10 );
				let next = Number.isFinite( parsed ) ? parsed : min;
				next = Math.max( min, next );
				if ( max !== undefined ) {
					next = Math.min( max, next );
				}
				onChange( next );
			} }
		/>
	);
}

/**
 * Decimal input. Keeps the typed text while editing, so "-" or "13." are
 * not lost; reports the parsed, clamped number (0 when empty).
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    Value.
 * @param {number}   props.min      Minimum.
 * @param {number}   props.max      Maximum.
 * @param {Function} props.onChange Receives a number.
 * @param {string}   props.help     Help text.
 */
export function DecimalField( { label, value, min, max, onChange, help } ) {
	const toText = ( number ) => ( number === 0 ? '' : String( number ) );
	const [ raw, setRaw ] = useState( toText( value ) );

	// Follow outside changes (e.g. a server response) without overwriting typing.
	useEffect( () => {
		const parsed = parseFloat( raw );
		if ( ( Number.isFinite( parsed ) ? parsed : 0 ) !== value ) {
			setRaw( toText( value ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			inputMode="decimal"
			label={ label }
			help={ help }
			value={ raw }
			placeholder="0"
			onChange={ ( next ) => {
				setRaw( next );
				const parsed = parseFloat( next );
				onChange(
					Number.isFinite( parsed )
						? Math.max( min, Math.min( max, parsed ) )
						: 0
				);
			} }
		/>
	);
}

/**
 * One-line text input.
 *
 * @param {Object} props TextControl props.
 */
export function TextField( props ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			{ ...props }
		/>
	);
}

/**
 * Multi-line text input. Accepts basic HTML (sanitized on save).
 *
 * @param {Object} props TextareaControl props.
 */
export function TextareaField( props ) {
	return <TextareaControl __nextHasNoMarginBottom rows={ 4 } { ...props } />;
}
