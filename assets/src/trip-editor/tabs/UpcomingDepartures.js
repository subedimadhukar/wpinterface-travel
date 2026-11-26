/**
 * The next 90 days of departures from the saved schedule, with seats and
 * a Close / Reopen action per date.
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';

const STATUS_LABELS = {
	open: __( 'Open', 'wpinterface-travel' ),
	sold_out: __( 'Sold out', 'wpinterface-travel' ),
	closed: __( 'Closed', 'wpinterface-travel' ),
	cancelled: __( 'Cancelled', 'wpinterface-travel' ),
	past_cutoff: __( 'Booking closed (cut-off)', 'wpinterface-travel' ),
	not_yet_open: __( 'Not yet bookable', 'wpinterface-travel' ),
	unscheduled: __( 'Removed from schedule', 'wpinterface-travel' ),
};

export default function UpcomingDepartures() {
	const { postId, isSaving } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			isSaving: select( editorStore ).isSavingPost(),
		} ),
		[]
	);
	const [ departures, setDepartures ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	const load = useCallback( () => {
		setError( '' );
		apiFetch( {
			path: `/wpinterface-travel/v1/trips/${ postId }/departures`,
		} )
			.then( ( response ) => setDepartures( response.departures ) )
			.catch( ( e ) => setError( e.message ) );
	}, [ postId ] );

	// Reload after each save, since the list reflects the saved schedule.
	useEffect( () => {
		if ( postId && ! isSaving ) {
			load();
		}
	}, [ postId, isSaving, load ] );

	const setStatus = async ( departure, status ) => {
		const key = `${ departure.date }|${ departure.time }`;
		setBusy( key );
		try {
			await apiFetch(
				departure.departure_id
					? {
							path: `/wpinterface-travel/v1/departures/${ departure.departure_id }`,
							method: 'PATCH',
							data: { status },
					  }
					: {
							path: `/wpinterface-travel/v1/trips/${ postId }/departures`,
							method: 'POST',
							data: {
								date: departure.date,
								time: departure.time,
								status,
							},
					  }
			);
			load();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	return (
		<fieldset className="wpitravel-fieldset">
			<legend className="wpitravel-field-label">
				{ __(
					'Upcoming departures (next 90 days)',
					'wpinterface-travel'
				) }
			</legend>
			<p className="wpitravel-help">
				{ __(
					'Based on the saved schedule. Save the trip to see changes above reflected here.',
					'wpinterface-travel'
				) }
			</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ departures === null && ! error && <Spinner /> }
			{ departures && departures.length === 0 && (
				<p>
					{ __(
						'No departures in the next 90 days.',
						'wpinterface-travel'
					) }
				</p>
			) }
			{ departures && departures.length > 0 && (
				<table className="widefat striped wpitravel-departures">
					<thead>
						<tr>
							<th>{ __( 'Date', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Time', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Seats', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Status', 'wpinterface-travel' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'wpinterface-travel' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ departures.map( ( departure ) => {
							const key = `${ departure.date }|${ departure.time }`;
							const isClosed =
								departure.status === 'closed' ||
								departure.status === 'cancelled';
							return (
								<tr key={ key }>
									<td>{ departure.date }</td>
									<td>{ departure.time || '—' }</td>
									<td>
										{ departure.capacity === null
											? `${ departure.seats_booked } / ∞`
											: `${ departure.seats_booked } / ${ departure.capacity }` }
									</td>
									<td>
										{ STATUS_LABELS[ departure.status ] ||
											departure.status }
									</td>
									<td>
										<Button
											variant="link"
											isBusy={ busy === key }
											disabled={ !! busy }
											onClick={ () =>
												setStatus(
													departure,
													isClosed ? 'open' : 'closed'
												)
											}
										>
											{ isClosed
												? __(
														'Reopen',
														'wpinterface-travel'
												  )
												: __(
														'Close date',
														'wpinterface-travel'
												  ) }
										</Button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
		</fieldset>
	);
}
