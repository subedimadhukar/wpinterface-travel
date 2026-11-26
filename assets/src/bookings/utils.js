/**
 * Helpers shared by the bookings screens.
 */
import { dateI18n } from '@wordpress/date';
import formatPrice from '../components/formatPrice';

export const BASE = '/wpinterface-travel/v1';

/**
 * Formats an amount in the booking's currency.
 *
 * @param {Object} config   Screen config.
 * @param {string} amount   Decimal string.
 * @param {string} currency Currency code of the booking.
 * @return {string} Formatted amount.
 */
export function money( config, amount, currency ) {
	const value = parseFloat( amount ) || 0;
	const sign = value < 0 ? '−' : '';

	if ( currency && currency !== config.currency.code ) {
		return `${ sign }${ Math.abs( value ).toFixed( 2 ) } ${ currency }`;
	}

	return sign + formatPrice( value, config.currency, config.symbol );
}

/**
 * Formats a Y-m-d date with the site's date format.
 *
 * @param {Object} config Screen config.
 * @param {string} date   Y-m-d.
 * @return {string} Date.
 */
export function formatDate( config, date ) {
	return date ? dateI18n( config.dateFormat, `${ date }T12:00:00` ) : '';
}

/**
 * Formats a stored UTC datetime in the site's timezone.
 *
 * @param {Object} config   Screen config.
 * @param {string} datetime Y-m-d H:i:s (UTC).
 * @return {string} Date and time.
 */
export function formatDateTime( config, datetime ) {
	if ( ! datetime ) {
		return '';
	}

	return dateI18n(
		`${ config.dateFormat } H:i`,
		`${ datetime.replace( ' ', 'T' ) }Z`
	);
}

/**
 * Builds the screen URL for a view.
 *
 * @param {Object} params Query parameters (booking, action); empty for the list.
 * @return {string} URL.
 */
export function screenUrl( params = {} ) {
	const url = new URL( window.location.href );

	[ 'booking', 'action' ].forEach( ( key ) =>
		url.searchParams.delete( key )
	);
	Object.entries( params ).forEach( ( [ key, value ] ) =>
		url.searchParams.set( key, value )
	);

	return url.toString();
}
