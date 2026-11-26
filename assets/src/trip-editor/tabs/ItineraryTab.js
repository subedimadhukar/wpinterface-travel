/**
 * Day-by-day itinerary.
 */
import { __, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import Repeater from '../../components/Repeater';
import { TextField, TextareaField } from '../../components/fields';

const { meals = [] } = window.wpitravelTripEditor || {};

const newDay = () => ( {
	title: '',
	description: '',
	meals: [],
	accommodation: '',
} );

export default function ItineraryTab( { meta, set } ) {
	return (
		<Repeater
			items={ meta._wpitravel_itinerary || [] }
			onChange={ ( items ) => set( '_wpitravel_itinerary', items ) }
			newItem={ newDay }
			addLabel={ __( 'Add day', 'wpinterface-travel' ) }
			emptyText={ __(
				'No days yet. Add one row per day of the trip.',
				'wpinterface-travel'
			) }
			itemLabel={ ( day, index ) =>
				day.title
					? sprintf(
							/* translators: 1: day number, 2: day title. */
							__( 'Day %1$d: %2$s', 'wpinterface-travel' ),
							index + 1,
							day.title
					  )
					: sprintf(
							/* translators: %d: day number. */
							__( 'Day %d', 'wpinterface-travel' ),
							index + 1
					  )
			}
			renderItem={ ( day, update ) => (
				<>
					<TextField
						label={ __( 'Title', 'wpinterface-travel' ) }
						value={ day.title }
						onChange={ ( title ) => update( { title } ) }
					/>
					<TextareaField
						label={ __( 'Description', 'wpinterface-travel' ) }
						value={ day.description }
						onChange={ ( description ) =>
							update( { description } )
						}
					/>
					<fieldset className="wpitravel-inline-checks">
						<legend className="wpitravel-field-label">
							{ __( 'Meals included', 'wpinterface-travel' ) }
						</legend>
						{ meals.map( ( meal ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ meal.value }
								label={ meal.label }
								checked={ ( day.meals || [] ).includes(
									meal.value
								) }
								onChange={ ( checked ) =>
									update( {
										meals: checked
											? [
													...( day.meals || [] ),
													meal.value,
											  ]
											: ( day.meals || [] ).filter(
													( value ) =>
														value !== meal.value
											  ),
									} )
								}
							/>
						) ) }
					</fieldset>
					<TextField
						label={ __( 'Accommodation', 'wpinterface-travel' ) }
						placeholder={ __(
							'e.g. Teahouse in Namche',
							'wpinterface-travel'
						) }
						value={ day.accommodation }
						onChange={ ( accommodation ) =>
							update( { accommodation } )
						}
					/>
				</>
			) }
		/>
	);
}
