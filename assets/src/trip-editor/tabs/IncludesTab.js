/**
 * What the price includes and excludes.
 */
import { __ } from '@wordpress/i18n';
import StringList from '../../components/StringList';

export default function IncludesTab( { meta, set } ) {
	return (
		<div className="wpitravel-grid">
			<StringList
				label={ __( 'Included', 'wpinterface-travel' ) }
				items={ meta._wpitravel_includes || [] }
				onChange={ ( items ) => set( '_wpitravel_includes', items ) }
				addLabel={ __( 'Add included item', 'wpinterface-travel' ) }
				placeholder={ __(
					'e.g. Airport pickup',
					'wpinterface-travel'
				) }
			/>
			<StringList
				label={ __( 'Excluded', 'wpinterface-travel' ) }
				items={ meta._wpitravel_excludes || [] }
				onChange={ ( items ) => set( '_wpitravel_excludes', items ) }
				addLabel={ __( 'Add excluded item', 'wpinterface-travel' ) }
				placeholder={ __(
					'e.g. International flights',
					'wpinterface-travel'
				) }
			/>
		</div>
	);
}
