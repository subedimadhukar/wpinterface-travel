/**
 * A list of editable rows with add, remove and reorder.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { arrowUp, arrowDown, trash } from '@wordpress/icons';

/**
 * @param {Object}   props
 * @param {Array}    props.items      Rows.
 * @param {Function} props.onChange   Receives the new rows.
 * @param {Function} props.newItem    Returns an empty row.
 * @param {Function} props.renderItem ( item, update( patch ), index ) => element.
 * @param {Function} props.itemLabel  ( item, index ) => header text.
 * @param {string}   props.addLabel   Add button text.
 * @param {string}   props.emptyText  Shown when there are no rows.
 */
export default function Repeater( {
	items = [],
	onChange,
	newItem,
	renderItem,
	itemLabel,
	addLabel,
	emptyText,
} ) {
	const update = ( index, patch ) =>
		onChange(
			items.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			)
		);

	const move = ( index, offset ) => {
		const next = [ ...items ];
		const [ moved ] = next.splice( index, 1 );
		next.splice( index + offset, 0, moved );
		onChange( next );
	};

	const remove = ( index ) =>
		onChange( items.filter( ( item, i ) => i !== index ) );

	return (
		<div className="wpitravel-repeater">
			{ items.length === 0 && emptyText && (
				<p className="wpitravel-repeater__empty">{ emptyText }</p>
			) }
			{ items.map( ( item, index ) => {
				const label = itemLabel( item, index );
				return (
					<Card
						key={ index }
						size="small"
						className="wpitravel-repeater__item"
					>
						<CardHeader>
							<strong>{ label }</strong>
							<div className="wpitravel-repeater__actions">
								<Button
									icon={ arrowUp }
									size="small"
									label={ sprintf(
										/* translators: %s: row label. */
										__(
											'Move %s up',
											'wpinterface-travel'
										),
										label
									) }
									disabled={ index === 0 }
									onClick={ () => move( index, -1 ) }
								/>
								<Button
									icon={ arrowDown }
									size="small"
									label={ sprintf(
										/* translators: %s: row label. */
										__(
											'Move %s down',
											'wpinterface-travel'
										),
										label
									) }
									disabled={ index === items.length - 1 }
									onClick={ () => move( index, 1 ) }
								/>
								<Button
									icon={ trash }
									size="small"
									isDestructive
									label={ sprintf(
										/* translators: %s: row label. */
										__( 'Remove %s', 'wpinterface-travel' ),
										label
									) }
									onClick={ () => remove( index ) }
								/>
							</div>
						</CardHeader>
						<CardBody>
							{ renderItem(
								item,
								( patch ) => update( index, patch ),
								index
							) }
						</CardBody>
					</Card>
				);
			} ) }
			<Button
				variant="secondary"
				onClick={ () => onChange( [ ...items, newItem() ] ) }
			>
				{ addLabel }
			</Button>
		</div>
	);
}
