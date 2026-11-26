/**
 * Price options, each with prices per traveler type.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { trash } from '@wordpress/icons';
import Repeater from '../../components/Repeater';
import { IntegerField, TextField } from '../../components/fields';

const { currency = { code: 'USD' } } = window.wpitravelTripEditor || {};

const newCategory = ( label = '' ) => ( {
	key: '',
	label,
	price: '',
	sale_price: '',
	min: 0,
	max: 0,
} );

const newOption = () => ( {
	key: '',
	label: '',
	min_pax: 1,
	max_pax: 0,
	categories: [
		{ ...newCategory( __( 'Adult', 'wpinterface-travel' ) ), min: 1 },
	],
} );

/**
 * Rows of traveler types (Adult, Child…) with their prices.
 *
 * @param {Object}   props
 * @param {Array}    props.categories Categories.
 * @param {Function} props.onChange   Receives the new categories.
 */
function CategoryRows( { categories, onChange } ) {
	const update = ( index, patch ) =>
		onChange(
			categories.map( ( category, i ) =>
				i === index ? { ...category, ...patch } : category
			)
		);

	return (
		<fieldset className="wpitravel-fieldset">
			<legend className="wpitravel-field-label">
				{ __( 'Prices per traveler', 'wpinterface-travel' ) }
			</legend>
			<div className="wpitravel-price-rows">
				{ categories.map( ( category, index ) => (
					<div className="wpitravel-price-row" key={ index }>
						<TextField
							label={ __(
								'Traveler type',
								'wpinterface-travel'
							) }
							placeholder={ __(
								'e.g. Adult',
								'wpinterface-travel'
							) }
							value={ category.label }
							onChange={ ( label ) => update( index, { label } ) }
						/>
						<TextField
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Price (%s)', 'wpinterface-travel' ),
								currency.code
							) }
							inputMode="decimal"
							value={ String( category.price ?? '' ) }
							onChange={ ( price ) => update( index, { price } ) }
						/>
						<TextField
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Sale price (%s)', 'wpinterface-travel' ),
								currency.code
							) }
							inputMode="decimal"
							placeholder={ __(
								'Optional',
								'wpinterface-travel'
							) }
							value={ String( category.sale_price ?? '' ) }
							onChange={ ( salePrice ) =>
								update( index, { sale_price: salePrice } )
							}
						/>
						<IntegerField
							label={ __( 'Min', 'wpinterface-travel' ) }
							value={ category.min }
							onChange={ ( min ) => update( index, { min } ) }
						/>
						<IntegerField
							label={ __( 'Max', 'wpinterface-travel' ) }
							value={ category.max }
							onChange={ ( max ) => update( index, { max } ) }
						/>
						<Button
							icon={ trash }
							isDestructive
							className="wpitravel-price-row__remove"
							label={ __(
								'Remove traveler type',
								'wpinterface-travel'
							) }
							disabled={ categories.length === 1 }
							onClick={ () =>
								onChange(
									categories.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</div>
				) ) }
			</div>
			<p className="wpitravel-help">
				{ __(
					'Prices are per traveler. Min and max limit how many of each type one booking can include (0 = no limit). A sale price only applies when it is lower than the price.',
					'wpinterface-travel'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ () => onChange( [ ...categories, newCategory() ] ) }
			>
				{ __( 'Add traveler type', 'wpinterface-travel' ) }
			</Button>
		</fieldset>
	);
}

export default function PricingTab( { meta, set } ) {
	const pricing = meta._wpitravel_pricing || { options: [] };

	return (
		<>
			<p className="wpitravel-help">
				{ __(
					'Add at least one price option, e.g. "Standard" or "Private group". Customers pick an option, then how many travelers of each type.',
					'wpinterface-travel'
				) }
			</p>
			<Repeater
				items={ pricing.options }
				onChange={ ( options ) =>
					set( '_wpitravel_pricing', { ...pricing, options } )
				}
				newItem={ newOption }
				addLabel={ __( 'Add price option', 'wpinterface-travel' ) }
				emptyText={ __(
					'No prices yet, so this trip cannot be booked.',
					'wpinterface-travel'
				) }
				itemLabel={ ( option, index ) =>
					option.label ||
					sprintf(
						/* translators: %d: option number. */
						__( 'Price option %d', 'wpinterface-travel' ),
						index + 1
					)
				}
				renderItem={ ( option, update ) => (
					<>
						<div className="wpitravel-grid wpitravel-grid--3">
							<TextField
								label={ __(
									'Option name',
									'wpinterface-travel'
								) }
								placeholder={ __(
									'e.g. Standard',
									'wpinterface-travel'
								) }
								value={ option.label }
								onChange={ ( label ) => update( { label } ) }
							/>
							<IntegerField
								label={ __(
									'Minimum travelers per booking',
									'wpinterface-travel'
								) }
								min={ 1 }
								value={ option.min_pax }
								onChange={ ( minPax ) =>
									update( { min_pax: minPax } )
								}
							/>
							<IntegerField
								label={ __(
									'Maximum travelers per booking',
									'wpinterface-travel'
								) }
								help={ __(
									'0 = no limit.',
									'wpinterface-travel'
								) }
								value={ option.max_pax }
								onChange={ ( maxPax ) =>
									update( { max_pax: maxPax } )
								}
							/>
						</div>
						<CategoryRows
							categories={ option.categories || [] }
							onChange={ ( categories ) =>
								update( { categories } )
							}
						/>
					</>
				) }
			/>
		</>
	);
}
