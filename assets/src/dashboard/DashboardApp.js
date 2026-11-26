/**
 * Travel dashboard: what needs attention, sales over a period, top trips
 * and upcoming departures.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { dateI18n } from '@wordpress/date';
import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import formatPrice from '../components/formatPrice';
import RevenueChart from './RevenueChart';

export default function DashboardApp( { config } ) {
	const [ period, setPeriod ] = useState( '30d' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		apiFetch( {
			path: addQueryArgs( '/wpinterface-travel/v1/reports/summary', {
				period,
			} ),
		} )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ period ] );

	const money = ( amount ) =>
		formatPrice(
			parseFloat( amount ) || 0,
			config.currency,
			config.symbol
		);
	const date = ( value ) =>
		value ? dateI18n( config.dateFormat, `${ value }T12:00:00` ) : '';
	const bookingsLink = ( params ) =>
		addQueryArgs( config.bookingsUrl, params );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	const attention = data
		? [
				[
					data.attention.pending,
					/* translators: %s: number of bookings. */
					_n(
						'%s booking awaiting confirmation',
						'%s bookings awaiting confirmation',
						data.attention.pending,
						'wpinterface-travel'
					),
					{ status: 'pending' },
				],
				[
					data.attention.cancel_requested,
					/* translators: %s: number of bookings. */
					_n(
						'%s cancellation request',
						'%s cancellation requests',
						data.attention.cancel_requested,
						'wpinterface-travel'
					),
					{ status: 'cancel_requested' },
				],
				[
					data.attention.unpaid_soon,
					/* translators: %s: number of bookings. */
					_n(
						'%s unpaid booking leaving within a week',
						'%s unpaid bookings leaving within a week',
						data.attention.unpaid_soon,
						'wpinterface-travel'
					),
					{ payment_status: 'unpaid' },
				],
		  ].filter( ( [ count ] ) => count > 0 )
		: [];

	return (
		<div className="wpitravel-dashboard">
			<div className="wpitravel-dashboard__toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Period', 'wpinterface-travel' ) }
					value={ period }
					options={ [
						{
							value: '7d',
							label: __( 'Last 7 days', 'wpinterface-travel' ),
						},
						{
							value: '30d',
							label: __( 'Last 30 days', 'wpinterface-travel' ),
						},
						{
							value: '90d',
							label: __( 'Last 90 days', 'wpinterface-travel' ),
						},
						{
							value: '12m',
							label: __( 'Last 12 months', 'wpinterface-travel' ),
						},
					] }
					onChange={ setPeriod }
				/>
			</div>

			{ ! data && <Spinner /> }

			{ data && (
				<>
					{ attention.length > 0 && (
						<Notice
							status="warning"
							isDismissible={ false }
							className="wpitravel-dashboard__attention"
						>
							<ul>
								{ attention.map(
									( [ count, label, params ] ) => (
										<li key={ label }>
											<a href={ bookingsLink( params ) }>
												{ sprintf( label, count ) }
											</a>
										</li>
									)
								) }
							</ul>
						</Notice>
					) }

					<div className="wpitravel-dashboard__stats">
						<Stat
							label={ __( 'Received', 'wpinterface-travel' ) }
							value={ money( data.revenue ) }
							help={ __(
								'Payments minus refunds',
								'wpinterface-travel'
							) }
						/>
						<Stat
							label={ __( 'Bookings', 'wpinterface-travel' ) }
							value={ data.bookings }
						/>
						<Stat
							label={ __( 'Travelers', 'wpinterface-travel' ) }
							value={ data.pax }
						/>
						<Stat
							label={ __(
								'Booking value',
								'wpinterface-travel'
							) }
							value={ money( data.booking_value ) }
							help={ sprintf(
								/* translators: %s: average booking value. */
								__( 'Average %s', 'wpinterface-travel' ),
								money( data.average_value )
							) }
						/>
					</div>

					{ data.other_currencies.length > 0 && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: currency codes. */
								__(
									'Payments in %s are not included in these totals.',
									'wpinterface-travel'
								),
								data.other_currencies.join( ', ' )
							) }
						</Notice>
					) }

					<Card className="wpitravel-dashboard__chart">
						<CardHeader>
							<h2>
								{ __( 'Money received', 'wpinterface-travel' ) }
							</h2>
						</CardHeader>
						<CardBody>
							<RevenueChart
								series={ data.series }
								interval={ data.interval }
								money={ money }
								dateFormat={ config.dateFormat }
							/>
						</CardBody>
					</Card>

					<div className="wpitravel-dashboard__grid">
						<Card>
							<CardHeader>
								<h2>
									{ __( 'Top trips', 'wpinterface-travel' ) }
								</h2>
							</CardHeader>
							<CardBody>
								{ data.top_trips.length === 0 ? (
									<p>
										{ __(
											'No bookings in this period.',
											'wpinterface-travel'
										) }
									</p>
								) : (
									<table className="widefat striped">
										<thead>
											<tr>
												<th>
													{ __(
														'Trip',
														'wpinterface-travel'
													) }
												</th>
												<th className="num">
													{ __(
														'Bookings',
														'wpinterface-travel'
													) }
												</th>
												<th className="num">
													{ __(
														'Travelers',
														'wpinterface-travel'
													) }
												</th>
												<th className="num">
													{ __(
														'Value',
														'wpinterface-travel'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ data.top_trips.map( ( trip ) => (
												<tr key={ trip.id }>
													<td>
														<a
															href={ bookingsLink(
																{
																	trip_id:
																		trip.id,
																}
															) }
														>
															{ trip.title }
														</a>
													</td>
													<td className="num">
														{ trip.bookings }
													</td>
													<td className="num">
														{ trip.pax }
													</td>
													<td className="num">
														{ money( trip.value ) }
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								) }
							</CardBody>
						</Card>

						<Card>
							<CardHeader>
								<h2>
									{ __(
										'Next 14 days',
										'wpinterface-travel'
									) }
								</h2>
							</CardHeader>
							<CardBody>
								{ data.upcoming.length === 0 ? (
									<p>
										{ __(
											'No departures with travelers.',
											'wpinterface-travel'
										) }
									</p>
								) : (
									<ul className="wpitravel-dashboard__list">
										{ data.upcoming.map( ( row ) => (
											<li
												key={ `${ row.trip.id }-${ row.date }-${ row.time }` }
											>
												<span>
													<strong>
														{ date( row.date ) }
														{ row.time &&
															` ${ row.time }` }
													</strong>
													<br />
													{ row.trip.title }
												</span>
												<span className="wpitravel-dashboard__seats">
													{ row.capacity
														? sprintf(
																/* translators: 1: seats booked, 2: capacity. */
																__(
																	'%1$d / %2$d seats',
																	'wpinterface-travel'
																),
																row.booked,
																row.capacity
														  )
														: sprintf(
																/* translators: %d: travelers. */
																_n(
																	'%d traveler',
																	'%d travelers',
																	row.booked,
																	'wpinterface-travel'
																),
																row.booked
														  ) }
												</span>
											</li>
										) ) }
									</ul>
								) }
							</CardBody>
						</Card>

						{ data.recent.length > 0 && (
							<Card>
								<CardHeader>
									<h2>
										{ __(
											'Latest bookings',
											'wpinterface-travel'
										) }
									</h2>
								</CardHeader>
								<CardBody>
									<ul className="wpitravel-dashboard__list">
										{ data.recent.map( ( booking ) => (
											<li key={ booking.id }>
												<span>
													<a
														href={ bookingsLink( {
															booking: booking.id,
														} ) }
													>
														{ booking.number }
													</a>{ ' ' }
													{ booking.customer.name }
													<br />
													<span className="wpitravel-dashboard__muted">
														{ booking.trip.title } ·{ ' ' }
														{ date( booking.date ) }
													</span>
												</span>
												<span>
													{ money( booking.total ) }
													<br />
													<span className="wpitravel-dashboard__muted">
														{ booking.status_label }
													</span>
												</span>
											</li>
										) ) }
									</ul>
								</CardBody>
							</Card>
						) }
					</div>
					{ config.proUrl && <ProNote url={ config.proUrl } /> }
				</>
			) }
		</div>
	);
}

/**
 * A short note about Pro, at the end of the dashboard. Not shown once Pro
 * is active.
 *
 * @param {Object} props
 * @param {string} props.url Link to the Pro page.
 */
function ProNote( { url } ) {
	return (
		<Card className="wpitravel-dashboard__pro">
			<CardBody>
				<strong>
					{ __( 'WPInterface Travel Pro', 'wpinterface-travel' ) }
				</strong>{ ' ' }
				{ __(
					'adds card payments with Stripe and other gateways, deposits and installments, tour extras, group discounts and branded invoices.',
					'wpinterface-travel'
				) }{ ' ' }
				<a href={ url } target="_blank" rel="noopener noreferrer">
					{ __( 'Learn more', 'wpinterface-travel' ) }
					<span className="screen-reader-text">
						{ __( '(opens in a new tab)', 'wpinterface-travel' ) }
					</span>
				</a>
			</CardBody>
		</Card>
	);
}

function Stat( { label, value, help } ) {
	return (
		<div className="wpitravel-dashboard__stat">
			<span className="wpitravel-dashboard__stat-label">{ label }</span>
			<span className="wpitravel-dashboard__stat-value">{ value }</span>
			{ help && (
				<span className="wpitravel-dashboard__muted">{ help }</span>
			) }
		</div>
	);
}
