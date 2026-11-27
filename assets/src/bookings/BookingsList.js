/**
 * Booking list: status links, search, trip and date filters, pages.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { TextField } from '../components/fields';
import StatusBadge from './StatusBadge';
import { BASE, formatDate, money, screenUrl } from './utils';

const PER_PAGE = 20;

const STATUSES = () => [
	[ '', __( 'All', 'wpinterface-travel' ) ],
	[ 'pending', __( 'Awaiting confirmation', 'wpinterface-travel' ) ],
	[ 'confirmed', __( 'Confirmed', 'wpinterface-travel' ) ],
	[
		'cancel_requested',
		__( 'Cancellation requested', 'wpinterface-travel' ),
	],
	[ 'completed', __( 'Completed', 'wpinterface-travel' ) ],
	[ 'cancelled', __( 'Cancelled', 'wpinterface-travel' ) ],
	[ 'failed', __( 'Failed', 'wpinterface-travel' ) ],
];

export default function BookingsList( { config, navigate } ) {
	const [ filters, setFilters ] = useState( () => {
		const params = new URLSearchParams( window.location.search );

		return {
			status: params.get( 'status' ) || '',
			search: '',
			trip_id: parseInt( params.get( 'trip_id' ) || '0', 10 ) || 0,
			payment_status: params.get( 'payment_status' ) || '',
			date_from: '',
			date_to: '',
			orderby: 'created',
			order: 'desc',
		};
	} );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const set = ( key, value ) => {
		setFilters( ( current ) => ( { ...current, [ key ]: value } ) );
		setPage( 1 );
	};

	const load = useCallback( () => {
		apiFetch( {
			path: addQueryArgs( `${ BASE }/bookings`, {
				...filters,
				page,
				per_page: PER_PAGE,
			} ),
			parse: false,
		} )
			.then( async ( response ) => {
				const data = await response.json();
				setResult( {
					...data,
					total: parseInt( response.headers.get( 'X-WP-Total' ), 10 ),
					pages: parseInt(
						response.headers.get( 'X-WP-TotalPages' ),
						10
					),
				} );
				setError( null );
			} )
			.catch( ( e ) =>
				setError(
					e.message ||
						__( 'Could not load bookings.', 'wpinterface-travel' )
				)
			);
	}, [ filters, page ] );

	useEffect( () => {
		const timer = setTimeout( load, filters.search ? 300 : 0 );
		return () => clearTimeout( timer );
	}, [ load, filters.search ] );

	const counts = result?.counts || {};
	const allCount = Object.values( counts ).reduce( ( a, b ) => a + b, 0 );

	const sortBy = ( key ) => {
		set( 'orderby', key );
		set(
			'order',
			filters.orderby === key && filters.order === 'desc' ? 'asc' : 'desc'
		);
	};

	const sortHeader = ( key, label ) => {
		const active = filters.orderby === key;

		return (
			<th
				scope="col"
				className={ `manage-column sortable ${
					active ? `sorted ${ filters.order }` : 'desc'
				}` }
				aria-sort={
					active
						? `${
								filters.order === 'asc'
									? 'ascending'
									: 'descending'
						  }`
						: undefined
				}
			>
				<Button variant="link" onClick={ () => sortBy( key ) }>
					{ label }
				</Button>
			</th>
		);
	};

	return (
		<div className="wpitravel-bookings">
			<div className="wpitravel-bookings__head">
				<h1 className="wp-heading-inline">
					{ __( 'Bookings', 'wpinterface-travel' ) }
				</h1>
				{ config.canManage && (
					<Button
						variant="secondary"
						href={ screenUrl( { action: 'new' } ) }
						onClick={ ( event ) => {
							event.preventDefault();
							navigate( { action: 'new' } );
						} }
					>
						{ __( 'Add booking', 'wpinterface-travel' ) }
					</Button>
				) }
			</div>

			<ul className="subsubsub">
				{ STATUSES().map( ( [ key, label ], index ) => {
					const count = key ? counts[ key ] || 0 : allCount;

					if ( key && ! count && filters.status !== key ) {
						return null;
					}

					return (
						<li key={ key || 'all' }>
							{ index > 0 && ' | ' }
							<Button
								variant="link"
								className={
									filters.status === key ? 'current' : ''
								}
								aria-current={
									filters.status === key ? 'page' : undefined
								}
								onClick={ () => set( 'status', key ) }
							>
								{ label }{ ' ' }
								<span className="count">({ count })</span>
							</Button>
						</li>
					);
				} ) }
			</ul>

			<div className="wpitravel-bookings__filters">
				<TextField
					label={ __( 'Search', 'wpinterface-travel' ) }
					help={ __(
						'Booking number, name, email or phone',
						'wpinterface-travel'
					) }
					value={ filters.search }
					onChange={ ( value ) => set( 'search', value ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Trip', 'wpinterface-travel' ) }
					value={ String( filters.trip_id ) }
					options={ [
						{
							value: '0',
							label: __( 'All trips', 'wpinterface-travel' ),
						},
						...config.trips.map( ( trip ) => ( {
							value: String( trip.id ),
							label: decodeEntities( trip.title ),
						} ) ),
					] }
					onChange={ ( value ) =>
						set( 'trip_id', parseInt( value, 10 ) )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Payment', 'wpinterface-travel' ) }
					value={ filters.payment_status }
					options={ [
						{ value: '', label: __( 'Any', 'wpinterface-travel' ) },
						{
							value: 'unpaid',
							label: __( 'Not paid', 'wpinterface-travel' ),
						},
						{
							value: 'partial',
							label: __( 'Partly paid', 'wpinterface-travel' ),
						},
						{
							value: 'paid',
							label: __( 'Paid', 'wpinterface-travel' ),
						},
						{
							value: 'refunded',
							label: __( 'Refunded', 'wpinterface-travel' ),
						},
					] }
					onChange={ ( value ) => set( 'payment_status', value ) }
				/>
				<TextField
					type="date"
					label={ __( 'Departs from', 'wpinterface-travel' ) }
					value={ filters.date_from }
					onChange={ ( value ) => set( 'date_from', value ) }
				/>
				<TextField
					type="date"
					label={ __( 'Departs until', 'wpinterface-travel' ) }
					value={ filters.date_to }
					onChange={ ( value ) => set( 'date_to', value ) }
				/>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ result === null && ! error && <Spinner /> }

			{ result && (
				<>
					<table className="wp-list-table widefat fixed striped wpitravel-bookings__table">
						<caption className="screen-reader-text">
							{ __( 'Bookings', 'wpinterface-travel' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col" className="column-primary">
									{ __( 'Booking', 'wpinterface-travel' ) }
								</th>
								<th scope="col">
									{ __( 'Trip', 'wpinterface-travel' ) }
								</th>
								{ sortHeader(
									'start_date',
									__( 'Departure', 'wpinterface-travel' )
								) }
								<th scope="col" className="num">
									{ __( 'Travelers', 'wpinterface-travel' ) }
								</th>
								{ sortHeader(
									'total',
									__( 'Total', 'wpinterface-travel' )
								) }
								<th scope="col">
									{ __( 'Status', 'wpinterface-travel' ) }
								</th>
								{ sortHeader(
									'created',
									__( 'Booked', 'wpinterface-travel' )
								) }
							</tr>
						</thead>
						<tbody>
							{ result.items.length === 0 && (
								<tr>
									<td colSpan="7">
										{ __(
											'No bookings found.',
											'wpinterface-travel'
										) }
									</td>
								</tr>
							) }
							{ result.items.map( ( booking ) => (
								<tr key={ booking.id }>
									<td className="column-primary">
										<a
											className="row-title"
											href={ screenUrl( {
												booking: booking.id,
											} ) }
											onClick={ ( event ) => {
												event.preventDefault();
												navigate( {
													booking: booking.id,
												} );
											} }
										>
											{ booking.number }
										</a>
										<div>{ booking.customer.name }</div>
										<div className="wpitravel-muted">
											{ booking.customer.email }
										</div>
									</td>
									<td>{ booking.trip.title }</td>
									<td>
										{ formatDate( config, booking.date ) }
										{ booking.time && ` ${ booking.time }` }
									</td>
									<td className="num">{ booking.pax }</td>
									<td>
										{ money(
											config,
											booking.total,
											booking.currency
										) }
										{ booking.payment_status !== 'paid' && (
											<div>
												<StatusBadge
													status={
														booking.payment_status
													}
													label={
														booking.payment_status_label
													}
												/>
											</div>
										) }
									</td>
									<td>
										<StatusBadge
											status={ booking.status }
											label={ booking.status_label }
										/>
									</td>
									<td>
										{ formatDate(
											config,
											booking.created.slice( 0, 10 )
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div className="tablenav bottom">
						<div className="tablenav-pages">
							<span className="displaying-num">
								{ sprintf(
									/* translators: %s: number of bookings. */
									_n(
										'%s booking',
										'%s bookings',
										result.total,
										'wpinterface-travel'
									),
									result.total
								) }
							</span>
							{ result.pages > 1 && (
								<span className="pagination-links">
									<Button
										variant="secondary"
										size="compact"
										disabled={ page <= 1 }
										onClick={ () => setPage( page - 1 ) }
									>
										{ __(
											'Previous',
											'wpinterface-travel'
										) }
									</Button>{ ' ' }
									{ sprintf(
										/* translators: 1: current page, 2: number of pages. */
										__(
											'%1$d of %2$d',
											'wpinterface-travel'
										),
										page,
										result.pages
									) }{ ' ' }
									<Button
										variant="secondary"
										size="compact"
										disabled={ page >= result.pages }
										onClick={ () => setPage( page + 1 ) }
									>
										{ __( 'Next', 'wpinterface-travel' ) }
									</Button>
								</span>
							) }
						</div>
					</div>
				</>
			) }
		</div>
	);
}
