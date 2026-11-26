/**
 * Custom label/value facts (e.g. "Max altitude: 5,364 m").
 */
import { __, sprintf } from '@wordpress/i18n';
import Repeater from '../../components/Repeater';
import { TextField } from '../../components/fields';

export default function FactsTab( { meta, set } ) {
	return (
		<>
			<p className="wpitravel-help">
				{ __(
					'Duration, difficulty, group size and minimum age are shown automatically. Add any other facts here.',
					'wpinterface-travel'
				) }
			</p>
			<Repeater
				items={ meta._wpitravel_facts || [] }
				onChange={ ( items ) => set( '_wpitravel_facts', items ) }
				newItem={ () => ( { label: '', value: '' } ) }
				addLabel={ __( 'Add fact', 'wpinterface-travel' ) }
				itemLabel={ ( fact, index ) =>
					fact.label ||
					sprintf(
						/* translators: %d: fact number. */
						__( 'Fact %d', 'wpinterface-travel' ),
						index + 1
					)
				}
				renderItem={ ( fact, update ) => (
					<div className="wpitravel-grid">
						<TextField
							label={ __( 'Label', 'wpinterface-travel' ) }
							placeholder={ __(
								'e.g. Max altitude',
								'wpinterface-travel'
							) }
							value={ fact.label }
							onChange={ ( label ) => update( { label } ) }
						/>
						<TextField
							label={ __( 'Value', 'wpinterface-travel' ) }
							placeholder={ __(
								'e.g. 5,364 m',
								'wpinterface-travel'
							) }
							value={ fact.value }
							onChange={ ( value ) => update( { value } ) }
						/>
					</div>
				) }
			/>
		</>
	);
}
