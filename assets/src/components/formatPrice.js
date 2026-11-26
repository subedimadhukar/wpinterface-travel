/**
 * Formats a sample price with the currency settings, for the live preview.
 * The server formats real prices; this only mirrors the same rules.
 *
 * @param {number} amount   Amount.
 * @param {Object} currency Currency settings.
 * @param {string} symbol   Currency symbol.
 * @return {string} Formatted price.
 */
export default function formatPrice( amount, currency, symbol ) {
	const decimals = Math.max( 0, Math.min( 4, currency.decimals ) );
	const [ whole, fraction ] = Math.abs( amount )
		.toFixed( decimals )
		.split( '.' );
	const grouped = whole.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		currency.thousand_separator
	);
	const number = fraction
		? `${ grouped }${ currency.decimal_separator }${ fraction }`
		: grouped;

	switch ( currency.position ) {
		case 'right':
			return `${ number }${ symbol }`;
		case 'left_space':
			return `${ symbol } ${ number }`;
		case 'right_space':
			return `${ number } ${ symbol }`;
		default:
			return `${ symbol }${ number }`;
	}
}
