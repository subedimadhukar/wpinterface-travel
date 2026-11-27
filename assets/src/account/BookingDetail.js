/**
 * One booking for its customer: details, pay, cancel, voucher, review.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import formatPrice from '../components/formatPrice';
import { formatDate } from '../booking/dates';
import PayAgain from '../booking/PayAgain';
import ReviewForm from './ReviewForm';

const BASE = '/wpinterface-travel/v1';

function CancelRequest( { bookingKey, token, onDone } ) {
	const id = useInstanceId( CancelRequest, 'wpitravel-cancel' );
	const [ open, setOpen ] = useState( false );
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			onDone(
				await apiFetch( {
					path: `${ BASE }/bookings/${ bookingKey }/cancel-request`,
					method: 'POST',
					data: { token, reason },
				} )
			);
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	if ( ! open ) {
		return (
			<button
				type="button"
				className="wpitravel-link"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Ask to cancel this booking', 'wpinterface-travel' ) }
			</button>
		);
	}

	return (
		<form className="wpitravel-checkout" onSubmit={ submit }>
			<div className="wpitravel-field">
				<label htmlFor={ id }>
					{ __(
						'Why do you want to cancel? (optional)',
						'wpinterface-travel'
					) }
				</label>
				<textarea
					id={ id }
					rows={ 3 }
					value={ reason }
					onChange={ ( e ) => setReason( e.target.value ) }
				/>
			</div>
			<p className="wpitravel-booking__muted">
				{ __(
					'We will review your request and contact you about any refund, according to our cancellation terms.',
					'wpinterface-travel'
				) }
			</p>
			{ error && (
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
			) }
			<div className="wpitravel-checkout__actions">
				<button
					type="button"
					className="wpitravel-button is-secondary"
					onClick={ () => setOpen( false ) }
				>
					{ __( 'Keep my booking', 'wpinterface-travel' ) }
				</button>
				<button
					type="submit"
					className="wpitravel-button"
					disabled={ busy }
				>
					{ __( 'Send cancellation request', 'wpinterface-travel' ) }
				</button>
			</div>
		</form>
	);
}

export default function BookingDetail( {
	config,
	bookingKey,
	token,
	startReview,
	onBack,
} ) {
	const [ booking, setBooking ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ instructions, setInstructions ] = useState( '' );
	const [ reviewed, setReviewed ] = useState( null );

	const load = useCallback( () => {
		apiFetch( {
			path: addQueryArgs( `${ BASE }/bookings/${ bookingKey }`, {
				token,
			} ),
		} )
			.then( setBooking )
			.catch( ( e ) => setError( e.message ) );
	}, [ bookingKey, token ] );

	useEffect( load, [ load ] );

	const back = (
		<button type="button" className="wpitravel-link" onClick={ onBack }>
			{ config.loggedIn
				? __( '← All my bookings', 'wpinterface-travel' )
				: __( '← Find another booking', 'wpinterface-travel' ) }
		</button>
	);

	if ( error ) {
		return (
			<>
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
				{ back }
			</>
		);
	}

	if ( ! booking ) {
		return (
			<p className="wpitravel-booking__muted">
				{ __( 'Loading…', 'wpinterface-travel' ) }
			</p>
		);
	}

	const money = ( amount ) =>
		formatPrice( amount, config.currency, config.currency.symbol );

	return (
		<div className="wpitravel-account__detail">
			{ back }
			<h2 className="wpitravel-booking__title">{ booking.trip.title }</h2>
			<p className="wpitravel-account__badges">
				<span className={ `wpitravel-badge is-${ booking.status }` }>
					{ booking.status_label }
				</span>
				<span className="wpitravel-badge">
					{ booking.payment_status_label }
				</span>
			</p>

			<dl className="wpitravel-account__facts">
				<dt>{ __( 'Booking number', 'wpinterface-travel' ) }</dt>
				<dd>{ booking.number }</dd>
				<dt>{ __( 'Date', 'wpinterface-travel' ) }</dt>
				<dd>
					{ formatDate( booking.date ) }
					{ booking.time && ` · ${ booking.time }` }
					{ booking.end_date !== booking.date &&
						` – ${ formatDate( booking.end_date ) }` }
				</dd>
				<dt>{ __( 'Travelers', 'wpinterface-travel' ) }</dt>
				<dd>
					{ booking.travelers
						.map( ( t ) =>
							`${ t.first_name } ${ t.last_name }`.trim()
						)
						.filter( Boolean )
						.join( ', ' ) || booking.pax }
				</dd>
			</dl>

			<dl className="wpitravel-summary">
				{ booking.items.map( ( item, index ) => (
					<div
						className={ `wpitravel-summary__row is-${ item.type }` }
						key={ index }
					>
						<dt>
							{ item.type === 'pax'
								? `${ item.label } × ${ item.quantity }`
								: item.label }
						</dt>
						<dd>
							{ item.included
								? `(${ money( item.total ) })`
								: money( item.total ) }
						</dd>
					</div>
				) ) }
				<div className="wpitravel-summary__row is-total">
					<dt>{ __( 'Total', 'wpinterface-travel' ) }</dt>
					<dd>{ money( booking.total ) }</dd>
				</div>
				<div className="wpitravel-summary__row">
					<dt>{ __( 'Paid', 'wpinterface-travel' ) }</dt>
					<dd>{ money( booking.amount_paid ) }</dd>
				</div>
				<div className="wpitravel-summary__row">
					<dt>{ __( 'Balance', 'wpinterface-travel' ) }</dt>
					<dd>{ money( booking.balance ) }</dd>
				</div>
			</dl>

			{ ( booking.notes || [] ).map( ( note, index ) => (
				<p key={ index } className="wpitravel-booking__notice">
					{ note }
				</p>
			) ) }

			{ booking.links?.length > 0 && (
				<>
					<h3 className="wpitravel-booking__step">
						{ __( 'Files for your trip', 'wpinterface-travel' ) }
					</h3>
					<ul className="wpitravel-booking__links">
						{ booking.links.map( ( link ) => (
							<li key={ link.url }>
								<a href={ link.url }>{ link.label }</a>
								{ link.note && (
									<span className="wpitravel-booking__link-note">
										{ link.note }
									</span>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }

			{ booking.can_pay && ! instructions && (
				<>
					<h3 className="wpitravel-booking__step">
						{ booking.amount_due &&
						booking.amount_due !== booking.balance
							? sprintf(
									/* translators: %s: amount. */
									__( 'Pay %s now', 'wpinterface-travel' ),
									money( booking.amount_due )
							  )
							: sprintf(
									/* translators: %s: amount. */
									__(
										'Pay the balance of %s',
										'wpinterface-travel'
									),
									money( booking.balance )
							  ) }
					</h3>
					<PayAgain
						bookingKey={ booking.booking_key }
						gateways={ config.gateways }
						onInstructions={ setInstructions }
					/>
				</>
			) }
			{ instructions && (
				<p className="wpitravel-instructions">{ instructions }</p>
			) }

			<div className="wpitravel-account__actions">
				{ booking.voucher_url && (
					<a
						className="wpitravel-button is-secondary"
						href={ booking.voucher_url }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Voucher (print or PDF)', 'wpinterface-travel' ) }
					</a>
				) }
				{ booking.can_cancel && (
					<CancelRequest
						bookingKey={ booking.booking_key }
						token={ booking.token }
						onDone={ setBooking }
					/>
				) }
			</div>

			{ config.reviews && booking.can_review && ! reviewed && (
				<ReviewForm
					tripId={ booking.trip.id }
					bookingKey={ booking.booking_key }
					token={ booking.token }
					scrollIntoView={ startReview }
					onDone={ setReviewed }
				/>
			) }
			{ reviewed && (
				<p className="wpitravel-booking__notice" role="status">
					{ reviewed.published
						? __(
								'Thank you! Your review is now on the trip page.',
								'wpinterface-travel'
						  )
						: __(
								'Thank you! Your review will appear after we check it.',
								'wpinterface-travel'
						  ) }
				</p>
			) }
		</div>
	);
}
