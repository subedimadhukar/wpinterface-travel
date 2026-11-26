/**
 * Number of travelers per type.
 */
import { __, sprintf } from '@wordpress/i18n';
import displayPrice from './displayPrice';

export default function PaxSelector( {
	option,
	pax,
	onChange,
	seatsLeft,
	currency,
} ) {
	const total = Object.values( pax ).reduce( ( sum, n ) => sum + n, 0 );
	const limit = Math.min(
		option.maxPax > 0 ? option.maxPax : Infinity,
		seatsLeft === null || seatsLeft === undefined ? Infinity : seatsLeft
	);

	return (
		<div className="wpitravel-pax">
			{ option.categories.map( ( category ) => {
				const count = pax[ category.key ] || 0;
				const canAdd =
					total < limit &&
					( category.max === 0 || count < category.max );
				const canRemove = count > category.min;

				return (
					<div className="wpitravel-pax__row" key={ category.key }>
						<div className="wpitravel-pax__label">
							<span>{ category.label }</span>
							<span className="wpitravel-booking__muted">
								{ category.price !== category.regular && (
									<del>
										{ displayPrice(
											category.regular,
											currency,
											currency.symbol
										) }
									</del>
								) }{ ' ' }
								{ displayPrice(
									category.price,
									currency,
									currency.symbol
								) }
							</span>
						</div>
						<div className="wpitravel-stepper">
							<button
								type="button"
								disabled={ ! canRemove }
								aria-label={ sprintf(
									/* translators: %s: traveler type. */
									__( 'Fewer %s', 'wpinterface-travel' ),
									category.label
								) }
								onClick={ () =>
									onChange( {
										...pax,
										[ category.key ]: count - 1,
									} )
								}
							>
								−
							</button>
							<output aria-live="polite">{ count }</output>
							<button
								type="button"
								disabled={ ! canAdd }
								aria-label={ sprintf(
									/* translators: %s: traveler type. */
									__( 'More %s', 'wpinterface-travel' ),
									category.label
								) }
								onClick={ () =>
									onChange( {
										...pax,
										[ category.key ]: count + 1,
									} )
								}
							>
								+
							</button>
						</div>
					</div>
				);
			} ) }
			{ seatsLeft !== null &&
				seatsLeft !== undefined &&
				seatsLeft <= 5 && (
					<p className="wpitravel-booking__muted">
						{ sprintf(
							/* translators: %d: seats left. */
							__(
								'Only %d seats left on this date.',
								'wpinterface-travel'
							),
							seatsLeft
						) }
					</p>
				) }
		</div>
	);
}
