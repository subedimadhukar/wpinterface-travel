/**
 * Coupon list with add, edit and delete.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	FormTokenField,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { IntegerField, TextField } from '../components/fields';

const PATH = '/wpinterface-travel/v1/coupons';

const EMPTY = {
	code: '',
	description: '',
	discount_type: 'percent',
	amount: '',
	min_subtotal: '',
	usage_limit: 0,
	per_customer_limit: 0,
	trip_ids: [],
	starts_at: '',
	expires_at: '',
	status: 'active',
};

function CouponForm( { coupon, config, onSaved, onClose } ) {
	const [ values, setValues ] = useState( {
		...EMPTY,
		...coupon,
		usage_limit: coupon?.usage_limit || 0,
		per_customer_limit: coupon?.per_customer_limit || 0,
	} );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const set = ( key, value ) => setValues( { ...values, [ key ]: value } );

	const tripLabel = ( id ) =>
		config.trips.find( ( trip ) => trip.value === id )?.label;

	const save = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( '' );
		try {
			const saved = await apiFetch( {
				path: coupon?.id ? `${ PATH }/${ coupon.id }` : PATH,
				method: coupon?.id ? 'PATCH' : 'POST',
				data: values,
			} );
			onSaved( saved );
		} catch ( e ) {
			setError( e.message );
			setSaving( false );
		}
	};

	return (
		<Modal
			title={
				coupon?.id
					? __( 'Edit coupon', 'wpinterface-travel' )
					: __( 'Add coupon', 'wpinterface-travel' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<form className="wpitravel-coupon-form" onSubmit={ save }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<div className="wpitravel-grid">
					<TextField
						label={ __( 'Code', 'wpinterface-travel' ) }
						help={ __(
							'Letters, digits, - and _. Not case-sensitive.',
							'wpinterface-travel'
						) }
						value={ values.code }
						onChange={ ( value ) =>
							set( 'code', value.toUpperCase() )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Status', 'wpinterface-travel' ) }
						value={ values.status }
						options={ [
							{
								value: 'active',
								label: __( 'Active', 'wpinterface-travel' ),
							},
							{
								value: 'inactive',
								label: __( 'Inactive', 'wpinterface-travel' ),
							},
						] }
						onChange={ ( value ) => set( 'status', value ) }
					/>
				</div>
				<TextField
					label={ __(
						'Description (internal)',
						'wpinterface-travel'
					) }
					value={ values.description }
					onChange={ ( value ) => set( 'description', value ) }
				/>
				<div className="wpitravel-grid">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Discount type', 'wpinterface-travel' ) }
						value={ values.discount_type }
						options={ [
							{
								value: 'percent',
								label: __( 'Percentage', 'wpinterface-travel' ),
							},
							{
								value: 'fixed',
								label: sprintf(
									/* translators: %s: currency code. */
									__(
										'Fixed amount (%s)',
										'wpinterface-travel'
									),
									config.currency
								),
							},
						] }
						onChange={ ( value ) => set( 'discount_type', value ) }
					/>
					<TextField
						label={
							values.discount_type === 'percent'
								? __( 'Discount (%)', 'wpinterface-travel' )
								: sprintf(
										/* translators: %s: currency code. */
										__(
											'Discount (%s)',
											'wpinterface-travel'
										),
										config.currency
								  )
						}
						inputMode="decimal"
						value={ String( values.amount ) }
						onChange={ ( value ) => set( 'amount', value ) }
					/>
					<TextField
						label={ sprintf(
							/* translators: %s: currency code. */
							__( 'Minimum booking (%s)', 'wpinterface-travel' ),
							config.currency
						) }
						inputMode="decimal"
						placeholder="0"
						value={ String( values.min_subtotal ) }
						onChange={ ( value ) => set( 'min_subtotal', value ) }
					/>
				</div>
				<div className="wpitravel-grid">
					<TextField
						type="datetime-local"
						label={ __( 'Valid from', 'wpinterface-travel' ) }
						value={ values.starts_at.replace( ' ', 'T' ) }
						onChange={ ( value ) =>
							set( 'starts_at', value.replace( 'T', ' ' ) )
						}
					/>
					<TextField
						type="datetime-local"
						label={ __( 'Valid until', 'wpinterface-travel' ) }
						value={ values.expires_at.replace( ' ', 'T' ) }
						onChange={ ( value ) =>
							set( 'expires_at', value.replace( 'T', ' ' ) )
						}
					/>
				</div>
				<div className="wpitravel-grid">
					<IntegerField
						label={ __( 'Total uses', 'wpinterface-travel' ) }
						help={ __( '0 = unlimited.', 'wpinterface-travel' ) }
						value={ values.usage_limit }
						onChange={ ( value ) => set( 'usage_limit', value ) }
					/>
					<IntegerField
						label={ __(
							'Uses per customer',
							'wpinterface-travel'
						) }
						help={ __(
							'0 = unlimited. Counted by email address.',
							'wpinterface-travel'
						) }
						value={ values.per_customer_limit }
						onChange={ ( value ) =>
							set( 'per_customer_limit', value )
						}
					/>
				</div>
				<FormTokenField
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Only for these trips (empty = all trips)',
						'wpinterface-travel'
					) }
					value={ values.trip_ids.map( tripLabel ).filter( Boolean ) }
					suggestions={ config.trips.map( ( trip ) => trip.label ) }
					onChange={ ( labels ) =>
						set(
							'trip_ids',
							labels
								.map(
									( label ) =>
										config.trips.find(
											( trip ) => trip.label === label
										)?.value
								)
								.filter( Boolean )
						)
					}
				/>
				<div className="wpitravel-coupon-form__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'wpinterface-travel' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save coupon', 'wpinterface-travel' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function CouponsApp( { config } ) {
	const [ coupons, setCoupons ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		apiFetch( { path: `${ PATH }?per_page=100` } )
			.then( setCoupons )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	useEffect( load, [ load ] );

	const [ deleting, setDeleting ] = useState( null );

	const remove = async ( coupon ) => {
		setDeleting( null );
		try {
			await apiFetch( {
				path: `${ PATH }/${ coupon.id }`,
				method: 'DELETE',
			} );
			load();
		} catch ( e ) {
			setNotice( { status: 'error', message: e.message } );
		}
	};

	const discount = ( coupon ) =>
		coupon.discount_type === 'percent'
			? `${ coupon.amount }%`
			: `${ coupon.amount } ${ coupon.currency }`;

	return (
		<div className="wpitravel-coupons">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<p>
				<Button variant="primary" onClick={ () => setEditing( {} ) }>
					{ __( 'Add coupon', 'wpinterface-travel' ) }
				</Button>
			</p>
			{ coupons === null && <Spinner /> }
			{ coupons && coupons.length === 0 && (
				<p>{ __( 'No coupons yet.', 'wpinterface-travel' ) }</p>
			) }
			{ coupons && coupons.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Code', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Discount', 'wpinterface-travel' ) }</th>
							<th>{ __( 'Used', 'wpinterface-travel' ) }</th>
							<th>
								{ __( 'Valid until', 'wpinterface-travel' ) }
							</th>
							<th>{ __( 'Status', 'wpinterface-travel' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'wpinterface-travel' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ coupons.map( ( coupon ) => (
							<tr key={ coupon.id }>
								<td>
									<strong>{ coupon.code }</strong>
									{ coupon.description && (
										<div>{ coupon.description }</div>
									) }
								</td>
								<td>{ discount( coupon ) }</td>
								<td>
									{ coupon.usage_limit
										? `${ coupon.usage_count } / ${ coupon.usage_limit }`
										: coupon.usage_count }
								</td>
								<td>{ coupon.expires_at || '—' }</td>
								<td>
									{ coupon.status === 'active'
										? __( 'Active', 'wpinterface-travel' )
										: __(
												'Inactive',
												'wpinterface-travel'
										  ) }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( coupon ) }
									>
										{ __( 'Edit', 'wpinterface-travel' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () => setDeleting( coupon ) }
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
					title={ __( 'Delete coupon', 'wpinterface-travel' ) }
					onRequestClose={ () => setDeleting( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: coupon code. */
							__(
								'Delete coupon %s? Bookings that used it keep their discount.',
								'wpinterface-travel'
							),
							deleting.code
						) }
					</p>
					<div className="wpitravel-coupon-form__actions">
						<Button
							variant="tertiary"
							onClick={ () => setDeleting( null ) }
						>
							{ __( 'Cancel', 'wpinterface-travel' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => remove( deleting ) }
						>
							{ __( 'Delete', 'wpinterface-travel' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ editing && (
				<CouponForm
					coupon={ editing }
					config={ config }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						setNotice( {
							status: 'success',
							message: __(
								'Coupon saved.',
								'wpinterface-travel'
							),
						} );
						load();
					} }
				/>
			) }
		</div>
	);
}
