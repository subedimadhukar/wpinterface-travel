/**
 * Trip photo gallery, picked from the media library.
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { MediaUpload } from '@wordpress/media-utils';
import { closeSmall } from '@wordpress/icons';

const NO_IMAGES = [];

export default function GalleryTab( { meta, set } ) {
	const stored = meta._wpitravel_gallery;
	const ids = useMemo( () => stored || NO_IMAGES, [ stored ] );

	const images = useSelect(
		( select ) => ids.map( ( id ) => select( coreStore ).getMedia( id ) ),
		[ ids ]
	);

	return (
		<>
			{ ids.length > 0 && (
				<ul className="wpitravel-gallery">
					{ ids.map( ( id, index ) => {
						const image = images[ index ];
						const src =
							image?.media_details?.sizes?.thumbnail
								?.source_url || image?.source_url;
						return (
							<li key={ id } className="wpitravel-gallery__item">
								{ src ? (
									<img src={ src } alt={ image.alt_text } />
								) : (
									<Spinner />
								) }
								<Button
									icon={ closeSmall }
									size="small"
									className="wpitravel-gallery__remove"
									label={ __(
										'Remove image',
										'wpinterface-travel'
									) }
									onClick={ () =>
										set(
											'_wpitravel_gallery',
											ids.filter(
												( value ) => value !== id
											)
										)
									}
								/>
							</li>
						);
					} ) }
				</ul>
			) }
			<MediaUpload
				title={ __( 'Trip gallery', 'wpinterface-travel' ) }
				allowedTypes={ [ 'image' ] }
				multiple
				gallery
				value={ ids }
				onSelect={ ( selected ) =>
					set(
						'_wpitravel_gallery',
						selected.map( ( image ) => image.id )
					)
				}
				render={ ( { open } ) => (
					<Button variant="secondary" onClick={ open }>
						{ ids.length
							? __( 'Edit gallery', 'wpinterface-travel' )
							: __( 'Add images', 'wpinterface-travel' ) }
					</Button>
				) }
			/>
		</>
	);
}
