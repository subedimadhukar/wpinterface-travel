/**
 * My bookings: a list (logged in), a lookup form (guests) and booking details.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import BookingDetail from './BookingDetail';
import formatPrice from '../components/formatPrice';
import { formatDate } from '../booking/dates';

const BASE = '/wpinterface-travel/v1';

/**
 * Booking key and token from a link in our emails.
 *
 * @return {Object|null} { key, token, review }.
 */
const fromUrl = () => {
	const params = new URLSearchParams( window.location.search );
	const key = params.get( 'booking' ) || '';
	return /^[a-f0-9]{32}$/.test( key )
		? {
				key,
				token: params.get( 'token' ) || '',
				review: params.get( 'review' ) === '1',
		  }
		: null;
};

function Lookup() {
	const id = useInstanceId( Lookup, 'wpitravel-lookup' );
	const [ number, setNumber ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ sent, setSent ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `${ BASE }/bookings/lookup`,
				method: 'POST',
				data: { number, email },
			} );
			setSent( result.message );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	return (
		<form className="wpitravel-checkout" onSubmit={ submit }>
			<h2 className="wpitravel-booking__title">
				{ __( 'Find your booking', 'wpinterface-travel' ) }
			</h2>
			{ sent && (
				<p className="wpitravel-booking__notice" role="status">
					{ sent }
				</p>
			) }
			<div className="wpitravel-checkout__grid">
				<div className="wpitravel-field">
					<label htmlFor={ `${ id }-number` }>
						{ __( 'Booking number', 'wpinterface-travel' ) }
					</label>
					<input
						id={ `${ id }-number` }
						required
						placeholder="TRV-1042"
						value={ number }
						onChange={ ( e ) => setNumber( e.target.value ) }
					/>
				</div>
				<div className="wpitravel-field">
					<label htmlFor={ `${ id }-email` }>
						{ __( 'Email used to book', 'wpinterface-travel' ) }
					</label>
					<input
						id={ `${ id }-email` }
						type="email"
						required
						autoComplete="email"
						value={ email }
						onChange={ ( e ) => setEmail( e.target.value ) }
					/>
				</div>
			</div>
			{ error && (
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
			) }
			<button
				type="submit"
				className="wpitravel-button"
				disabled={ busy }
			>
				{ __( 'Find booking', 'wpinterface-travel' ) }
			</button>
		</form>
	);
}

function MyBookings( { config, onOpen } ) {
	const [ bookings, setBookings ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: `${ BASE }/my/bookings` } )
			.then( setBookings )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( error ) {
		return (
			<p className="wpitravel-booking__error" role="alert">
				{ error }
			</p>
		);
	}

	if ( bookings === null ) {
		return (
			<p className="wpitravel-booking__muted">
				{ __( 'Loading…', 'wpinterface-travel' ) }
			</p>
		);
	}

	if ( bookings.length === 0 ) {
		return (
			<p>
				{ __(
					'You have no bookings yet. Bookings made as a guest appear here once you open them from the link in your booking email.',
					'wpinterface-travel'
				) }
			</p>
		);
	}

	return (
		<ul className="wpitravel-account__list">
			{ bookings.map( ( booking ) => (
				<li key={ booking.booking_key }>
					<button
						type="button"
						className="wpitravel-account__item"
						onClick={ () =>
							onOpen( {
								key: booking.booking_key,
								token: booking.token,
							} )
						}
					>
						<strong>{ booking.trip.title }</strong>
						<span>
							{ formatDate( booking.date ) } · { booking.number }{ ' ' }
							· { booking.status_label }
						</span>
						<span className="wpitravel-booking__muted">
							{ formatPrice(
								booking.total,
								config.currency,
								config.currency.symbol
							) }{ ' ' }
							· { booking.payment_status_label }
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}

export default function AccountApp( { config } ) {
	const [ open, setOpen ] = useState( fromUrl );
	const card = useRef();
	const firstView = useRef( true );

	// Opening a booking or going back removes the pressed button, so move
	// focus to the new view's heading once it has loaded.
	useEffect( () => {
		if ( firstView.current ) {
			firstView.current = false;
			return;
		}
		const root = card.current;
		if ( ! root ) {
			return;
		}
		const focusHeading = () => {
			const heading = root.querySelector( 'h2' );
			if ( ! heading ) {
				return false;
			}
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
			return true;
		};
		if ( focusHeading() ) {
			return;
		}
		// The booking details load first; wait for their heading (up to 10s).
		const observer = new window.MutationObserver( () => {
			if ( focusHeading() ) {
				observer.disconnect();
			}
		} );
		observer.observe( root, { childList: true, subtree: true } );
		const timer = setTimeout( () => observer.disconnect(), 10000 );
		return () => {
			observer.disconnect();
			clearTimeout( timer );
		};
	}, [ open ] );

	const openBooking = ( booking ) => {
		setOpen( booking );
		window.history.replaceState(
			null,
			'',
			addQueryArgs( config.pageUrl, {
				booking: booking.key,
				token: booking.token,
			} )
		);
	};

	const back = () => {
		setOpen( null );
		window.history.replaceState( null, '', config.pageUrl );
	};

	return (
		<div className="wpitravel-booking wpitravel-account__app">
			<div
				className="wpitravel-booking__card wpitravel-account__card"
				ref={ card }
			>
				{ open ? (
					<BookingDetail
						config={ config }
						bookingKey={ open.key }
						token={ open.token }
						startReview={ open.review }
						onBack={ back }
					/>
				) : (
					<>
						{ config.loggedIn ? (
							<>
								<h2 className="wpitravel-booking__title">
									{ __(
										'My bookings',
										'wpinterface-travel'
									) }
								</h2>
								<MyBookings
									config={ config }
									onOpen={ openBooking }
								/>
							</>
						) : (
							<>
								<Lookup />
								<p className="wpitravel-booking__muted">
									<a href={ config.loginUrl }>
										{ __(
											'Have an account? Log in to see all your bookings.',
											'wpinterface-travel'
										) }
									</a>
								</p>
							</>
						) }
					</>
				) }
			</div>
		</div>
	);
}
