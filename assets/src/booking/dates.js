/**
 * Date helpers. Departure dates are calendar dates ("2026-12-05"), so they
 * are handled in UTC to avoid shifting by the visitor's timezone.
 */

const locale = () => document.documentElement.lang || undefined;

export const parseDate = ( value ) => new Date( `${ value }T00:00:00Z` );

export const toISODate = ( date ) => date.toISOString().slice( 0, 10 );

export const addDays = ( value, days ) => {
	const date = parseDate( value );
	date.setUTCDate( date.getUTCDate() + days );
	return toISODate( date );
};

export const monthKey = ( value ) => value.slice( 0, 7 );

export const shiftMonth = ( key, offset ) => {
	const [ year, month ] = key.split( '-' ).map( Number );
	const date = new Date( Date.UTC( year, month - 1 + offset, 1 ) );
	return toISODate( date ).slice( 0, 7 );
};

export const formatDate = ( value, options = {} ) =>
	new Intl.DateTimeFormat( locale(), {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		timeZone: 'UTC',
		...options,
	} ).format( parseDate( value ) );

export const formatMonth = ( key ) =>
	new Intl.DateTimeFormat( locale(), {
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	} ).format( parseDate( `${ key }-01` ) );

/**
 * Short weekday names starting with the site's first day of the week.
 *
 * @param {number} startOfWeek 0 = Sunday … 6 = Saturday.
 * @return {string[]} Seven names.
 */
export const weekdayNames = ( startOfWeek ) => {
	const format = new Intl.DateTimeFormat( locale(), {
		weekday: 'short',
		timeZone: 'UTC',
	} );
	// 2023-01-01 was a Sunday.
	return Array.from( { length: 7 }, ( _, i ) =>
		format.format(
			new Date( Date.UTC( 2023, 0, 1 + ( ( startOfWeek + i ) % 7 ) ) )
		)
	);
};

/**
 * Calendar cells for a month: null for padding, else "Y-m-d".
 *
 * @param {string} key         "Y-m".
 * @param {number} startOfWeek 0 = Sunday … 6 = Saturday.
 * @return {Array<string|null>} Cells.
 */
export const monthCells = ( key, startOfWeek ) => {
	const first = parseDate( `${ key }-01` );
	const padding = ( first.getUTCDay() - startOfWeek + 7 ) % 7;
	const cells = Array( padding ).fill( null );
	const date = new Date( first );

	while ( monthKey( toISODate( date ) ) === key ) {
		cells.push( toISODate( date ) );
		date.setUTCDate( date.getUTCDate() + 1 );
	}

	return cells;
};
