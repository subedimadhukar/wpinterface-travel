/**
 * Month calendar of departures. Loads a year at a time.
 */
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useEffect, useMemo, useState } from '@wordpress/element';
import displayPrice from './displayPrice';
import { getAvailability } from './api';
import {
	addDays,
	formatDate,
	formatMonth,
	monthCells,
	monthKey,
	shiftMonth,
	weekdayNames,
} from './dates';

const RANGE_DAYS = 365;

export default function Calendar( {
	tripId,
	priceOption,
	config,
	value,
	onChange,
} ) {
	const [ departures, setDepartures ] = useState( [] );
	const [ loadedUntil, setLoadedUntil ] = useState( null );
	const [ month, setMonth ] = useState( monthKey( config.today ) );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	const load = ( from, replace ) => {
		const to = addDays( from, RANGE_DAYS );
		setLoading( true );
		return getAvailability( tripId, from, to, priceOption )
			.then( ( response ) => {
				setDepartures( ( current ) =>
					replace
						? response.departures
						: [ ...current, ...response.departures ]
				);
				setLoadedUntil( to );
				return response.departures;
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoading( false ) );
	};

	// Reload when the price option changes; jump to the first month with a departure.
	useEffect( () => {
		load( config.today, true ).then( ( list ) => {
			if ( list?.length ) {
				setMonth( monthKey( list[ 0 ].date ) );
			}
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tripId, priceOption ] );

	const byDate = useMemo( () => {
		const map = {};
		departures.forEach( ( departure ) => {
			( map[ departure.date ] = map[ departure.date ] || [] ).push(
				departure
			);
		} );
		return map;
	}, [ departures ] );

	const goTo = ( offset ) => {
		const next = shiftMonth( month, offset );
		setMonth( next );

		if ( loadedUntil && `${ next }-31` > loadedUntil ) {
			load( addDays( loadedUntil, 1 ), false );
		}
	};

	const selectedTimes = value ? byDate[ value.date ] || [] : [];

	if ( error ) {
		return (
			<p className="wpitravel-booking__error" role="alert">
				{ error }
			</p>
		);
	}

	if ( ! loading && departures.length === 0 ) {
		return (
			<p className="wpitravel-booking__notice">
				{ __(
					'There are no upcoming departures. Send us an enquiry for other dates.',
					'wpinterface-travel'
				) }
			</p>
		);
	}

	return (
		<div className="wpitravel-calendar">
			<div className="wpitravel-calendar__header">
				<button
					type="button"
					className="wpitravel-calendar__nav"
					onClick={ () => goTo( -1 ) }
					disabled={ month <= monthKey( config.today ) }
					aria-label={ __( 'Previous month', 'wpinterface-travel' ) }
				>
					‹
				</button>
				<strong aria-live="polite">{ formatMonth( month ) }</strong>
				<button
					type="button"
					className="wpitravel-calendar__nav"
					onClick={ () => goTo( 1 ) }
					aria-label={ __( 'Next month', 'wpinterface-travel' ) }
				>
					›
				</button>
			</div>
			<div className="wpitravel-calendar__grid">
				{ weekdayNames( config.startOfWeek ).map( ( name ) => (
					<span
						key={ name }
						className="wpitravel-calendar__weekday"
						aria-hidden="true"
					>
						{ name }
					</span>
				) ) }
				{ monthCells( month, config.startOfWeek ).map(
					( date, index ) => {
						if ( ! date ) {
							return <span key={ `pad-${ index }` } />;
						}

						const slots = byDate[ date ] || [];
						const open = slots.filter( ( slot ) => slot.bookable );
						const soldOut = slots.length > 0 && open.length === 0;
						const cheapest = open.reduce(
							( min, slot ) =>
								min === null ||
								parseFloat( slot.from_price ) <
									parseFloat( min )
									? slot.from_price
									: min,
							null
						);
						const isSelected = value?.date === date;

						return (
							<button
								key={ date }
								type="button"
								className={ [
									'wpitravel-calendar__day',
									open.length ? 'is-available' : '',
									soldOut ? 'is-sold-out' : '',
									isSelected ? 'is-selected' : '',
								].join( ' ' ) }
								disabled={ open.length === 0 }
								aria-pressed={ isSelected }
								aria-label={
									open.length
										? sprintf(
												/* translators: 1: date, 2: price. */
												__(
													'%1$s, from %2$s',
													'wpinterface-travel'
												),
												formatDate( date ),
												displayPrice(
													cheapest,
													config.currency,
													config.currency.symbol
												)
										  )
										: formatDate( date )
								}
								onClick={ () => onChange( open[ 0 ] ) }
							>
								<span className="wpitravel-calendar__date">
									{ Number( date.slice( 8 ) ) }
								</span>
								{ open.length > 0 && (
									<span className="wpitravel-calendar__price">
										{ displayPrice(
											cheapest,
											{ ...config.currency, decimals: 0 },
											config.currency.symbol
										) }
									</span>
								) }
								{ soldOut && (
									<span className="wpitravel-calendar__price">
										{ __( 'Full', 'wpinterface-travel' ) }
									</span>
								) }
							</button>
						);
					}
				) }
			</div>
			{ loading && (
				<p className="wpitravel-booking__muted">
					{ __( 'Loading dates…', 'wpinterface-travel' ) }
				</p>
			) }
			{ selectedTimes.filter( ( slot ) => slot.bookable ).length > 1 && (
				<div
					className="wpitravel-booking__times"
					role="group"
					aria-label={ __( 'Start time', 'wpinterface-travel' ) }
				>
					{ selectedTimes
						.filter( ( slot ) => slot.bookable )
						.map( ( slot ) => (
							<button
								key={ slot.time }
								type="button"
								className={
									slot.time === value.time
										? 'wpitravel-chip is-selected'
										: 'wpitravel-chip'
								}
								aria-pressed={ slot.time === value.time }
								onClick={ () => onChange( slot ) }
							>
								{ slot.time ||
									__( 'Any time', 'wpinterface-travel' ) }
							</button>
						) ) }
				</div>
			) }
			{
				/**
				 * Lets Pro add something under the calendar, e.g. a waitlist
				 * for full departures. Return an element (or null).
				 */
				applyFilters( 'wpitravel.calendar.after', null, {
					tripId,
					priceOption,
					departures,
					month,
					config,
				} )
			}
		</div>
	);
}
