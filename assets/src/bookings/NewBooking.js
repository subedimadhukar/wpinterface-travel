/**
 * Booking entered by staff (phone, email or walk-in customers). Seats and
 * prices are checked by the server exactly as for an online booking; staff
 * may also pick dates past their booking cut-off.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { IntegerField, TextField, TextareaField } from '../components/fields';
import { BASE, formatDate, money, screenUrl } from './utils';

const addDays = ( date, days ) => {
	const value = new Date( `${ date }T12:00:00Z` );
	value.setUTCDate( value.getUTCDate() + days );
	return value.toISOString().slice( 0, 10 );
};

export default function NewBooking( { config, navigate } ) {
	const trips = config.trips.filter( ( trip ) => trip.options.length > 0 );
	const [ tripId, setTripId ] = useState( trips[ 0 ]?.id || 0 );
	const [ optionKey, setOptionKey ] = useState( '' );
	const [ departures, setDepartures ] = useState( null );
	const [ slot, setSlot ] = useState( '' );
	const [ pax, setPax ] = useState( {} );
	const [ quote, setQuote ] = useState( null );
	const [ quoteError, setQuoteError ] = useState( '' );
	const [ customer, setCustomer ] = useState( {
		first_name: '',
		last_name: '',
		email: '',
		phone: '',
		country: '',
	} );
	const [ coupon, setCoupon ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ status, setStatus ] = useState( 'confirmed' );
	const [ notify, setNotify ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const trip = trips.find( ( row ) => row.id === tripId );
	const option = useMemo(
		() =>
			trip?.options.find( ( row ) => row.key === optionKey ) ||
			trip?.options[ 0 ],
		[ trip, optionKey ]
	);
	const [ date, time = '' ] = slot.split( '|' );

	// New trip or price option: start the selection again.
	useEffect( () => {
		if ( ! trip || ! option ) {
			return;
		}

		const initial = {};
		option.categories.forEach( ( category, index ) => {
			initial[ category.key ] =
				index === 0 ? Math.max( 1, category.min ) : 0;
		} );
		setPax( initial );
		setSlot( '' );
		setDepartures( null );

		apiFetch( {
			path: addQueryArgs( `${ BASE }/trips/${ trip.id }/availability`, {
				from: config.today,
				to: addDays( config.today, 365 ),
				price_option: option.key,
				all: true,
			} ),
		} )
			.then( ( data ) => setDepartures( data.departures ) )
			.catch( ( e ) => {
				setDepartures( [] );
				setError( e.message );
			} );
	}, [ trip, option, config.today ] );

	// Price the selection as it changes.
	useEffect( () => {
		setQuote( null );
		setQuoteError( '' );

		if ( ! date || ! option ) {
			return;
		}

		const timer = setTimeout( () => {
			apiFetch( {
				path: `${ BASE }/quote`,
				method: 'POST',
				data: {
					trip_id: tripId,
					date,
					time,
					price_option: option.key,
					pax,
					coupon,
					staff: true,
				},
			} )
				.then( setQuote )
				.catch( ( e ) => setQuoteError( e.message ) );
		}, 300 );

		return () => clearTimeout( timer );
	}, [ tripId, date, time, option, pax, coupon ] );

	const statusLabel = ( departure ) => {
		if ( departure.status === 'sold_out' ) {
			return __( 'sold out', 'wpinterface-travel' );
		}
		if ( departure.status === 'closed' ) {
			return __( 'closed', 'wpinterface-travel' );
		}
		if ( departure.status === 'past_cutoff' ) {
			return __( 'past booking cut-off', 'wpinterface-travel' );
		}
		return departure.seats_left === null
			? __( 'open', 'wpinterface-travel' )
			: sprintf(
					/* translators: %d: seats left. */
					__( '%d seats left', 'wpinterface-travel' ),
					departure.seats_left
			  );
	};

	const setField = ( key ) => ( value ) =>
		setCustomer( ( current ) => ( { ...current, [ key ]: value } ) );

	const submit = async () => {
		setBusy( true );
		setError( null );
		try {
			const booking = await apiFetch( {
				path: `${ BASE }/bookings/manual`,
				method: 'POST',
				data: {
					trip_id: tripId,
					date,
					time,
					price_option: option.key,
					pax,
					customer,
					coupon,
					note,
					status,
					notify,
				},
			} );
			navigate( { booking: booking.id } );
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	const ready =
		quote &&
		customer.first_name.trim() &&
		customer.last_name.trim() &&
		customer.email.includes( '@' );

	return (
		<div className="wpitravel-bookings wpitravel-new-booking">
			<p className="wpitravel-back">
				<a
					href={ screenUrl() }
					onClick={ ( event ) => {
						event.preventDefault();
						navigate( {} );
					} }
				>
					{ __( '← All bookings', 'wpinterface-travel' ) }
				</a>
			</p>
			<h1>{ __( 'Add booking', 'wpinterface-travel' ) }</h1>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ trips.length === 0 ? (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Add prices to a trip before entering bookings.',
						'wpinterface-travel'
					) }
				</Notice>
			) : (
				<div className="wpitravel-booking-detail__grid">
					<div className="wpitravel-booking-detail__main">
						<Card>
							<CardHeader>
								<h2>
									{ __(
										'Trip and date',
										'wpinterface-travel'
									) }
								</h2>
							</CardHeader>
							<CardBody className="wpitravel-form-stack">
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Trip', 'wpinterface-travel' ) }
									value={ String( tripId ) }
									options={ trips.map( ( row ) => ( {
										value: String( row.id ),
										label:
											decodeEntities( row.title ) +
											( row.status === 'publish'
												? ''
												: ` (${ row.status })` ),
									} ) ) }
									onChange={ ( value ) => {
										setTripId( parseInt( value, 10 ) );
										setOptionKey( '' );
									} }
								/>
								{ trip.options.length > 1 && (
									<SelectControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Price option',
											'wpinterface-travel'
										) }
										value={ option.key }
										options={ trip.options.map(
											( row ) => ( {
												value: row.key,
												label: row.label,
											} )
										) }
										onChange={ setOptionKey }
									/>
								) }
								{ departures === null && <Spinner /> }
								{ departures && departures.length === 0 && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ __(
											'This trip has no departures in the next 12 months. Add dates in the trip’s Departures tab.',
											'wpinterface-travel'
										) }
									</Notice>
								) }
								{ departures && departures.length > 0 && (
									<SelectControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Departure',
											'wpinterface-travel'
										) }
										value={ slot }
										options={ [
											{
												value: '',
												label: __(
													'Choose a date',
													'wpinterface-travel'
												),
											},
											...departures.map(
												( departure ) => ( {
													value: `${ departure.date }|${ departure.time }`,
													label: `${ formatDate(
														config,
														departure.date
													) }${
														departure.time
															? ` ${ departure.time }`
															: ''
													} – ${ statusLabel(
														departure
													) }`,
													disabled: [
														'closed',
														'sold_out',
													].includes(
														departure.status
													),
												} )
											),
										] }
										onChange={ setSlot }
									/>
								) }
								<div className="wpitravel-pax-fields">
									{ option.categories.map( ( category ) => (
										<IntegerField
											key={ category.key }
											label={ `${
												category.label
											} (${ money(
												config,
												category.price
											) })` }
											min={ 0 }
											max={ category.max || undefined }
											value={ pax[ category.key ] || 0 }
											onChange={ ( value ) =>
												setPax( {
													...pax,
													[ category.key ]: value,
												} )
											}
										/>
									) ) }
								</div>
								<TextField
									label={ __(
										'Coupon',
										'wpinterface-travel'
									) }
									value={ coupon }
									onChange={ setCoupon }
								/>
							</CardBody>
						</Card>

						<Card>
							<CardHeader>
								<h2>
									{ __( 'Customer', 'wpinterface-travel' ) }
								</h2>
							</CardHeader>
							<CardBody className="wpitravel-form-grid">
								<TextField
									label={ __(
										'First name',
										'wpinterface-travel'
									) }
									value={ customer.first_name }
									onChange={ setField( 'first_name' ) }
								/>
								<TextField
									label={ __(
										'Last name',
										'wpinterface-travel'
									) }
									value={ customer.last_name }
									onChange={ setField( 'last_name' ) }
								/>
								<TextField
									type="email"
									label={ __(
										'Email',
										'wpinterface-travel'
									) }
									value={ customer.email }
									onChange={ setField( 'email' ) }
								/>
								<TextField
									type="tel"
									label={ __(
										'Phone',
										'wpinterface-travel'
									) }
									value={ customer.phone }
									onChange={ setField( 'phone' ) }
								/>
								<TextField
									label={ __(
										'Country code',
										'wpinterface-travel'
									) }
									help={ __(
										'Two letters, e.g. GB',
										'wpinterface-travel'
									) }
									maxLength={ 2 }
									value={ customer.country }
									onChange={ setField( 'country' ) }
								/>
								<TextareaField
									label={ __( 'Note', 'wpinterface-travel' ) }
									rows={ 2 }
									value={ note }
									onChange={ setNote }
								/>
							</CardBody>
						</Card>
					</div>

					<div className="wpitravel-booking-detail__side">
						<Card>
							<CardHeader>
								<h2>
									{ __( 'Summary', 'wpinterface-travel' ) }
								</h2>
							</CardHeader>
							<CardBody>
								{ ! date && (
									<p className="wpitravel-muted">
										{ __(
											'Choose a departure to see the price.',
											'wpinterface-travel'
										) }
									</p>
								) }
								{ quoteError && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ quoteError }
									</Notice>
								) }
								{ quote && (
									<table className="widefat wpitravel-booking-detail__items">
										<tbody>
											{ quote.items.map(
												( item, index ) => (
													<tr key={ index }>
														<td>
															{ item.quantity > 1
																? `${ item.label } × ${ item.quantity }`
																: item.label }
														</td>
														<td className="num">
															{ money(
																config,
																item.total
															) }
														</td>
													</tr>
												)
											) }
											<tr className="is-total">
												<th>
													{ __(
														'Total',
														'wpinterface-travel'
													) }
												</th>
												<td className="num">
													<strong>
														{ money(
															config,
															quote.total
														) }
													</strong>
												</td>
											</tr>
										</tbody>
									</table>
								) }
								{ quote?.messages?.map( ( message, index ) => (
									<p
										key={ index }
										className="wpitravel-muted"
									>
										{ message.message }
									</p>
								) ) }
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Status',
										'wpinterface-travel'
									) }
									value={ status }
									options={ [
										{
											value: 'confirmed',
											label: __(
												'Confirmed',
												'wpinterface-travel'
											),
										},
										{
											value: 'pending',
											label: __(
												'Awaiting confirmation',
												'wpinterface-travel'
											),
										},
									] }
									onChange={ setStatus }
								/>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ __(
										'Email the customer',
										'wpinterface-travel'
									) }
									checked={ notify }
									onChange={ setNotify }
								/>
								<p className="wpitravel-muted">
									{ __(
										'Record any payment on the booking after saving it.',
										'wpinterface-travel'
									) }
								</p>
								<Button
									variant="primary"
									disabled={ ! ready || busy }
									isBusy={ busy }
									onClick={ submit }
								>
									{ __(
										'Save booking',
										'wpinterface-travel'
									) }
								</Button>
							</CardBody>
						</Card>
					</div>
				</div>
			) }
		</div>
	);
}
