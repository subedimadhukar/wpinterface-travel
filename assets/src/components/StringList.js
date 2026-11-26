/**
 * A list of one-line text entries (highlights, included, excluded).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
import { trash } from '@wordpress/icons';

/**
 * @param {Object}   props
 * @param {string}   props.label       Field label.
 * @param {string[]} props.items       Lines.
 * @param {Function} props.onChange    Receives the new lines.
 * @param {string}   props.addLabel    Add button text.
 * @param {string}   props.placeholder Placeholder for new lines.
 */
export default function StringList( {
	label,
	items = [],
	onChange,
	addLabel,
	placeholder,
} ) {
	const set = ( index, value ) =>
		onChange( items.map( ( item, i ) => ( i === index ? value : item ) ) );

	return (
		<fieldset className="wpitravel-string-list">
			<legend className="wpitravel-field-label">{ label }</legend>
			{ items.map( ( item, index ) => (
				<div className="wpitravel-string-list__row" key={ index }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						hideLabelFromVision
						label={ sprintf(
							/* translators: 1: list label, 2: line number. */
							__( '%1$s, line %2$d', 'wpinterface-travel' ),
							label,
							index + 1
						) }
						value={ item }
						placeholder={ placeholder }
						onChange={ ( value ) => set( index, value ) }
					/>
					<Button
						icon={ trash }
						isDestructive
						label={ __( 'Remove line', 'wpinterface-travel' ) }
						onClick={ () =>
							onChange( items.filter( ( _, i ) => i !== index ) )
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => onChange( [ ...items, '' ] ) }
			>
				{ addLabel }
			</Button>
		</fieldset>
	);
}
