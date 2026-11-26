/**
 * Sample trips and importing from other travel plugins.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';

const BASE = '/wpinterface-travel/v1';

export default function ContentStep( { status, setStatus } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ log, setLog ] = useState( {} );

	const samples = async ( method ) => {
		setBusy( 'samples' );
		setError( null );
		try {
			const result = await apiFetch( {
				path: `${ BASE }/setup/sample-data`,
				method,
			} );
			setStatus( { ...status, samples: result.samples } );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const runImport = async ( importer ) => {
		setBusy( importer.id );
		setError( null );

		const done = { created: 0, skipped: 0, notes: [] };
		let offset = 0;

		try {
			while ( offset !== null ) {
				const result = await apiFetch( {
					path: `${ BASE }/import/${ importer.id }`,
					method: 'POST',
					data: { offset },
				} );

				done.created += result.created.length;
				done.skipped += result.skipped;
				done.notes = [ ...done.notes, ...result.notes ];
				offset = result.next;

				setLog( ( current ) => ( {
					...current,
					[ importer.id ]: {
						...done,
						progress: Math.min(
							result.total,
							offset === null ? result.total : offset
						),
						total: result.total,
						finished: offset === null,
					},
				} ) );
			}

			const fresh = await apiFetch( { path: `${ BASE }/setup` } );
			setStatus( fresh );
		} catch ( e ) {
			setError( e.message );
		}

		setBusy( '' );
	};

	return (
		<>
			<h2>{ __( 'Add your trips', 'wpinterface-travel' ) }</h2>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ status.importers.length > 0 && (
				<section className="wpitravel-setup__section">
					<h3>
						{ __(
							'Import from another plugin',
							'wpinterface-travel'
						) }
					</h3>
					<p>
						{ __(
							'Trips are copied with their itinerary, prices, upcoming dates, photos and destinations. The other plugin’s data is not changed, and running an import again skips trips already copied. Imported trips keep their original status (published or draft).',
							'wpinterface-travel'
						) }
					</p>
					{ status.importers.map( ( importer ) => {
						const entry = log[ importer.id ];

						return (
							<div
								key={ importer.id }
								className="wpitravel-setup__importer"
							>
								<div>
									<strong>{ importer.label }</strong>
									<div className="wpitravel-setup__muted">
										{ sprintf(
											/* translators: 1: trips found, 2: trips already imported. */
											_n(
												'%1$d trip found, %2$d imported',
												'%1$d trips found, %2$d imported',
												importer.count,
												'wpinterface-travel'
											),
											importer.count,
											importer.imported
										) }
									</div>
									{ entry && (
										<div
											className="wpitravel-setup__progress"
											role="status"
										>
											{ entry.finished
												? sprintf(
														/* translators: 1: trips created, 2: trips skipped. */
														__(
															'Done: %1$d created, %2$d skipped.',
															'wpinterface-travel'
														),
														entry.created,
														entry.skipped
												  )
												: sprintf(
														/* translators: 1: trips processed, 2: total. */
														__(
															'Importing %1$d of %2$d…',
															'wpinterface-travel'
														),
														entry.progress,
														entry.total
												  ) }
											{ entry.notes.length > 0 && (
												<ul>
													{ entry.notes.map(
														( note, index ) => (
															<li key={ index }>
																{ note }
															</li>
														)
													) }
												</ul>
											) }
										</div>
									) }
								</div>
								<Button
									variant="secondary"
									isBusy={ busy === importer.id }
									disabled={ !! busy }
									onClick={ () => runImport( importer ) }
								>
									{ importer.imported > 0
										? __(
												'Import new trips',
												'wpinterface-travel'
										  )
										: __(
												'Import trips',
												'wpinterface-travel'
										  ) }
								</Button>
							</div>
						);
					} ) }
				</section>
			) }

			<section className="wpitravel-setup__section">
				<h3>{ __( 'Sample trips', 'wpinterface-travel' ) }</h3>
				{ status.samples.length > 0 ? (
					<>
						<p>
							{ sprintf(
								/* translators: %d: number of sample trips. */
								_n(
									'%d sample trip is on your site. Remove it when you have added your own.',
									'%d sample trips are on your site. Remove them when you have added your own.',
									status.samples.length,
									'wpinterface-travel'
								),
								status.samples.length
							) }
						</p>
						<Button
							variant="secondary"
							isDestructive
							isBusy={ busy === 'samples' }
							disabled={ !! busy }
							onClick={ () => samples( 'DELETE' ) }
						>
							{ __(
								'Remove sample trips',
								'wpinterface-travel'
							) }
						</Button>
					</>
				) : (
					<>
						<p>
							{ __(
								'Add three example trips (a trek, a city walk and a safari) with prices, dates, itineraries and FAQs, to see how everything looks. You can remove them in one click later.',
								'wpinterface-travel'
							) }
						</p>
						<Button
							variant="secondary"
							isBusy={ busy === 'samples' }
							disabled={ !! busy }
							onClick={ () => samples( 'POST' ) }
						>
							{ __( 'Add sample trips', 'wpinterface-travel' ) }
						</Button>
					</>
				) }
			</section>
		</>
	);
}
