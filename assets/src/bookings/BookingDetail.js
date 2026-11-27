/**
 * One booking: summary, customer, travelers, payments, emails and history.
 * Staff with manage rights can change the status, record payments and
 * refunds, resend emails and add notes.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { TextField, TextareaField } from '../components/fields';
import StatusBadge from './StatusBadge';
import { BASE, formatDate, formatDateTime, money, screenUrl } from './utils';

const PAYMENT_STATUSES = () => ( {
	pending: __( 'Pending', 'wpinterface-travel' ),
	completed: __( 'Completed', 'wpinterface-travel' ),
	failed: __( 'Failed', 'wpinterface-travel' ),
	cancelled: __( 'Cancelled', 'wpinterface-travel' ),
} );

export default function BookingDetail( { id, config, navigate } ) {
	const [ booking, setBooking ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ statusNote, setStatusNote ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ payment, setPayment ] = useState( null );
	const [ refund, setRefund ] = useState( null );

	const load = useCallback( () => {
		apiFetch( { path: `${ BASE }/bookings/${ id }` } )
			.then( ( data ) => {
				setBooking( data );
				setStatus( data.status );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);
	}, [ id ] );

	useEffect( load, [ load ] );

	const run = async ( request, success ) => {
		setBusy( true );
		setNotice( null );
		try {
			const data = await request();
			if ( data && data.id === id && data.history ) {
				setBooking( data );
				setStatus( data.status );
			} else {
				load();
			}
			setNotice( { status: 'success', text: success } );
			return true;
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
			return false;
		} finally {
			setBusy( false );
		}
	};

	if ( ! booking ) {
		return (
			<div className="wpitravel-bookings">
				<BackLink navigate={ navigate } />
				{ notice ? (
					<Notice status="error" isDismissible={ false }>
						{ notice.text }
					</Notice>
				) : (
					<Spinner />
				) }
			</div>
		);
	}

	const fmt = ( amount ) => money( config, amount, booking.currency );
	const completedPayments = booking.payments.filter(
		( row ) => row.status === 'completed' && row.payment_type === 'payment'
	);

	const saveStatus = () =>
		run(
			() =>
				apiFetch( {
					path: `${ BASE }/bookings/${ id }`,
					method: 'PATCH',
					data: { status, note: statusNote },
				} ),
			__( 'Status updated.', 'wpinterface-travel' )
		).then( ( ok ) => ok && setStatusNote( '' ) );

	const addNote = () =>
		run(
			() =>
				apiFetch( {
					path: `${ BASE }/bookings/${ id }/notes`,
					method: 'POST',
					data: { note },
				} ),
			__( 'Note added.', 'wpinterface-travel' )
		).then( ( ok ) => ok && setNote( '' ) );

	const sendEmail = () =>
		run(
			() =>
				apiFetch( {
					path: `${ BASE }/bookings/${ id }/emails`,
					method: 'POST',
					data: { template: email },
				} ),
			__( 'Email sent.', 'wpinterface-travel' )
		);

	const recordPayment = () =>
		run(
			() =>
				apiFetch( {
					path: `${ BASE }/bookings/${ id }/payments`,
					method: 'POST',
					data: payment,
				} ),
			__( 'Payment recorded.', 'wpinterface-travel' )
		).then( ( ok ) => ok && setPayment( null ) );

	const sendRefund = () =>
		run(
			() =>
				apiFetch( {
					path: `${ BASE }/bookings/${ id }/refunds`,
					method: 'POST',
					data: refund,
				} ),
			__( 'Refund recorded.', 'wpinterface-travel' )
		).then( ( ok ) => ok && setRefund( null ) );

	return (
		<div className="wpitravel-bookings wpitravel-booking-detail">
			<BackLink navigate={ navigate } />
			<div className="wpitravel-bookings__head">
				<h1 className="wp-heading-inline">
					{ sprintf(
						/* translators: %s: booking number. */
						__( 'Booking %s', 'wpinterface-travel' ),
						booking.number
					) }
				</h1>
				<StatusBadge
					status={ booking.status }
					label={ booking.status_label }
				/>
				<StatusBadge
					status={ booking.payment_status }
					label={ booking.payment_status_label }
				/>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<div className="wpitravel-booking-detail__grid">
				<div className="wpitravel-booking-detail__main">
					<Card>
						<CardHeader>
							<h2>{ __( 'Trip', 'wpinterface-travel' ) }</h2>
						</CardHeader>
						<CardBody>
							<dl className="wpitravel-dl">
								<dt>{ __( 'Trip', 'wpinterface-travel' ) }</dt>
								<dd>
									{ booking.trip.edit_url ? (
										<a href={ booking.trip.edit_url }>
											{ booking.trip.title }
										</a>
									) : (
										booking.trip.title
									) }
								</dd>
								<dt>
									{ __( 'Departure', 'wpinterface-travel' ) }
								</dt>
								<dd>
									{ formatDate( config, booking.date ) }
									{ booking.time && ` ${ booking.time }` }
									{ booking.manifest_url && (
										<>
											{ ' · ' }
											<a href={ booking.manifest_url }>
												{ __(
													'Traveler list',
													'wpinterface-travel'
												) }
											</a>
										</>
									) }
								</dd>
								{ booking.pickup && (
									<>
										<dt>
											{ __(
												'Pickup',
												'wpinterface-travel'
											) }
										</dt>
										<dd>
											{ booking.pickup.name }
											{ booking.pickup.time &&
												`, ${ booking.pickup.time }` }
										</dd>
									</>
								) }
								<dt>
									{ __( 'Travelers', 'wpinterface-travel' ) }
								</dt>
								<dd>{ booking.pax }</dd>
								{ booking.coupon_code && (
									<>
										<dt>
											{ __(
												'Coupon',
												'wpinterface-travel'
											) }
										</dt>
										<dd>
											<code>{ booking.coupon_code }</code>
										</dd>
									</>
								) }
							</dl>
							<table className="widefat wpitravel-booking-detail__items">
								<tbody>
									{ booking.items.map( ( item ) => (
										<tr key={ item.id }>
											<td>
												{ item.quantity > 1
													? `${ item.label } × ${ item.quantity }`
													: item.label }
											</td>
											<td className="num">
												{ fmt( item.total ) }
											</td>
										</tr>
									) ) }
									<tr className="is-total">
										<th>
											{ __(
												'Total',
												'wpinterface-travel'
											) }
										</th>
										<td className="num">
											<strong>
												{ fmt( booking.total ) }
											</strong>
										</td>
									</tr>
									<tr>
										<td>
											{ __(
												'Paid',
												'wpinterface-travel'
											) }
										</td>
										<td className="num">
											{ fmt( booking.amount_paid ) }
										</td>
									</tr>
									<tr>
										<td>
											{ __(
												'Balance',
												'wpinterface-travel'
											) }
										</td>
										<td className="num">
											<strong>
												{ fmt( booking.balance ) }
											</strong>
										</td>
									</tr>
								</tbody>
							</table>
							{ booking.customer_note && (
								<>
									<h3>
										{ __(
											'Note from the customer',
											'wpinterface-travel'
										) }
									</h3>
									<p className="wpitravel-prewrap">
										{ booking.customer_note }
									</p>
								</>
							) }
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<h2>{ __( 'Payments', 'wpinterface-travel' ) }</h2>
							{ config.canManage && (
								<Button
									variant="secondary"
									size="compact"
									onClick={ () =>
										setPayment( {
											amount: booking.balance,
											method: 'manual',
											reference: '',
											note: '',
										} )
									}
								>
									{ __(
										'Record payment',
										'wpinterface-travel'
									) }
								</Button>
							) }
						</CardHeader>
						<CardBody>
							{ booking.payment_label && (
								<p>
									{ sprintf(
										/* translators: %s: payment method. */
										__(
											'Customer chose: %s',
											'wpinterface-travel'
										),
										booking.payment_label
									) }
								</p>
							) }
							{ booking.payments.length === 0 ? (
								<p>
									{ __(
										'No payments yet.',
										'wpinterface-travel'
									) }
								</p>
							) : (
								<table className="widefat striped">
									<thead>
										<tr>
											<th>
												{ __(
													'Date',
													'wpinterface-travel'
												) }
											</th>
											<th>
												{ __(
													'Method',
													'wpinterface-travel'
												) }
											</th>
											<th>
												{ __(
													'Status',
													'wpinterface-travel'
												) }
											</th>
											<th className="num">
												{ __(
													'Amount',
													'wpinterface-travel'
												) }
											</th>
											<th>
												<span className="screen-reader-text">
													{ __(
														'Actions',
														'wpinterface-travel'
													) }
												</span>
											</th>
										</tr>
									</thead>
									<tbody>
										{ booking.payments.map( ( row ) => (
											<tr key={ row.id }>
												<td>
													{ formatDateTime(
														config,
														row.created
													) }
												</td>
												<td>
													{ row.method_label }
													{ row.transaction_id && (
														<div className="wpitravel-muted">
															{
																row.transaction_id
															}
														</div>
													) }
												</td>
												<td>
													{ PAYMENT_STATUSES()[
														row.status
													] || row.status }
												</td>
												<td className="num">
													{ row.payment_type ===
													'refund'
														? `−${ fmt(
																row.amount
														  ) }`
														: fmt( row.amount ) }
												</td>
												<td>
													{ config.canManage &&
														row.status ===
															'completed' &&
														row.payment_type ===
															'payment' && (
															<Button
																variant="link"
																onClick={ () =>
																	setRefund( {
																		payment_id:
																			row.id,
																		amount: row.amount,
																		reason: '',
																		manual: ! booking.gateways.some(
																			(
																				gateway
																			) =>
																				gateway.id ===
																					row.gateway &&
																				gateway.online
																		),
																	} )
																}
															>
																{ __(
																	'Refund',
																	'wpinterface-travel'
																) }
															</Button>
														) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
							{ completedPayments.length === 0 &&
								booking.payments.length > 0 && (
									<p className="wpitravel-muted">
										{ __(
											'No completed payments.',
											'wpinterface-travel'
										) }
									</p>
								) }
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<h2>{ __( 'History', 'wpinterface-travel' ) }</h2>
						</CardHeader>
						<CardBody>
							{ config.canManage && (
								<div className="wpitravel-booking-detail__note">
									<TextareaField
										label={ __(
											'Add a note (staff only)',
											'wpinterface-travel'
										) }
										rows={ 2 }
										value={ note }
										onChange={ setNote }
									/>
									<Button
										variant="secondary"
										disabled={ busy || ! note.trim() }
										onClick={ addNote }
									>
										{ __(
											'Add note',
											'wpinterface-travel'
										) }
									</Button>
								</div>
							) }
							<ol className="wpitravel-history">
								{ booking.history.map( ( row ) => (
									<li
										key={ row.id }
										className={ `is-${ row.event }` }
									>
										<span className="wpitravel-history__message">
											{ row.message || row.event }
										</span>
										<span className="wpitravel-muted">
											{ formatDateTime(
												config,
												row.created
											) }
											{ row.actor && ` · ${ row.actor }` }
										</span>
									</li>
								) ) }
							</ol>
						</CardBody>
					</Card>
				</div>

				<div className="wpitravel-booking-detail__side">
					{
						/**
						 * Lets Pro add cards (e.g. payment plan, invoice) to a
						 * booking. Return an array of elements.
						 */
						applyFilters( 'wpitravel.bookingDetail.side', [], {
							booking,
							config,
							reload: load,
						} ).map( ( element, index ) => (
							<div key={ index }>{ element }</div>
						) )
					}
					{ config.canManage && (
						<Card>
							<CardHeader>
								<h2>
									{ __( 'Status', 'wpinterface-travel' ) }
								</h2>
							</CardHeader>
							<CardBody>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Booking status',
										'wpinterface-travel'
									) }
									value={ status }
									options={ Object.entries(
										booking.statuses
									).map( ( [ value, label ] ) => ( {
										value,
										label,
									} ) ) }
									onChange={ setStatus }
								/>
								{ status !== booking.status && (
									<>
										<TextField
											label={ __(
												'Reason (optional, staff only)',
												'wpinterface-travel'
											) }
											value={ statusNote }
											onChange={ setStatusNote }
										/>
										{ [ 'confirmed', 'cancelled' ].includes(
											status
										) && (
											<p className="wpitravel-muted">
												{ __(
													'The customer is emailed about this change.',
													'wpinterface-travel'
												) }
											</p>
										) }
										<Button
											variant="primary"
											disabled={ busy }
											isBusy={ busy }
											onClick={ saveStatus }
										>
											{ __(
												'Update status',
												'wpinterface-travel'
											) }
										</Button>
									</>
								) }
							</CardBody>
						</Card>
					) }

					<Card>
						<CardHeader>
							<h2>{ __( 'Customer', 'wpinterface-travel' ) }</h2>
						</CardHeader>
						<CardBody>
							<p>
								<strong>{ booking.customer.name }</strong>
								<br />
								<a
									href={ `mailto:${ booking.customer.email }` }
								>
									{ booking.customer.email }
								</a>
								{ booking.customer.phone && (
									<>
										<br />
										<a
											href={ `tel:${ booking.customer.phone }` }
										>
											{ booking.customer.phone }
										</a>
									</>
								) }
								{ booking.customer.country && (
									<>
										<br />
										{ booking.customer.country }
									</>
								) }
							</p>
							<p>
								<a
									href={ booking.manage_url }
									target="_blank"
									rel="noreferrer"
								>
									{ __(
										'View as the customer',
										'wpinterface-travel'
									) }
								</a>
								{ ' · ' }
								<a
									href={ booking.voucher_url }
									target="_blank"
									rel="noreferrer"
								>
									{ __( 'Voucher', 'wpinterface-travel' ) }
								</a>
							</p>
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<h2>{ __( 'Travelers', 'wpinterface-travel' ) }</h2>
						</CardHeader>
						<CardBody>
							<ul className="wpitravel-travelers">
								{ booking.travelers.map(
									( traveler, index ) => (
										<li key={ index }>
											<strong>
												{ `${ traveler.first_name } ${ traveler.last_name }` }
											</strong>
											{ traveler.lead && (
												<span className="wpitravel-muted">
													{ ' ' }
													{ __(
														'(lead)',
														'wpinterface-travel'
													) }
												</span>
											) }
											<div className="wpitravel-muted">
												{ [
													traveler.category,
													traveler.email,
													traveler.phone,
												]
													.filter( Boolean )
													.join( ' · ' ) }
											</div>
										</li>
									)
								) }
							</ul>
						</CardBody>
					</Card>

					{ config.canManage && (
						<Card>
							<CardHeader>
								<h2>
									{ __( 'Emails', 'wpinterface-travel' ) }
								</h2>
							</CardHeader>
							<CardBody>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Send again',
										'wpinterface-travel'
									) }
									value={ email }
									options={ [
										{
											value: '',
											label: __(
												'Choose an email',
												'wpinterface-travel'
											),
										},
										...booking.emails.map( ( row ) => ( {
											value: row.id,
											label:
												row.recipient === 'admin'
													? sprintf(
															/* translators: %s: email name. */
															__(
																'%s (to staff)',
																'wpinterface-travel'
															),
															row.title
													  )
													: row.title,
										} ) ),
									] }
									onChange={ setEmail }
								/>
								<Button
									variant="secondary"
									disabled={ busy || ! email }
									onClick={ sendEmail }
								>
									{ __( 'Send', 'wpinterface-travel' ) }
								</Button>
							</CardBody>
						</Card>
					) }
				</div>
			</div>

			{ payment && (
				<Modal
					title={ __( 'Record a payment', 'wpinterface-travel' ) }
					onRequestClose={ () => setPayment( null ) }
				>
					<div className="wpitravel-modal-form">
						<TextField
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Amount (%s)', 'wpinterface-travel' ),
								booking.currency
							) }
							inputMode="decimal"
							value={ payment.amount }
							onChange={ ( amount ) =>
								setPayment( { ...payment, amount } )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Received by', 'wpinterface-travel' ) }
							value={ payment.method }
							options={ [
								{
									value: 'manual',
									label: __(
										'Other (cash, card terminal…)',
										'wpinterface-travel'
									),
								},
								...booking.gateways.map( ( gateway ) => ( {
									value: gateway.id,
									label: gateway.title,
								} ) ),
							] }
							onChange={ ( method ) =>
								setPayment( { ...payment, method } )
							}
						/>
						<TextField
							label={ __( 'Reference', 'wpinterface-travel' ) }
							help={ __(
								'Receipt or transfer reference, if any.',
								'wpinterface-travel'
							) }
							value={ payment.reference }
							onChange={ ( reference ) =>
								setPayment( { ...payment, reference } )
							}
						/>
						<TextField
							label={ __( 'Note', 'wpinterface-travel' ) }
							value={ payment.note }
							onChange={ ( value ) =>
								setPayment( { ...payment, note: value } )
							}
						/>
						<Button
							variant="primary"
							disabled={ busy }
							isBusy={ busy }
							onClick={ recordPayment }
						>
							{ __( 'Record payment', 'wpinterface-travel' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ refund && (
				<Modal
					title={ __( 'Refund a payment', 'wpinterface-travel' ) }
					onRequestClose={ () => setRefund( null ) }
				>
					<div className="wpitravel-modal-form">
						<TextField
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Amount (%s)', 'wpinterface-travel' ),
								booking.currency
							) }
							inputMode="decimal"
							value={ refund.amount }
							onChange={ ( amount ) =>
								setRefund( { ...refund, amount } )
							}
						/>
						<TextField
							label={ __( 'Reason', 'wpinterface-travel' ) }
							value={ refund.reason }
							onChange={ ( reason ) =>
								setRefund( { ...refund, reason } )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'How', 'wpinterface-travel' ) }
							value={ refund.manual ? 'manual' : 'gateway' }
							options={ [
								{
									value: 'gateway',
									label: __(
										'Refund through the payment provider',
										'wpinterface-travel'
									),
								},
								{
									value: 'manual',
									label: __(
										'Only record it (money returned another way)',
										'wpinterface-travel'
									),
								},
							] }
							onChange={ ( value ) =>
								setRefund( {
									...refund,
									manual: value === 'manual',
								} )
							}
						/>
						<Button
							variant="primary"
							isDestructive
							disabled={ busy }
							isBusy={ busy }
							onClick={ sendRefund }
						>
							{ __( 'Refund', 'wpinterface-travel' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

function BackLink( { navigate } ) {
	return (
		<p className="wpitravel-back">
			<a
				href={ screenUrl() }
				onClick={ ( event ) => {
					event.preventDefault();
					navigate( {} );
				} }
			>
				{ __( '← All bookings', 'wpinterface-travel' ) }
			</a>
		</p>
	);
}
