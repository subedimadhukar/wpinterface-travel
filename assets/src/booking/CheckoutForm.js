/**
 * Contact and traveler details, coupon and terms, while seats are held.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { applyFilters, doAction } from '@wordpress/hooks';
import QuoteSummary from './QuoteSummary';
import PaymentMethods from './PaymentMethods';
import { postBooking, postQuote } from './api';
import { formatDate } from './dates';

/**
 * Seconds until a UTC timestamp.
 *
 * @param {string} iso Timestamp.
 * @return {number} Seconds, at least 0.
 */
const secondsUntil = ( iso ) =>
	Math.max( 0, Math.round( ( Date.parse( iso ) - Date.now() ) / 1000 ) );

function Field( { label, required, ...props } ) {
	const id = useInstanceId( Field, 'wpitravel-field' );

	return (
		<div className="wpitravel-field">
			<label htmlFor={ id }>
				{ label }
				{ required && (
					<span aria-hidden="true" className="wpitravel-required">
						{ ' ' }
						*
					</span>
				) }
			</label>
			<input id={ id } required={ required } { ...props } />
		</div>
	);
}

export default function CheckoutForm( {
	trip,
	config,
	hold,
	quote,
	option,
	onQuote,
	onBack,
	onBooked,
} ) {
	const [ customer, setCustomer ] = useState( {
		first_name: config.user?.firstName || '',
		last_name: config.user?.lastName || '',
		email: config.user?.email || '',
		phone: '',
	} );
	const [ travelers, setTravelers ] = useState( {} );
	const [ fields, setFields ] = useState( {} );
	const [ couponInput, setCouponInput ] = useState( '' );
	const [ coupon, setCoupon ] = useState( '' );
	const [ couponMessage, setCouponMessage ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ createAccount, setCreateAccount ] = useState( false );
	const [ terms, setTerms ] = useState( false );
	const [ gateway, setGateway ] = useState( config.gateways[ 0 ]?.id || '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const idBase = useInstanceId( CheckoutForm, 'wpitravel-checkout' );
	const [ remaining, setRemaining ] = useState( () =>
		secondsUntil( hold.expires_at )
	);

	useEffect( () => {
		const timer = setInterval(
			() => setRemaining( secondsUntil( hold.expires_at ) ),
			1000
		);
		return () => clearInterval( timer );
	}, [ hold.expires_at ] );

	const expired = remaining === 0;
	const needsPayment =
		parseFloat( quote.total ) > 0 && config.gateways.length > 0;
	const online =
		needsPayment &&
		config.gateways.find( ( item ) => item.id === gateway )?.online;
	const submitLabel = online
		? __( 'Continue to payment', 'wpinterface-travel' )
		: __( 'Confirm booking', 'wpinterface-travel' );
	const selection = {
		trip_id: trip.id,
		date: quote.date,
		time: quote.time,
		price_option: quote.price_option,
		pax: quote.pax,
	};

	// One name row per traveler, labelled "Adult 1", "Child 1"…
	const travelerRows = [];
	option.categories.forEach( ( category ) => {
		for ( let i = 1; i <= ( quote.pax[ category.key ] || 0 ); i++ ) {
			travelerRows.push( {
				id: `${ category.key }-${ i }`,
				category: category.key,
				label: `${ category.label } ${ i }`,
			} );
		}
	} );

	const applyCoupon = async () => {
		const code = couponInput.trim();
		setCouponMessage( '' );
		try {
			const response = await postQuote( { ...selection, coupon: code } );
			const rejected = response.messages.find(
				( message ) => message.type === 'coupon'
			);
			if ( rejected ) {
				setCouponMessage( rejected.message );
				return;
			}
			setCoupon( response.coupon || code );
			onQuote( response );
		} catch ( e ) {
			setCouponMessage( e.message );
		}
	};

	const removeCoupon = async () => {
		setCoupon( '' );
		setCouponInput( '' );
		try {
			onQuote( await postQuote( selection ) );
		} catch ( e ) {
			setError( e.message );
		}
	};

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			const result = await postBooking( {
				hold_key: hold.hold_key,
				customer,
				travelers: travelerRows
					.map( ( row ) => ( {
						category: row.category,
						...( travelers[ row.id ] || {} ),
					} ) )
					.filter( ( row ) => row.first_name || row.last_name ),
				coupon,
				note,
				create_account: createAccount,
				terms_accepted: terms,
				gateway: needsPayment ? gateway : '',
				fields,
			} );

			// For add-ons such as analytics (Pro: GA4 purchase). Runs before
			// any redirect to the payment provider.
			doAction( 'wpitravel.booking.created', {
				booking: result,
				quote,
				trip,
			} );

			if ( result.payment?.action === 'redirect' && result.payment.url ) {
				window.location.assign( result.payment.url );
				return;
			}

			onBooked( result );
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	const minutes = Math.floor( remaining / 60 );
	const seconds = String( remaining % 60 ).padStart( 2, '0' );

	return (
		<form className="wpitravel-checkout" onSubmit={ submit }>
			<h2 className="wpitravel-booking__title">
				{ __( 'Your details', 'wpinterface-travel' ) }
			</h2>
			<p className="wpitravel-booking__muted">
				{ trip.title } · { formatDate( quote.date ) }
				{ quote.time && ` · ${ quote.time }` }
			</p>

			<p
				className={
					expired
						? 'wpitravel-booking__error'
						: 'wpitravel-booking__hold'
				}
				role={ expired ? 'alert' : 'status' }
			>
				{ expired
					? __(
							'Your reserved seats have expired.',
							'wpinterface-travel'
					  )
					: sprintf(
							/* translators: %s: time left, e.g. 14:59. */
							__(
								'Your seats are held for %s.',
								'wpinterface-travel'
							),
							`${ minutes }:${ seconds }`
					  ) }
			</p>

			<fieldset className="wpitravel-checkout__group">
				<legend>{ __( 'Contact', 'wpinterface-travel' ) }</legend>
				<div className="wpitravel-checkout__grid">
					<Field
						label={ __( 'First name', 'wpinterface-travel' ) }
						required
						autoComplete="given-name"
						value={ customer.first_name }
						onChange={ ( e ) =>
							setCustomer( {
								...customer,
								first_name: e.target.value,
							} )
						}
					/>
					<Field
						label={ __( 'Last name', 'wpinterface-travel' ) }
						required
						autoComplete="family-name"
						value={ customer.last_name }
						onChange={ ( e ) =>
							setCustomer( {
								...customer,
								last_name: e.target.value,
							} )
						}
					/>
					<Field
						label={ __( 'Email', 'wpinterface-travel' ) }
						required
						type="email"
						autoComplete="email"
						value={ customer.email }
						onChange={ ( e ) =>
							setCustomer( {
								...customer,
								email: e.target.value,
							} )
						}
					/>
					<Field
						label={ __( 'Phone', 'wpinterface-travel' ) }
						type="tel"
						autoComplete="tel"
						value={ customer.phone }
						onChange={ ( e ) =>
							setCustomer( {
								...customer,
								phone: e.target.value,
							} )
						}
					/>
				</div>
			</fieldset>

			{ travelerRows.length > 1 && (
				<details className="wpitravel-checkout__group">
					<summary>
						{ __(
							'Traveler names (optional, can be sent later)',
							'wpinterface-travel'
						) }
					</summary>
					{ travelerRows.map( ( row ) => (
						<div
							className="wpitravel-checkout__grid"
							key={ row.id }
						>
							<Field
								label={ sprintf(
									/* translators: %s: traveler, e.g. "Adult 1". */
									__(
										'%s: first name',
										'wpinterface-travel'
									),
									row.label
								) }
								value={ travelers[ row.id ]?.first_name || '' }
								onChange={ ( e ) =>
									setTravelers( {
										...travelers,
										[ row.id ]: {
											...travelers[ row.id ],
											first_name: e.target.value,
										},
									} )
								}
							/>
							<Field
								label={ sprintf(
									/* translators: %s: traveler, e.g. "Adult 1". */
									__( '%s: last name', 'wpinterface-travel' ),
									row.label
								) }
								value={ travelers[ row.id ]?.last_name || '' }
								onChange={ ( e ) =>
									setTravelers( {
										...travelers,
										[ row.id ]: {
											...travelers[ row.id ],
											last_name: e.target.value,
										},
									} )
								}
							/>
						</div>
					) ) }
				</details>
			) }

			<div className="wpitravel-checkout__group">
				{ coupon ? (
					<p>
						{ sprintf(
							/* translators: %s: coupon code. */
							__( 'Coupon %s applied.', 'wpinterface-travel' ),
							coupon
						) }{ ' ' }
						<button
							type="button"
							className="wpitravel-link"
							onClick={ removeCoupon }
						>
							{ __( 'Remove', 'wpinterface-travel' ) }
						</button>
					</p>
				) : (
					<div className="wpitravel-checkout__coupon">
						<Field
							label={ __( 'Coupon code', 'wpinterface-travel' ) }
							value={ couponInput }
							onChange={ ( e ) =>
								setCouponInput( e.target.value )
							}
						/>
						<button
							type="button"
							className="wpitravel-button is-secondary"
							disabled={ ! couponInput.trim() }
							onClick={ applyCoupon }
						>
							{ __( 'Apply', 'wpinterface-travel' ) }
						</button>
					</div>
				) }
				{ couponMessage && (
					<p className="wpitravel-booking__error" role="alert">
						{ couponMessage }
					</p>
				) }
			</div>

			<div className="wpitravel-field">
				<label htmlFor={ `${ idBase }-note` }>
					{ __(
						'Notes for the organizer (optional)',
						'wpinterface-travel'
					) }
				</label>
				<textarea
					id={ `${ idBase }-note` }
					rows={ 3 }
					value={ note }
					onChange={ ( e ) => setNote( e.target.value ) }
				/>
			</div>

			<QuoteSummary quote={ quote } currency={ config.currency } />

			{ ! config.user && config.createAccounts && (
				<label
					className="wpitravel-check"
					htmlFor={ `${ idBase }-account` }
				>
					<input
						id={ `${ idBase }-account` }
						type="checkbox"
						checked={ createAccount }
						onChange={ ( e ) =>
							setCreateAccount( e.target.checked )
						}
					/>
					{ __(
						'Create an account to manage this booking',
						'wpinterface-travel'
					) }
				</label>
			) }

			{
				/**
				 * Lets Pro add checkout choices, e.g. "pay a deposit". Values set
				 * with setFields are sent as `fields` with the booking.
				 */
				applyFilters( 'wpitravel.checkout.beforePayment', null, {
					trip,
					quote,
					fields,
					setFields,
					config,
				} )
			}

			{ needsPayment && (
				<PaymentMethods
					gateways={ config.gateways }
					value={ gateway }
					onChange={ setGateway }
				/>
			) }

			{ config.terms && (
				<label
					className="wpitravel-check"
					htmlFor={ `${ idBase }-terms` }
				>
					<input
						id={ `${ idBase }-terms` }
						type="checkbox"
						required
						checked={ terms }
						onChange={ ( e ) => setTerms( e.target.checked ) }
					/>
					<span>
						{ createInterpolateElement(
							sprintf(
								/* translators: %s: terms page title. */
								__(
									'I accept the <a>%s</a>',
									'wpinterface-travel'
								),
								config.terms.title
							),
							{
								a: (
									<a
										href={ config.terms.url }
										target="_blank"
										rel="noreferrer"
									>
										{ config.terms.title }
									</a>
								),
							}
						) }
					</span>
				</label>
			) }

			{ error && (
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
			) }

			<div className="wpitravel-checkout__actions">
				<button
					type="button"
					className="wpitravel-button is-secondary"
					onClick={ onBack }
				>
					{ expired
						? __( 'Choose again', 'wpinterface-travel' )
						: __( 'Back', 'wpinterface-travel' ) }
				</button>
				<button
					type="submit"
					className="wpitravel-button"
					disabled={ busy || expired }
				>
					{ busy
						? __( 'Booking…', 'wpinterface-travel' )
						: submitLabel }
				</button>
			</div>
		</form>
	);
}
