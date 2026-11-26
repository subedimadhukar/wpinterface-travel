/**
 * Review form for a completed trip, sent with the booking key and token.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';

export default function ReviewForm( {
	tripId,
	bookingKey,
	token,
	scrollIntoView,
	onDone,
} ) {
	const id = useInstanceId( ReviewForm, 'wpitravel-review' );
	const [ rating, setRating ] = useState( 0 );
	const [ title, setTitle ] = useState( '' );
	const [ content, setContent ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const form = useRef();

	useEffect( () => {
		if ( scrollIntoView ) {
			form.current?.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ scrollIntoView ] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! rating ) {
			setError( __( 'Choose 1 to 5 stars.', 'wpinterface-travel' ) );
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			onDone(
				await apiFetch( {
					path: `/wpinterface-travel/v1/trips/${ tripId }/reviews`,
					method: 'POST',
					data: {
						rating,
						title,
						content,
						booking_key: bookingKey,
						token,
					},
				} )
			);
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	return (
		<form ref={ form } className="wpitravel-checkout" onSubmit={ submit }>
			<h3 className="wpitravel-booking__step">
				{ __( 'How was your trip?', 'wpinterface-travel' ) }
			</h3>
			<fieldset className="wpitravel-rating">
				<legend>{ __( 'Your rating', 'wpinterface-travel' ) }</legend>
				{ [ 1, 2, 3, 4, 5 ].map( ( value ) => (
					<span key={ value }>
						<input
							id={ `${ id }-star-${ value }` }
							type="radio"
							name={ `${ id }-rating` }
							value={ value }
							checked={ rating === value }
							onChange={ () => setRating( value ) }
						/>
						<label
							htmlFor={ `${ id }-star-${ value }` }
							className={ value <= rating ? 'is-on' : '' }
						>
							<span aria-hidden="true">★</span>
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: %d: stars. */
									__( '%d stars', 'wpinterface-travel' ),
									value
								) }
							</span>
						</label>
					</span>
				) ) }
			</fieldset>
			<div className="wpitravel-field">
				<label htmlFor={ `${ id }-title` }>
					{ __( 'Title (optional)', 'wpinterface-travel' ) }
				</label>
				<input
					id={ `${ id }-title` }
					maxLength={ 120 }
					value={ title }
					onChange={ ( e ) => setTitle( e.target.value ) }
				/>
			</div>
			<div className="wpitravel-field">
				<label htmlFor={ `${ id }-content` }>
					{ __( 'Your review', 'wpinterface-travel' ) }
				</label>
				<textarea
					id={ `${ id }-content` }
					rows={ 5 }
					required
					minLength={ 10 }
					value={ content }
					onChange={ ( e ) => setContent( e.target.value ) }
				/>
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
				{ __( 'Publish review', 'wpinterface-travel' ) }
			</button>
		</form>
	);
}
