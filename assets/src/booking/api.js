/**
 * Checkout REST calls.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/wpinterface-travel/v1';

export const getAvailability = ( tripId, from, to, priceOption ) =>
	apiFetch( {
		path: addQueryArgs( `${ BASE }/trips/${ tripId }/availability`, {
			from,
			to,
			price_option: priceOption,
		} ),
	} );

export const postQuote = ( data ) =>
	apiFetch( { path: `${ BASE }/quote`, method: 'POST', data } );

export const postHold = ( data ) =>
	apiFetch( { path: `${ BASE }/holds`, method: 'POST', data } );

export const deleteHold = ( key ) =>
	apiFetch( {
		path: `${ BASE }/holds/${ key }`,
		method: 'DELETE',
		parse: false,
	} ).catch( () => {} );

export const postBooking = ( data ) =>
	apiFetch( { path: `${ BASE }/bookings`, method: 'POST', data } );

export const postPay = ( bookingKey, gateway ) =>
	apiFetch( {
		path: `${ BASE }/bookings/${ bookingKey }/pay`,
		method: 'POST',
		data: { gateway },
	} );
