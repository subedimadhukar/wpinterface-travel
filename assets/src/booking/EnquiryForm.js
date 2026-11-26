/**
 * "Ask a question" form. Sends to POST /enquiries.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';

export default function EnquiryForm( { tripId = 0, user = null, onClose } ) {
	const id = useInstanceId( EnquiryForm, 'wpitravel-enquiry' );
	const [ values, setValues ] = useState( {
		name: user
			? `${ user.firstName || '' } ${ user.lastName || '' }`.trim()
			: '',
		email: user?.email || '',
		phone: '',
		message: '',
		website: '',
	} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ sent, setSent ] = useState( false );
	const set = ( key ) => ( event ) =>
		setValues( { ...values, [ key ]: event.target.value } );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			await apiFetch( {
				path: '/wpinterface-travel/v1/enquiries',
				method: 'POST',
				data: { ...values, trip_id: tripId },
			} );
			setSent( true );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	if ( sent ) {
		return (
			<p className="wpitravel-booking__notice" role="status">
				{ __(
					'Thank you! We received your message and will reply by email.',
					'wpinterface-travel'
				) }
			</p>
		);
	}

	const field = ( key, label, props = {} ) => (
		<div className="wpitravel-field">
			<label htmlFor={ `${ id }-${ key }` }>{ label }</label>
			<input
				id={ `${ id }-${ key }` }
				value={ values[ key ] }
				onChange={ set( key ) }
				{ ...props }
			/>
		</div>
	);

	return (
		<form className="wpitravel-enquiry-form" onSubmit={ submit }>
			<h3 className="wpitravel-booking__step">
				{ __( 'Ask a question', 'wpinterface-travel' ) }
			</h3>
			<div className="wpitravel-checkout__grid">
				{ field( 'name', __( 'Name', 'wpinterface-travel' ), {
					required: true,
					autoComplete: 'name',
				} ) }
				{ field( 'email', __( 'Email', 'wpinterface-travel' ), {
					required: true,
					type: 'email',
					autoComplete: 'email',
				} ) }
				{ field(
					'phone',
					__( 'Phone (optional)', 'wpinterface-travel' ),
					{
						type: 'tel',
						autoComplete: 'tel',
					}
				) }
			</div>
			<div className="wpitravel-field">
				<label htmlFor={ `${ id }-message` }>
					{ __( 'Your question', 'wpinterface-travel' ) }
				</label>
				<textarea
					id={ `${ id }-message` }
					rows={ 4 }
					required
					minLength={ 10 }
					value={ values.message }
					onChange={ set( 'message' ) }
				/>
			</div>
			{ /* Honeypot: hidden from people, filled in by bots. */ }
			<div className="wpitravel-hp" aria-hidden="true">
				<label htmlFor={ `${ id }-website` }>Website</label>
				<input
					id={ `${ id }-website` }
					tabIndex={ -1 }
					autoComplete="off"
					value={ values.website }
					onChange={ set( 'website' ) }
				/>
			</div>
			{ error && (
				<p className="wpitravel-booking__error" role="alert">
					{ error }
				</p>
			) }
			<div className="wpitravel-checkout__actions">
				{ onClose && (
					<button
						type="button"
						className="wpitravel-button is-secondary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'wpinterface-travel' ) }
					</button>
				) }
				<button
					type="submit"
					className="wpitravel-button"
					disabled={ busy }
				>
					{ busy
						? __( 'Sending…', 'wpinterface-travel' )
						: __( 'Send', 'wpinterface-travel' ) }
				</button>
			</div>
		</form>
	);
}
