/**
 * Trip location: address and coordinates (rendered with OpenStreetMap from Phase 6).
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { DecimalField, IntegerField, TextField } from '../../components/fields';

const DEFAULT_MAP = { lat: 0, lng: 0, zoom: 10, address: '' };

export default function MapTab( { meta, set } ) {
	const map = { ...DEFAULT_MAP, ...( meta._wpitravel_map || {} ) };
	const update = ( patch ) => set( '_wpitravel_map', { ...map, ...patch } );
	const hasPosition = map.lat !== 0 || map.lng !== 0;

	return (
		<>
			<TextField
				label={ __( 'Address or place', 'wpinterface-travel' ) }
				value={ map.address }
				onChange={ ( address ) => update( { address } ) }
			/>
			<div className="wpitravel-grid wpitravel-grid--3">
				<DecimalField
					label={ __( 'Latitude', 'wpinterface-travel' ) }
					value={ map.lat }
					min={ -90 }
					max={ 90 }
					onChange={ ( lat ) => update( { lat } ) }
				/>
				<DecimalField
					label={ __( 'Longitude', 'wpinterface-travel' ) }
					value={ map.lng }
					min={ -180 }
					max={ 180 }
					onChange={ ( lng ) => update( { lng } ) }
				/>
				<IntegerField
					label={ __( 'Zoom', 'wpinterface-travel' ) }
					min={ 1 }
					max={ 20 }
					value={ map.zoom }
					onChange={ ( zoom ) => update( { zoom } ) }
				/>
			</div>
			<p className="wpitravel-help">
				{ __(
					'Find coordinates by right-clicking a place on any online map.',
					'wpinterface-travel'
				) }{ ' ' }
				{ hasPosition && (
					<ExternalLink
						href={ `https://www.openstreetmap.org/?mlat=${ map.lat }&mlon=${ map.lng }#map=${ map.zoom }/${ map.lat }/${ map.lng }` }
					>
						{ __( 'Check on OpenStreetMap', 'wpinterface-travel' ) }
					</ExternalLink>
				) }
			</p>
		</>
	);
}
