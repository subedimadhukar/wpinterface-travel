/**
 * Payment method choice at checkout.
 */
import { __ } from '@wordpress/i18n';
import { useInstanceId } from '@wordpress/compose';

export default function PaymentMethods( { gateways, value, onChange } ) {
	const name = useInstanceId( PaymentMethods, 'wpitravel-gateway' );

	if ( gateways.length === 0 ) {
		return null;
	}

	return (
		<fieldset className="wpitravel-checkout__group wpitravel-gateways">
			<legend>{ __( 'Payment', 'wpinterface-travel' ) }</legend>
			{ gateways.map( ( gateway ) => {
				const id = `${ name }-${ gateway.id }`;
				return (
					<div
						key={ gateway.id }
						className={
							gateway.id === value
								? 'wpitravel-gateways__option is-selected'
								: 'wpitravel-gateways__option'
						}
					>
						<input
							id={ id }
							type="radio"
							name={ name }
							value={ gateway.id }
							checked={ gateway.id === value }
							onChange={ () => onChange( gateway.id ) }
						/>
						<label htmlFor={ id }>
							<strong>{ gateway.title }</strong>
							{ gateway.description && (
								<span className="wpitravel-booking__muted">
									{ gateway.description }
								</span>
							) }
						</label>
					</div>
				);
			} ) }
		</fieldset>
	);
}
