/**
 * Prices shown while choosing a trip. Add-ons may change how they read
 * (Pro: the visitor's own currency), never what is charged.
 */
import { applyFilters } from '@wordpress/hooks';
import formatPrice from '../components/formatPrice';

/**
 * Formats a price for display in the booking form.
 *
 * @param {number|string} amount   Amount in the store currency.
 * @param {Object}        currency Store currency settings.
 * @param {string}        symbol   Currency symbol.
 * @return {string} Price text.
 */
export default function displayPrice( amount, currency, symbol ) {
	const text = formatPrice( amount, currency, symbol );

	/**
	 * Filters a price shown in the booking form.
	 *
	 * @param {string} text     Formatted price in the store currency.
	 * @param {number} amount   Amount in the store currency.
	 * @param {Object} currency Store currency settings.
	 */
	return applyFilters(
		'wpitravel.displayPrice',
		text,
		Number( amount ),
		currency
	);
}
