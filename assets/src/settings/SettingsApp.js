/**
 * Settings form. Saves the whole settings object through POST /settings;
 * the server validates and returns the saved values.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ComboboxControl,
	Notice,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { DecimalField, IntegerField, TextField } from '../components/fields';
import formatPrice from '../components/formatPrice';
import PaymentGateway from './PaymentGateway';

const THOUSAND_SEPARATORS = [
	{ value: ',', label: __( 'Comma (1,000)', 'wpinterface-travel' ) },
	{ value: '.', label: __( 'Period (1.000)', 'wpinterface-travel' ) },
	{ value: ' ', label: __( 'Space (1 000)', 'wpinterface-travel' ) },
	{ value: "'", label: __( "Apostrophe (1'000)", 'wpinterface-travel' ) },
	{ value: '', label: __( 'None (1000)', 'wpinterface-travel' ) },
];

const DECIMAL_SEPARATORS = [
	{ value: '.', label: __( 'Period (0.50)', 'wpinterface-travel' ) },
	{ value: ',', label: __( 'Comma (0,50)', 'wpinterface-travel' ) },
];

function Section( { title, children } ) {
	return (
		<Card className="wpitravel-settings__section">
			<CardHeader>
				<h2>{ title }</h2>
			</CardHeader>
			<CardBody>{ children }</CardBody>
		</Card>
	);
}

export default function SettingsApp( { initial } ) {
	const {
		currencies,
		positions,
		pages = [],
		gateways = [],
		secretMask,
		restPath,
		siteUrl,
	} = initial;
	const [ settings, setSettings ] = useState( initial.settings );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const set = ( section, key, value ) =>
		setSettings( ( current ) => ( {
			...current,
			[ section ]: { ...current[ section ], [ key ]: value },
		} ) );

	const currency = settings.currency;
	const currencyInfo = currencies.find( ( c ) => c.code === currency.code );

	const onCurrencyChange = ( code ) => {
		const info = currencies.find( ( c ) => c.code === code );
		if ( ! info ) {
			return;
		}
		setSettings( ( current ) => ( {
			...current,
			currency: {
				...current.currency,
				code,
				decimals: info.decimals,
			},
		} ) );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const response = await apiFetch( {
				path: restPath,
				method: 'POST',
				data: settings,
			} );
			setSettings( response.settings );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'wpinterface-travel' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__( 'Settings could not be saved.', 'wpinterface-travel' ),
			} );
		}
		setSaving( false );
	};

	const base = ( key ) => settings.permalinks[ key ];

	return (
		<div className="wpitravel-settings">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Section title={ __( 'Currency', 'wpinterface-travel' ) }>
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Currency', 'wpinterface-travel' ) }
					value={ currency.code }
					options={ currencies.map( ( c ) => ( {
						value: c.code,
						label: `${ c.name } (${ c.code } ${ c.symbol })`,
					} ) ) }
					onChange={ onCurrencyChange }
				/>
				<div className="wpitravel-grid">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Symbol position', 'wpinterface-travel' ) }
						value={ currency.position }
						options={ positions }
						onChange={ ( value ) =>
							set( 'currency', 'position', value )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Thousand separator',
							'wpinterface-travel'
						) }
						value={ currency.thousand_separator }
						options={ THOUSAND_SEPARATORS }
						onChange={ ( value ) =>
							set( 'currency', 'thousand_separator', value )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Decimal separator',
							'wpinterface-travel'
						) }
						value={ currency.decimal_separator }
						options={ DECIMAL_SEPARATORS }
						onChange={ ( value ) =>
							set( 'currency', 'decimal_separator', value )
						}
					/>
					<IntegerField
						label={ __( 'Decimals', 'wpinterface-travel' ) }
						min={ 0 }
						max={ 4 }
						value={ currency.decimals }
						onChange={ ( value ) =>
							set( 'currency', 'decimals', value )
						}
					/>
				</div>
				<p className="wpitravel-settings__preview">
					{ __( 'Preview:', 'wpinterface-travel' ) }{ ' ' }
					<strong>
						{ formatPrice(
							1234.5,
							currency,
							currencyInfo?.symbol || currency.code
						) }
					</strong>
				</p>
			</Section>

			<Section title={ __( 'Tax', 'wpinterface-travel' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Charge tax on bookings',
						'wpinterface-travel'
					) }
					checked={ settings.tax.enabled }
					onChange={ ( value ) => set( 'tax', 'enabled', value ) }
				/>
				{ settings.tax.enabled && (
					<>
						<div className="wpitravel-grid">
							<TextField
								label={ __(
									'Tax label',
									'wpinterface-travel'
								) }
								help={ __(
									'e.g. VAT, GST, Sales tax.',
									'wpinterface-travel'
								) }
								value={ settings.tax.label }
								onChange={ ( value ) =>
									set( 'tax', 'label', value )
								}
							/>
							<DecimalField
								label={ __(
									'Tax rate (%)',
									'wpinterface-travel'
								) }
								min={ 0 }
								max={ 100 }
								value={ settings.tax.rate }
								onChange={ ( value ) =>
									set( 'tax', 'rate', value )
								}
							/>
						</div>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Trip prices already include tax',
								'wpinterface-travel'
							) }
							help={ __(
								'Common in Europe and Australia. When off, tax is added at checkout.',
								'wpinterface-travel'
							) }
							checked={ settings.tax.prices_include_tax }
							onChange={ ( value ) =>
								set( 'tax', 'prices_include_tax', value )
							}
						/>
					</>
				) }
			</Section>

			<Section title={ __( 'Booking', 'wpinterface-travel' ) }>
				<div className="wpitravel-grid">
					<TextField
						label={ __(
							'Booking number prefix',
							'wpinterface-travel'
						) }
						help={ sprintf(
							/* translators: %s: example booking number. */
							__(
								'Bookings are numbered like %s.',
								'wpinterface-travel'
							),
							`${ settings.booking.number_prefix }1042`
						) }
						value={ settings.booking.number_prefix }
						onChange={ ( value ) =>
							set( 'booking', 'number_prefix', value )
						}
					/>
					<IntegerField
						label={ __(
							'Hold seats during checkout (minutes)',
							'wpinterface-travel'
						) }
						help={ __(
							'Seats are reserved while the customer pays, so nobody else can book them.',
							'wpinterface-travel'
						) }
						min={ 5 }
						max={ 120 }
						value={ settings.booking.hold_minutes }
						onChange={ ( value ) =>
							set( 'booking', 'hold_minutes', value )
						}
					/>
				</div>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Allow booking without an account',
						'wpinterface-travel'
					) }
					checked={ settings.booking.guest_checkout }
					onChange={ ( value ) =>
						set( 'booking', 'guest_checkout', value )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Let guests create an account while booking',
						'wpinterface-travel'
					) }
					help={ __(
						'The customer gets an email to set a password and can then see their bookings.',
						'wpinterface-travel'
					) }
					checked={ settings.booking.create_accounts }
					onChange={ ( value ) =>
						set( 'booking', 'create_accounts', value )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Add the booking form to trip pages',
						'wpinterface-travel'
					) }
					help={ __(
						'Turn off to place it yourself with the [wpitravel_booking] shortcode.',
						'wpinterface-travel'
					) }
					checked={ settings.booking.auto_widget }
					onChange={ ( value ) =>
						set( 'booking', 'auto_widget', value )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Terms and conditions page',
						'wpinterface-travel'
					) }
					help={ __(
						'When set, customers must accept this page to book.',
						'wpinterface-travel'
					) }
					value={ String( settings.booking.terms_page_id ) }
					options={ [
						{
							value: '0',
							label: __( 'None', 'wpinterface-travel' ),
						},
						...pages,
					] }
					onChange={ ( value ) =>
						set( 'booking', 'terms_page_id', parseInt( value, 10 ) )
					}
				/>
			</Section>

			<Section title={ __( 'Customers', 'wpinterface-travel' ) }>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( '"My bookings" page', 'wpinterface-travel' ) }
					help={ __(
						'Customers see, pay and manage their bookings here. Links in emails point to it. The page needs the [wpitravel_account] shortcode.',
						'wpinterface-travel'
					) }
					value={ String( settings.booking.account_page_id ) }
					options={ [
						{
							value: '0',
							label: __( 'None', 'wpinterface-travel' ),
						},
						...pages,
					] }
					onChange={ ( value ) =>
						set(
							'booking',
							'account_page_id',
							parseInt( value, 10 )
						)
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Trip reviews', 'wpinterface-travel' ) }
					checked={ settings.reviews.enabled }
					onChange={ ( value ) => set( 'reviews', 'enabled', value ) }
				/>
				{ settings.reviews.enabled && (
					<>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Who can review',
								'wpinterface-travel'
							) }
							value={ settings.reviews.who }
							options={ [
								{
									value: 'verified',
									label: __(
										'Only travelers who booked (recommended)',
										'wpinterface-travel'
									),
								},
								{
									value: 'anyone',
									label: __(
										'Anyone (held for moderation)',
										'wpinterface-travel'
									),
								},
							] }
							onChange={ ( value ) =>
								set( 'reviews', 'who', value )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Publish reviews from travelers who booked right away',
								'wpinterface-travel'
							) }
							help={ __(
								'Spam checks still apply. Moderate reviews in Comments → Trip reviews.',
								'wpinterface-travel'
							) }
							checked={ settings.reviews.auto_approve_verified }
							onChange={ ( value ) =>
								set( 'reviews', 'auto_approve_verified', value )
							}
						/>
					</>
				) }
			</Section>

			<Section title={ __( 'Payments', 'wpinterface-travel' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Confirm bookings automatically when fully paid',
						'wpinterface-travel'
					) }
					checked={ settings.payments.confirm_on_payment }
					onChange={ ( value ) =>
						set( 'payments', 'confirm_on_payment', value )
					}
				/>
				<IntegerField
					label={ __(
						'Cancel unpaid online bookings after (minutes)',
						'wpinterface-travel'
					) }
					help={ __(
						'Frees the seats when a customer leaves the online payment page without paying. 0 = never. Bank transfer and pay later bookings are not affected.',
						'wpinterface-travel'
					) }
					max={ 10080 }
					value={ settings.payments.unpaid_timeout_minutes }
					onChange={ ( value ) =>
						set( 'payments', 'unpaid_timeout_minutes', value )
					}
				/>
				{ gateways.map( ( gateway ) => (
					<PaymentGateway
						key={ gateway.id }
						gateway={ gateway }
						secretMask={ secretMask }
						values={
							settings.payments.gateways[ gateway.id ] || {}
						}
						onChange={ ( key, value ) =>
							setSettings( ( current ) => ( {
								...current,
								payments: {
									...current.payments,
									gateways: {
										...current.payments.gateways,
										[ gateway.id ]: {
											...current.payments.gateways[
												gateway.id
											],
											[ key ]: value,
										},
									},
								},
							} ) )
						}
					/>
				) ) }
			</Section>

			<Section title={ __( 'Permalinks', 'wpinterface-travel' ) }>
				<div className="wpitravel-grid">
					<TextField
						label={ __( 'Trips', 'wpinterface-travel' ) }
						help={ `${ siteUrl }${ base(
							'trip_base'
						) }/everest-base-camp/` }
						value={ base( 'trip_base' ) }
						onChange={ ( value ) =>
							set( 'permalinks', 'trip_base', value )
						}
					/>
					<TextField
						label={ __( 'Destinations', 'wpinterface-travel' ) }
						help={ `${ siteUrl }${ base(
							'destination_base'
						) }/nepal/` }
						value={ base( 'destination_base' ) }
						onChange={ ( value ) =>
							set( 'permalinks', 'destination_base', value )
						}
					/>
					<TextField
						label={ __( 'Activities', 'wpinterface-travel' ) }
						help={ `${ siteUrl }${ base(
							'activity_base'
						) }/trekking/` }
						value={ base( 'activity_base' ) }
						onChange={ ( value ) =>
							set( 'permalinks', 'activity_base', value )
						}
					/>
					<TextField
						label={ __( 'Trip types', 'wpinterface-travel' ) }
						help={ `${ siteUrl }${ base(
							'trip_type_base'
						) }/adventure/` }
						value={ base( 'trip_type_base' ) }
						onChange={ ( value ) =>
							set( 'permalinks', 'trip_type_base', value )
						}
					/>
				</div>
			</Section>

			<Section title={ __( 'Data', 'wpinterface-travel' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Delete all data when the plugin is deleted',
						'wpinterface-travel'
					) }
					help={ __(
						'Removes trips, bookings, payments and settings permanently when you delete the plugin. Leave off unless you are sure.',
						'wpinterface-travel'
					) }
					checked={ settings.general.delete_data_on_uninstall }
					onChange={ ( value ) =>
						set( 'general', 'delete_data_on_uninstall', value )
					}
				/>
			</Section>

			<div className="wpitravel-settings__actions">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ save }
				>
					{ __( 'Save settings', 'wpinterface-travel' ) }
				</Button>
			</div>
		</div>
	);
}
