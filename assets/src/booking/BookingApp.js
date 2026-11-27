/**
 * Booking flow: choose date and travelers → details (seats held) → confirmation.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { applyFilters, doAction } from '@wordpress/hooks';
import Calendar from './Calendar';
import PaxSelector from './PaxSelector';
import QuoteSummary from './QuoteSummary';
import CheckoutForm from './CheckoutForm';
import Confirmation from './Confirmation';
import PaymentResult from './PaymentResult';
import EnquiryForm from './EnquiryForm';
import { deleteHold, postHold, postQuote } from './api';
import { formatDate } from './dates';

/**
 * Starting traveler counts: each type's minimum, and at least one traveler.
 *
 * @param {Object} option Price option.
 * @return {Object} Counts by category key.
 */
const initialPax = ( option ) => {
	const pax = {};
	option.categories.forEach( ( category ) => {
		pax[ category.key ] = category.min;
	} );
	const total = Object.values( pax ).reduce( ( sum, n ) => sum + n, 0 );
	const needed = Math.max( 1, option.minPax ) - total;
	if ( needed > 0 ) {
		pax[ option.categories[ 0 ].key ] += needed;
	}
	return pax;
};

/**
 * Result of an online payment, from the URL the provider returned to.
 *
 * @return {Object|null} { status, number, bookingKey } or null.
 */
const readReturn = () => {
	const params = new URLSearchParams( window.location.search );
	const status = params.get( 'wpitravel_result' );
	const bookingKey = params.get( 'wpitravel_booking' );

	if ( ! status || ! /^[a-f0-9]{32}$/.test( bookingKey || '' ) ) {
		return null;
	}

	return {
		status,
		bookingKey,
		number: params.get( 'wpitravel_number' ) || '',
	};
};

export default function BookingApp( { trip, config } ) {
	const [ returned, setReturned ] = useState( readReturn );
	const [ step, setStep ] = useState( returned ? 'result' : 'select' );
	const [ optionKey, setOptionKey ] = useState( trip.options[ 0 ].key );
	const option =
		trip.options.find( ( item ) => item.key === optionKey ) ||
		trip.options[ 0 ];
	const [ departure, setDeparture ] = useState( null );
	const [ pax, setPax ] = useState( () => initialPax( option ) );
	const [ extras, setExtras ] = useState( {} );
	const [ quote, setQuote ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ hold, setHold ] = useState( null );
	const [ booking, setBooking ] = useState( null );
	const [ asking, setAsking ] = useState( false );
	const request = useRef( 0 );
	const root = useRef();

	const selection = departure
		? {
				trip_id: trip.id,
				date: departure.date,
				time: departure.time,
				price_option: option.key,
				pax,
				extras,
		  }
		: null;

	// Live price: re-quote (debounced) whenever the selection changes.
	useEffect( () => {
		if ( ! selection || step !== 'select' ) {
			return;
		}
		const id = ++request.current;
		const timer = setTimeout( () => {
			postQuote( selection )
				.then( ( response ) => {
					if ( id === request.current ) {
						setQuote( response );
						setError( '' );
					}
				} )
				.catch( ( e ) => {
					if ( id === request.current ) {
						setQuote( null );
						setError( e.message );
					}
				} );
		}, 250 );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( selection ), step ] );

	const scrollToTop = () =>
		root.current?.scrollIntoView( { behavior: 'smooth', block: 'start' } );

	// When the step changes, move keyboard and screen reader focus to the
	// new step's heading (the button that was pressed is gone).
	const firstStep = useRef( true );
	useEffect( () => {
		if ( firstStep.current ) {
			firstStep.current = false;
			return;
		}
		const heading = root.current?.querySelector(
			'h2, .wpitravel-confirmation'
		);
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
	}, [ step ] );

	const changeOption = ( key ) => {
		const next = trip.options.find( ( item ) => item.key === key );
		setOptionKey( key );
		setPax( initialPax( next ) );
		setExtras( {} );
		setDeparture( null );
		setQuote( null );
	};

	const reserve = async () => {
		setBusy( true );
		setError( '' );
		try {
			const response = await postHold( selection );
			setHold( response );
			setQuote( response.quote );
			// For add-ons such as analytics (Pro: GA4 begin_checkout).
			doAction( 'wpitravel.booking.checkoutStarted', {
				trip,
				quote: response.quote,
			} );
			setStep( 'details' );
			scrollToTop();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	const backToSelect = () => {
		if ( hold ) {
			deleteHold( hold.hold_key );
		}
		setHold( null );
		setStep( 'select' );
		scrollToTop();
	};

	// Remove the result from the address bar, so a reload starts fresh.
	useEffect( () => {
		if ( returned ) {
			const url = new URL( window.location.href );
			[
				'wpitravel_result',
				'wpitravel_booking',
				'wpitravel_number',
			].forEach( ( key ) => url.searchParams.delete( key ) );
			window.history.replaceState( null, '', url.toString() );
		}
	}, [ returned ] );

	const loginRequired = ! config.guestCheckout && ! config.user;

	return (
		<section
			ref={ root }
			className="wpitravel-booking__card"
			aria-label={ __( 'Book this trip', 'wpinterface-travel' ) }
		>
			{ step === 'select' && (
				<>
					<h2 className="wpitravel-booking__title">
						{ __( 'Book this trip', 'wpinterface-travel' ) }
					</h2>

					{ trip.options.length > 1 && (
						<fieldset className="wpitravel-booking__options">
							<legend>
								{ __( 'Option', 'wpinterface-travel' ) }
							</legend>
							{ trip.options.map( ( item ) => (
								<label
									key={ item.key }
									className="wpitravel-chip"
									htmlFor={ `wpitravel-option-${ trip.id }-${ item.key }` }
								>
									<input
										id={ `wpitravel-option-${ trip.id }-${ item.key }` }
										type="radio"
										name={ `wpitravel-option-${ trip.id }` }
										value={ item.key }
										checked={ item.key === option.key }
										onChange={ () =>
											changeOption( item.key )
										}
									/>
									{ item.label }
								</label>
							) ) }
						</fieldset>
					) }

					<h3 className="wpitravel-booking__step">
						{ __( '1. Choose a date', 'wpinterface-travel' ) }
					</h3>
					<Calendar
						tripId={ trip.id }
						priceOption={ option.key }
						config={ config }
						value={ departure }
						onChange={ setDeparture }
					/>

					{ departure && (
						<>
							<h3 className="wpitravel-booking__step">
								{ __( '2. Travelers', 'wpinterface-travel' ) }
							</h3>
							<p className="wpitravel-booking__muted">
								{ formatDate( departure.date ) }
								{ departure.time && ` · ${ departure.time }` }
								{ departure.end_date !== departure.date &&
									` – ${ formatDate( departure.end_date ) }` }
							</p>
							<PaxSelector
								option={ option }
								pax={ pax }
								onChange={ setPax }
								seatsLeft={ departure.seats_left }
								currency={ config.currency }
							/>
							{
								/**
								 * Lets Pro add choices under the travelers, e.g. tour
								 * extras. Return an element (or null). Values set with
								 * setExtras are priced and booked with the selection.
								 */
								applyFilters(
									'wpitravel.booking.afterPax',
									null,
									{
										trip,
										option,
										pax,
										extras,
										setExtras,
										config,
									}
								)
							}
						</>
					) }

					{ quote && departure && (
						<QuoteSummary
							quote={ quote }
							currency={ config.currency }
						/>
					) }

					{ error && (
						<p className="wpitravel-booking__error" role="alert">
							{ error }
						</p>
					) }

					{ departure &&
						( loginRequired ? (
							<a
								className="wpitravel-button"
								href={ config.loginUrl }
							>
								{ __( 'Log in to book', 'wpinterface-travel' ) }
							</a>
						) : (
							<button
								type="button"
								className="wpitravel-button"
								disabled={ ! quote || busy }
								onClick={ reserve }
							>
								{ busy
									? __( 'Reserving…', 'wpinterface-travel' )
									: __( 'Continue', 'wpinterface-travel' ) }
							</button>
						) ) }
					{ departure &&
						! loginRequired &&
						/**
						 * Lets Pro add actions next to Continue, e.g. "Add to
						 * cart". Return an element (or null).
						 */
						applyFilters( 'wpitravel.booking.actions', null, {
							trip,
							selection,
							quote,
							config,
							disabled: ! quote || busy,
						} ) }
					{ asking ? (
						<EnquiryForm
							tripId={ trip.id }
							user={ config.user }
							onClose={ () => setAsking( false ) }
						/>
					) : (
						<button
							type="button"
							className="wpitravel-link"
							onClick={ () => setAsking( true ) }
						>
							{ __(
								'Have a question? Ask us',
								'wpinterface-travel'
							) }
						</button>
					) }
				</>
			) }

			{ step === 'details' && hold && (
				<CheckoutForm
					trip={ trip }
					config={ config }
					hold={ hold }
					quote={ quote }
					option={ option }
					onQuote={ setQuote }
					onBack={ backToSelect }
					onBooked={ ( result ) => {
						setBooking( result );
						setStep( 'done' );
						scrollToTop();
					} }
				/>
			) }

			{ step === 'result' && returned && (
				<PaymentResult
					result={ returned }
					config={ config }
					onDone={ () => {
						setReturned( null );
						setStep( 'select' );
					} }
				/>
			) }

			{ step === 'done' && booking && (
				<Confirmation
					booking={ booking }
					trip={ trip }
					quote={ quote }
					config={ config }
				/>
			) }
		</section>
	);
}
