/**
 * Setup wizard: currency and tax, bookings, payments, trips, done.
 * Each step saves through the settings endpoint, so the wizard and
 * Travel → Settings never disagree.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import {
	DecimalField,
	IntegerField,
	TextField,
	TextareaField,
} from '../components/fields';
import ContentStep from './ContentStep';

const BASE = '/wpinterface-travel/v1';

const STEPS = () => [
	{ key: 'business', label: __( 'Currency', 'wpinterface-travel' ) },
	{ key: 'booking', label: __( 'Bookings', 'wpinterface-travel' ) },
	{ key: 'payments', label: __( 'Payments', 'wpinterface-travel' ) },
	{ key: 'content', label: __( 'Trips', 'wpinterface-travel' ) },
	{ key: 'done', label: __( 'Ready', 'wpinterface-travel' ) },
];

export default function SetupWizard( { config } ) {
	const steps = STEPS();
	const initial = Math.max(
		0,
		steps.findIndex(
			( step ) =>
				step.key ===
				( config.step === 'import' ? 'content' : config.step )
		)
	);
	const [ index, setIndex ] = useState( initial );
	const [ data, setData ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: `${ BASE }/settings` } ),
			apiFetch( { path: `${ BASE }/setup` } ),
		] )
			.then( ( [ settings, setup ] ) => {
				setData( settings );
				setStatus( setup );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( ! data || ! status ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const step = steps[ index ];
	const settings = data.settings;

	// Current value: unsaved edit, else saved setting.
	const value = ( section, key ) =>
		draft[ section ]?.[ key ] ?? settings[ section ][ key ];
	const set = ( section, key ) => ( next ) =>
		setDraft( ( current ) => ( {
			...current,
			[ section ]: { ...( current[ section ] || {} ), [ key ]: next },
		} ) );
	const gateway = ( id, key ) =>
		draft.payments?.gateways?.[ id ]?.[ key ] ??
		settings.payments.gateways[ id ]?.[ key ];
	const setGateway = ( id, key ) => ( next ) =>
		setDraft( ( current ) => ( {
			...current,
			payments: {
				...( current.payments || {} ),
				gateways: {
					...( current.payments?.gateways || {} ),
					[ id ]: {
						...( current.payments?.gateways?.[ id ] || {} ),
						[ key ]: next,
					},
				},
			},
		} ) );

	const finish = async () => {
		const result = await apiFetch( {
			path: `${ BASE }/setup/complete`,
			method: 'POST',
		} );
		setStatus( result );
	};

	const next = async () => {
		setBusy( true );
		setError( null );
		try {
			if ( Object.keys( draft ).length ) {
				const saved = await apiFetch( {
					path: `${ BASE }/settings`,
					method: 'POST',
					data: draft,
				} );
				setData( saved );
				setDraft( {} );
			}
			if ( steps[ index + 1 ]?.key === 'done' ) {
				await finish();
			}
			setIndex( index + 1 );
			window.scrollTo( 0, 0 );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	const skip = async () => {
		await finish();
		window.location.href = config.urls.trips;
	};

	const hasPayPal = !! settings.payments.gateways.paypal;

	return (
		<div className="wpitravel-setup">
			<header className="wpitravel-setup__header">
				<h1>
					{ __( 'Set up WPInterface Travel', 'wpinterface-travel' ) }
				</h1>
				<ol className="wpitravel-setup__steps">
					{ steps.map( ( item, position ) => (
						<li
							key={ item.key }
							className={
								( position === index ? 'is-current ' : '' ) +
								( position < index ? 'is-done' : '' )
							}
							aria-current={
								position === index ? 'step' : undefined
							}
						>
							{ item.label }
						</li>
					) ) }
				</ol>
			</header>

			<div className="wpitravel-setup__card">
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				{ step.key === 'business' && (
					<>
						<h2>
							{ __( 'Currency and tax', 'wpinterface-travel' ) }
						</h2>
						<p>
							{ __(
								'Prices are shown and charged in this currency.',
								'wpinterface-travel'
							) }
						</p>
						<ComboboxControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Currency', 'wpinterface-travel' ) }
							value={ value( 'currency', 'code' ) }
							options={ config.currencies }
							onChange={ ( code ) =>
								code && set( 'currency', 'code' )( code )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Symbol position',
								'wpinterface-travel'
							) }
							value={ value( 'currency', 'position' ) }
							options={ data.positions }
							onChange={ set( 'currency', 'position' ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Charge tax (VAT, GST, sales tax)',
								'wpinterface-travel'
							) }
							checked={ !! value( 'tax', 'enabled' ) }
							onChange={ set( 'tax', 'enabled' ) }
						/>
						{ value( 'tax', 'enabled' ) && (
							<div className="wpitravel-setup__row">
								<DecimalField
									label={ __(
										'Tax rate (%)',
										'wpinterface-travel'
									) }
									min={ 0 }
									max={ 100 }
									value={ value( 'tax', 'rate' ) }
									onChange={ set( 'tax', 'rate' ) }
								/>
								<TextField
									label={ __(
										'Tax name',
										'wpinterface-travel'
									) }
									value={ value( 'tax', 'label' ) }
									onChange={ set( 'tax', 'label' ) }
								/>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Trip prices',
										'wpinterface-travel'
									) }
									value={
										value( 'tax', 'prices_include_tax' )
											? 'yes'
											: 'no'
									}
									options={ [
										{
											value: 'yes',
											label: __(
												'include tax',
												'wpinterface-travel'
											),
										},
										{
											value: 'no',
											label: __(
												'exclude tax (added at checkout)',
												'wpinterface-travel'
											),
										},
									] }
									onChange={ ( choice ) =>
										set(
											'tax',
											'prices_include_tax'
										)( choice === 'yes' )
									}
								/>
							</div>
						) }
					</>
				) }

				{ step.key === 'booking' && (
					<>
						<h2>{ __( 'Bookings', 'wpinterface-travel' ) }</h2>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Let customers book without an account',
								'wpinterface-travel'
							) }
							checked={ !! value( 'booking', 'guest_checkout' ) }
							onChange={ set( 'booking', 'guest_checkout' ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Offer to create an account when booking',
								'wpinterface-travel'
							) }
							checked={ !! value( 'booking', 'create_accounts' ) }
							onChange={ set( 'booking', 'create_accounts' ) }
						/>
						<div className="wpitravel-setup__row">
							<IntegerField
								label={ __(
									'Hold seats during checkout (minutes)',
									'wpinterface-travel'
								) }
								min={ 5 }
								max={ 120 }
								value={ value( 'booking', 'hold_minutes' ) }
								onChange={ set( 'booking', 'hold_minutes' ) }
							/>
							<TextField
								label={ __(
									'Booking number prefix',
									'wpinterface-travel'
								) }
								value={ value( 'booking', 'number_prefix' ) }
								onChange={ set( 'booking', 'number_prefix' ) }
							/>
						</div>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Terms and conditions page',
								'wpinterface-travel'
							) }
							help={ __(
								'Customers must accept it to book. Leave empty to skip.',
								'wpinterface-travel'
							) }
							value={ String(
								value( 'booking', 'terms_page_id' )
							) }
							options={ [
								{ value: '0', label: '—' },
								...data.pages,
							] }
							onChange={ ( page ) =>
								set(
									'booking',
									'terms_page_id'
								)( parseInt( page, 10 ) )
							}
						/>
					</>
				) }

				{ step.key === 'payments' && (
					<>
						<h2>{ __( 'Payments', 'wpinterface-travel' ) }</h2>
						<p>
							{ __(
								'Choose how customers pay. You can change this any time in Travel → Settings → Payments.',
								'wpinterface-travel'
							) }
						</p>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Bank transfer',
								'wpinterface-travel'
							) }
							checked={ !! gateway( 'bank_transfer', 'enabled' ) }
							onChange={ setGateway(
								'bank_transfer',
								'enabled'
							) }
						/>
						{ gateway( 'bank_transfer', 'enabled' ) && (
							<TextareaField
								label={ __(
									'Bank details',
									'wpinterface-travel'
								) }
								help={ __(
									'Account holder, IBAN or account number, bank and SWIFT/BIC. Shown to customers after booking.',
									'wpinterface-travel'
								) }
								value={ gateway(
									'bank_transfer',
									'instructions'
								) }
								onChange={ setGateway(
									'bank_transfer',
									'instructions'
								) }
							/>
						) }
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Pay later (on arrival or by arrangement)',
								'wpinterface-travel'
							) }
							checked={ !! gateway( 'pay_later', 'enabled' ) }
							onChange={ setGateway( 'pay_later', 'enabled' ) }
						/>
						{ hasPayPal && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'To take card and PayPal payments online, add your PayPal app keys in Travel → Settings → Payments after setup.',
									'wpinterface-travel'
								) }
							</Notice>
						) }
					</>
				) }

				{ step.key === 'content' && (
					<ContentStep status={ status } setStatus={ setStatus } />
				) }

				{ step.key === 'done' && (
					<>
						<h2>
							{ __(
								'You’re ready to take bookings',
								'wpinterface-travel'
							) }
						</h2>
						<ul className="wpitravel-setup__next">
							<li>
								<a href={ config.urls.newTrip }>
									{ __( 'Add a trip', 'wpinterface-travel' ) }
								</a>{ ' ' }
								{ __(
									'with its itinerary, prices and departure dates.',
									'wpinterface-travel'
								) }
							</li>
							<li>
								<a href={ config.urls.site }>
									{ __(
										'View your trips page',
										'wpinterface-travel'
									) }
								</a>
							</li>
							<li>
								<a href={ config.urls.settings }>
									{ __(
										'Review all settings',
										'wpinterface-travel'
									) }
								</a>{ ' ' }
								{ __(
									'(emails, reviews, permalinks).',
									'wpinterface-travel'
								) }
							</li>
						</ul>
						{ status.samples.length > 0 && (
							<p className="wpitravel-setup__muted">
								{ sprintf(
									/* translators: %d: number of sample trips. */
									__(
										'%d sample trips are published. Remove them from this wizard (Travel → Settings → Setup wizard) when you are ready.',
										'wpinterface-travel'
									),
									status.samples.length
								) }
							</p>
						) }
					</>
				) }

				<footer className="wpitravel-setup__footer">
					{ index > 0 && step.key !== 'done' && (
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () => setIndex( index - 1 ) }
						>
							{ __( 'Back', 'wpinterface-travel' ) }
						</Button>
					) }
					<span className="wpitravel-setup__spacer" />
					{ step.key !== 'done' ? (
						<>
							<Button variant="link" onClick={ skip }>
								{ __( 'Skip setup', 'wpinterface-travel' ) }
							</Button>
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ next }
							>
								{ __( 'Continue', 'wpinterface-travel' ) }
							</Button>
						</>
					) : (
						<Button variant="primary" href={ config.urls.newTrip }>
							{ __(
								'Add your first trip',
								'wpinterface-travel'
							) }
						</Button>
					) }
				</footer>
			</div>
		</div>
	);
}
