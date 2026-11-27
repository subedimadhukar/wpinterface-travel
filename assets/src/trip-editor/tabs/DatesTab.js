/**
 * Departure schedule: fixed dates, recurring rules, excluded dates and
 * booking limits, plus a live list of upcoming departures.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl, TextControl } from '@wordpress/components';
import { trash } from '@wordpress/icons';
import Repeater from '../../components/Repeater';
import { IntegerField } from '../../components/fields';
import UpcomingDepartures from './UpcomingDepartures';

const WEEKDAYS = [
	[ 1, __( 'Mon', 'wpinterface-travel' ) ],
	[ 2, __( 'Tue', 'wpinterface-travel' ) ],
	[ 3, __( 'Wed', 'wpinterface-travel' ) ],
	[ 4, __( 'Thu', 'wpinterface-travel' ) ],
	[ 5, __( 'Fri', 'wpinterface-travel' ) ],
	[ 6, __( 'Sat', 'wpinterface-travel' ) ],
	[ 7, __( 'Sun', 'wpinterface-travel' ) ],
];

const MONTHS = [
	__( 'Jan', 'wpinterface-travel' ),
	__( 'Feb', 'wpinterface-travel' ),
	__( 'Mar', 'wpinterface-travel' ),
	__( 'Apr', 'wpinterface-travel' ),
	__( 'May', 'wpinterface-travel' ),
	__( 'Jun', 'wpinterface-travel' ),
	__( 'Jul', 'wpinterface-travel' ),
	__( 'Aug', 'wpinterface-travel' ),
	__( 'Sep', 'wpinterface-travel' ),
	__( 'Oct', 'wpinterface-travel' ),
	__( 'Nov', 'wpinterface-travel' ),
	__( 'Dec', 'wpinterface-travel' ),
];

const EMPTY_SCHEDULE = {
	fixed: [],
	recurring: [],
	excluded: [],
	capacity: 0,
	cutoff_hours: 0,
	advance_days: 365,
	private: false,
};

function DateInput( { label, value, onChange, type = 'date' } ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type={ type }
			label={ label }
			value={ value || '' }
			onChange={ onChange }
		/>
	);
}

/**
 * Toggles a number in a list.
 *
 * @param {number[]} list    List.
 * @param {number}   value   Value.
 * @param {boolean}  checked Whether it should be in the list.
 * @return {number[]} New sorted list.
 */
const toggle = ( list, value, checked ) =>
	( checked
		? [ ...new Set( [ ...list, value ] ) ]
		: list.filter( ( item ) => item !== value )
	).sort( ( a, b ) => a - b );

function FixedDates( { dates, onChange } ) {
	const update = ( index, patch ) =>
		onChange(
			dates.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			)
		);

	return (
		<fieldset className="wpitravel-fieldset">
			<legend className="wpitravel-field-label">
				{ __( 'Fixed dates', 'wpinterface-travel' ) }
			</legend>
			<div className="wpitravel-date-rows">
				{ dates.map( ( item, index ) => (
					<div className="wpitravel-date-row" key={ index }>
						<DateInput
							label={ __( 'Date', 'wpinterface-travel' ) }
							value={ item.date }
							onChange={ ( date ) => update( index, { date } ) }
						/>
						<DateInput
							type="time"
							label={ __(
								'Start time (optional)',
								'wpinterface-travel'
							) }
							value={ item.time }
							onChange={ ( time ) => update( index, { time } ) }
						/>
						<IntegerField
							label={ __(
								'Seats (0 = trip default)',
								'wpinterface-travel'
							) }
							value={ item.capacity || 0 }
							onChange={ ( capacity ) =>
								update( index, { capacity } )
							}
						/>
						<Button
							icon={ trash }
							isDestructive
							label={ __( 'Remove date', 'wpinterface-travel' ) }
							onClick={ () =>
								onChange(
									dates.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</div>
				) ) }
			</div>
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( [
						...dates,
						{ date: '', time: '', capacity: 0 },
					] )
				}
			>
				{ __( 'Add date', 'wpinterface-travel' ) }
			</Button>
		</fieldset>
	);
}

function RecurringRule( { rule, update } ) {
	const times = rule.times || [];

	return (
		<>
			<div className="wpitravel-grid">
				<DateInput
					label={ __( 'From', 'wpinterface-travel' ) }
					value={ rule.start }
					onChange={ ( start ) => update( { start } ) }
				/>
				<DateInput
					label={ __(
						'Until (empty = no end)',
						'wpinterface-travel'
					) }
					value={ rule.end }
					onChange={ ( end ) => update( { end } ) }
				/>
			</div>
			<fieldset className="wpitravel-inline-checks">
				<legend className="wpitravel-field-label">
					{ __(
						'Days of the week (none = every day)',
						'wpinterface-travel'
					) }
				</legend>
				{ WEEKDAYS.map( ( [ value, label ] ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ value }
						label={ label }
						checked={ ( rule.weekdays || [] ).includes( value ) }
						onChange={ ( checked ) =>
							update( {
								weekdays: toggle(
									rule.weekdays || [],
									value,
									checked
								),
							} )
						}
					/>
				) ) }
			</fieldset>
			<fieldset className="wpitravel-inline-checks">
				<legend className="wpitravel-field-label">
					{ __(
						'Months (none = every month)',
						'wpinterface-travel'
					) }
				</legend>
				{ MONTHS.map( ( label, index ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ label }
						label={ label }
						checked={ ( rule.months || [] ).includes( index + 1 ) }
						onChange={ ( checked ) =>
							update( {
								months: toggle(
									rule.months || [],
									index + 1,
									checked
								),
							} )
						}
					/>
				) ) }
			</fieldset>
			<fieldset className="wpitravel-fieldset">
				<legend className="wpitravel-field-label">
					{ __(
						'Start times (none = no fixed time)',
						'wpinterface-travel'
					) }
				</legend>
				<div className="wpitravel-date-rows">
					{ times.map( ( time, index ) => (
						<div className="wpitravel-date-row" key={ index }>
							<DateInput
								type="time"
								label={ sprintf(
									/* translators: %d: time number. */
									__( 'Time %d', 'wpinterface-travel' ),
									index + 1
								) }
								value={ time }
								onChange={ ( value ) =>
									update( {
										times: times.map( ( t, i ) =>
											i === index ? value : t
										),
									} )
								}
							/>
							<Button
								icon={ trash }
								isDestructive
								label={ __(
									'Remove time',
									'wpinterface-travel'
								) }
								onClick={ () =>
									update( {
										times: times.filter(
											( _, i ) => i !== index
										),
									} )
								}
							/>
						</div>
					) ) }
				</div>
				<Button
					variant="secondary"
					onClick={ () => update( { times: [ ...times, '' ] } ) }
				>
					{ __( 'Add start time', 'wpinterface-travel' ) }
				</Button>
			</fieldset>
			<div className="wpitravel-grid">
				<IntegerField
					label={ __(
						'Seats per start time (0 = trip default)',
						'wpinterface-travel'
					) }
					help={ __(
						'Each start time sells its own seats, e.g. 12 for a morning tour and 20 for an afternoon one in a second rule.',
						'wpinterface-travel'
					) }
					value={ rule.capacity || 0 }
					onChange={ ( capacity ) => update( { capacity } ) }
				/>
			</div>
		</>
	);
}

function ExcludedDates( { dates, onChange } ) {
	return (
		<fieldset className="wpitravel-fieldset">
			<legend className="wpitravel-field-label">
				{ __( 'Excluded dates', 'wpinterface-travel' ) }
			</legend>
			<div className="wpitravel-date-rows">
				{ dates.map( ( date, index ) => (
					<div className="wpitravel-date-row" key={ index }>
						<DateInput
							label={ sprintf(
								/* translators: %d: row number. */
								__( 'Excluded date %d', 'wpinterface-travel' ),
								index + 1
							) }
							value={ date }
							onChange={ ( value ) =>
								onChange(
									dates.map( ( d, i ) =>
										i === index ? value : d
									)
								)
							}
						/>
						<Button
							icon={ trash }
							isDestructive
							label={ __( 'Remove date', 'wpinterface-travel' ) }
							onClick={ () =>
								onChange(
									dates.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</div>
				) ) }
			</div>
			<p className="wpitravel-help">
				{ __(
					'No departures on these dates, e.g. public holidays.',
					'wpinterface-travel'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ () => onChange( [ ...dates, '' ] ) }
			>
				{ __( 'Add excluded date', 'wpinterface-travel' ) }
			</Button>
		</fieldset>
	);
}

export default function DatesTab( { meta, set } ) {
	const schedule = {
		...EMPTY_SCHEDULE,
		...( meta._wpitravel_departure_rules || {} ),
	};
	const update = ( patch ) =>
		set( '_wpitravel_departure_rules', { ...schedule, ...patch } );

	return (
		<>
			<div className="wpitravel-grid wpitravel-grid--3">
				<IntegerField
					label={ __( 'Seats per departure', 'wpinterface-travel' ) }
					help={ __(
						'0 = unlimited. Can be changed for single dates below.',
						'wpinterface-travel'
					) }
					value={ schedule.capacity }
					onChange={ ( capacity ) => update( { capacity } ) }
				/>
				<IntegerField
					label={ __(
						'Stop bookings (hours before start)',
						'wpinterface-travel'
					) }
					help={ __(
						'Dates without a start time begin at midnight.',
						'wpinterface-travel'
					) }
					max={ 8760 }
					value={ schedule.cutoff_hours }
					onChange={ ( cutoffHours ) =>
						update( { cutoff_hours: cutoffHours } )
					}
				/>
				<IntegerField
					label={ __(
						'Bookable up to (days ahead)',
						'wpinterface-travel'
					) }
					help={ __( '0 = no limit.', 'wpinterface-travel' ) }
					max={ 3650 }
					value={ schedule.advance_days }
					onChange={ ( advanceDays ) =>
						update( { advance_days: advanceDays } )
					}
				/>
			</div>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'Private departures: each date is booked by one group only',
					'wpinterface-travel'
				) }
				help={ __(
					'After one booking the date closes for everyone else. Seats per departure become the largest group size.',
					'wpinterface-travel'
				) }
				checked={ !! schedule.private }
				onChange={ ( checked ) => update( { private: checked } ) }
			/>

			<FixedDates
				dates={ schedule.fixed }
				onChange={ ( fixed ) => update( { fixed } ) }
			/>

			<fieldset className="wpitravel-fieldset">
				<legend className="wpitravel-field-label">
					{ __( 'Repeating departures', 'wpinterface-travel' ) }
				</legend>
				<Repeater
					items={ schedule.recurring }
					onChange={ ( recurring ) => update( { recurring } ) }
					newItem={ () => ( {
						start: '',
						end: '',
						weekdays: [],
						months: [],
						times: [],
						capacity: 0,
					} ) }
					addLabel={ __(
						'Add repeating rule',
						'wpinterface-travel'
					) }
					emptyText={ __(
						'For trips that run on a pattern, e.g. every Saturday from April to October, or daily.',
						'wpinterface-travel'
					) }
					itemLabel={ ( rule, index ) =>
						sprintf(
							/* translators: %d: rule number. */
							__( 'Rule %d', 'wpinterface-travel' ),
							index + 1
						)
					}
					renderItem={ ( rule, patch ) => (
						<RecurringRule rule={ rule } update={ patch } />
					) }
				/>
			</fieldset>

			<ExcludedDates
				dates={ schedule.excluded }
				onChange={ ( excluded ) => update( { excluded } ) }
			/>

			<UpcomingDepartures />
		</>
	);
}
