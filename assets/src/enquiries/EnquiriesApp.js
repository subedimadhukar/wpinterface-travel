/**
 * Enquiry inbox: filter, read, reply by email, change status, delete.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { TextField } from '../components/fields';

const PATH = '/wpinterface-travel/v1/enquiries';

const STATUS_LABELS = {
	new: __( 'New', 'wpinterface-travel' ),
	replied: __( 'Replied', 'wpinterface-travel' ),
	closed: __( 'Closed', 'wpinterface-travel' ),
};

export default function EnquiriesApp( { config } ) {
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ items, setItems ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	const load = useCallback( () => {
		apiFetch( {
			path: addQueryArgs( PATH, { status, search, per_page: 50 } ),
		} )
			.then( setItems )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);
	}, [ status, search ] );

	useEffect( () => {
		const timer = setTimeout( load, 250 );
		return () => clearTimeout( timer );
	}, [ load ] );

	const update = async ( enquiry, next ) => {
		try {
			await apiFetch( {
				path: `${ PATH }/${ enquiry.id }`,
				method: 'PATCH',
				data: { status: next },
			} );
			load();
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
		}
	};

	const remove = async ( enquiry ) => {
		setDeleting( null );
		try {
			await apiFetch( {
				path: `${ PATH }/${ enquiry.id }`,
				method: 'DELETE',
			} );
			load();
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
		}
	};

	const replyLink = ( enquiry ) =>
		`mailto:${ encodeURIComponent(
			enquiry.email
		) }?subject=${ encodeURIComponent(
			sprintf(
				/* translators: %s: trip name or site name. */
				__( 'Re: your question about %s', 'wpinterface-travel' ),
				enquiry.trip_title || config.siteName
			)
		) }`;

	return (
		<div className="wpitravel-enquiries">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<div className="wpitravel-enquiries__filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Status', 'wpinterface-travel' ) }
					value={ status }
					options={ [
						{ value: '', label: __( 'All', 'wpinterface-travel' ) },
						...Object.entries( STATUS_LABELS ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ setStatus }
				/>
				<TextField
					label={ __( 'Search', 'wpinterface-travel' ) }
					value={ search }
					onChange={ setSearch }
				/>
			</div>
			{ items === null && <Spinner /> }
			{ items && items.length === 0 && (
				<p>{ __( 'No enquiries.', 'wpinterface-travel' ) }</p>
			) }
			{ items && items.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Received', 'wpinterface-travel' ) }</th>
							<th>{ __( 'From', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Trip', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Message', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Status', 'wpinterface-travel' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'wpinterface-travel' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( enquiry ) => (
							<tr key={ enquiry.id }>
								<td>{ enquiry.created }</td>
								<td>
									<strong>{ enquiry.name }</strong>
									<br />
									{ enquiry.email }
									{ enquiry.phone && (
										<>
											<br />
											{ enquiry.phone }
										</>
									) }
								</td>
								<td>{ enquiry.trip_title || '—' }</td>
								<td
									style={ {
										whiteSpace: 'pre-line',
										maxWidth: 420,
									} }
								>
									{ enquiry.message }
								</td>
								<td>
									<SelectControl
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ __(
											'Status',
											'wpinterface-travel'
										) }
										value={ enquiry.status }
										options={ Object.entries(
											STATUS_LABELS
										).map( ( [ value, label ] ) => ( {
											value,
											label,
										} ) ) }
										onChange={ ( next ) =>
											update( enquiry, next )
										}
									/>
								</td>
								<td>
									<Button
										variant="link"
										href={ replyLink( enquiry ) }
										onClick={ () =>
											enquiry.status === 'new' &&
											update( enquiry, 'replied' )
										}
									>
										{ __( 'Reply', 'wpinterface-travel' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () => setDeleting( enquiry ) }
									>
										{ __( 'Delete', 'wpinterface-travel' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ deleting && (
				<Modal
					title={ __( 'Delete enquiry', 'wpinterface-travel' ) }
					onRequestClose={ () => setDeleting( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: sender name. */
							__(
								'Delete the enquiry from %s? This cannot be undone.',
								'wpinterface-travel'
							),
							deleting.name
						) }
					</p>
					<Button
						variant="tertiary"
						onClick={ () => setDeleting( null ) }
					>
						{ __( 'Cancel', 'wpinterface-travel' ) }
					</Button>{ ' ' }
					<Button
						variant="primary"
						isDestructive
						onClick={ () => remove( deleting ) }
					>
						{ __( 'Delete', 'wpinterface-travel' ) }
					</Button>
				</Modal>
			) }
		</div>
	);
}
