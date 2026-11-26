/**
 * Price breakdown of a quote.
 */
import { __ } from '@wordpress/i18n';
import displayPrice from './displayPrice';

export default function QuoteSummary( { quote, currency } ) {
	const money = ( amount ) => {
		const negative = parseFloat( amount ) < 0;
		const text = displayPrice(
			Math.abs( parseFloat( amount ) ),
			currency,
			currency.symbol
		);
		return negative ? `−${ text }` : text;
	};

	return (
		<dl className="wpitravel-summary" aria-live="polite">
			{ quote.items.map( ( item ) => (
				<div
					key={ `${ item.type }-${ item.key }` }
					className={ `wpitravel-summary__row is-${ item.type }` }
				>
					<dt>
						{ item.type === 'pax'
							? `${ item.label } × ${ item.quantity }`
							: item.label }
					</dt>
					<dd>
						{ item.included
							? `(${ money( item.total ) })`
							: money( item.total ) }
					</dd>
				</div>
			) ) }
			<div className="wpitravel-summary__row is-total">
				<dt>{ __( 'Total', 'wpinterface-travel' ) }</dt>
				<dd>{ money( quote.total ) }</dd>
			</div>
		</dl>
	);
}
