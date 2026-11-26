/**
 * Settings of one payment gateway, rendered from the gateway's own field list.
 */
import { __, sprintf } from '@wordpress/i18n';
import { TextareaControl, ToggleControl } from '@wordpress/components';
import { TextField } from '../components/fields';

export default function PaymentGateway( {
	gateway,
	values,
	secretMask,
	onChange,
} ) {
	const field = ( item ) => {
		const value = values[ item.key ];

		switch ( item.type ) {
			case 'toggle':
				return (
					<ToggleControl
						__nextHasNoMarginBottom
						key={ item.key }
						label={ item.label }
						help={ item.help }
						checked={ !! value }
						onChange={ ( checked ) =>
							onChange( item.key, checked )
						}
					/>
				);
			case 'textarea':
				return (
					<TextareaControl
						__nextHasNoMarginBottom
						key={ item.key }
						label={ item.label }
						help={ item.help }
						rows={ 4 }
						value={ value || '' }
						onChange={ ( text ) => onChange( item.key, text ) }
					/>
				);
			case 'password':
				return (
					<TextField
						key={ item.key }
						type="password"
						autoComplete="new-password"
						label={ item.label }
						help={
							value === secretMask
								? __(
										'Saved. Type a new value to replace it.',
										'wpinterface-travel'
								  )
								: item.help
						}
						value={ value || '' }
						onFocus={ () =>
							value === secretMask && onChange( item.key, '' )
						}
						onChange={ ( text ) => onChange( item.key, text ) }
					/>
				);
			default:
				return (
					<TextField
						key={ item.key }
						label={ item.label }
						help={ item.help }
						value={ value || '' }
						onChange={ ( text ) => onChange( item.key, text ) }
					/>
				);
		}
	};

	return (
		<div className="wpitravel-gateway">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: payment method name. */
					__( 'Accept %s', 'wpinterface-travel' ),
					gateway.title
				) }
				checked={ !! values.enabled }
				onChange={ ( checked ) => onChange( 'enabled', checked ) }
			/>
			{ values.enabled && (
				<div className="wpitravel-gateway__fields">
					{ gateway.fields.map( field ) }
					{ gateway.webhook_url && (
						<TextField
							label={ __( 'Webhook URL', 'wpinterface-travel' ) }
							help={ __(
								'Copy this into your provider account.',
								'wpinterface-travel'
							) }
							value={ gateway.webhook_url }
							readOnly
							onFocus={ ( event ) => event.target.select() }
							onChange={ () => {} }
						/>
					) }
				</div>
			) }
		</div>
	);
}
