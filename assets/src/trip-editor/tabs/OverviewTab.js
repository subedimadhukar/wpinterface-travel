/**
 * Overview: code, duration, difficulty, group size, age, meeting point,
 * pickup points, highlights.
 */
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { IntegerField, TextField } from '../../components/fields';
import StringList from '../../components/StringList';
import Repeater from '../../components/Repeater';

const { difficulties = [] } = window.wpitravelTripEditor || {};

export default function OverviewTab( { meta, set } ) {
	const duration = meta._wpitravel_duration || {
		days: 0,
		nights: 0,
		hours: 0,
	};
	const groupSize = meta._wpitravel_group_size || { min: 0, max: 0 };

	return (
		<>
			<div className="wpitravel-grid">
				<TextField
					label={ __( 'Trip code', 'wpinterface-travel' ) }
					help={ __(
						'Your internal reference, shown on bookings and invoices.',
						'wpinterface-travel'
					) }
					value={ meta._wpitravel_trip_code || '' }
					onChange={ ( value ) =>
						set( '_wpitravel_trip_code', value )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Difficulty', 'wpinterface-travel' ) }
					value={ meta._wpitravel_difficulty || '' }
					options={ [
						{
							value: '',
							label: __( 'Not set', 'wpinterface-travel' ),
						},
						...difficulties,
					] }
					onChange={ ( value ) =>
						set( '_wpitravel_difficulty', value )
					}
				/>
			</div>

			<fieldset className="wpitravel-fieldset">
				<legend className="wpitravel-field-label">
					{ __( 'Duration', 'wpinterface-travel' ) }
				</legend>
				<div className="wpitravel-grid wpitravel-grid--3">
					<IntegerField
						label={ __( 'Days', 'wpinterface-travel' ) }
						value={ duration.days }
						onChange={ ( days ) =>
							set( '_wpitravel_duration', { ...duration, days } )
						}
					/>
					<IntegerField
						label={ __( 'Nights', 'wpinterface-travel' ) }
						value={ duration.nights }
						onChange={ ( nights ) =>
							set( '_wpitravel_duration', {
								...duration,
								nights,
							} )
						}
					/>
					<IntegerField
						label={ __( 'Hours', 'wpinterface-travel' ) }
						help={ __( 'For day trips.', 'wpinterface-travel' ) }
						value={ duration.hours }
						onChange={ ( hours ) =>
							set( '_wpitravel_duration', {
								...duration,
								hours,
							} )
						}
					/>
				</div>
			</fieldset>

			<fieldset className="wpitravel-fieldset">
				<legend className="wpitravel-field-label">
					{ __( 'Group size', 'wpinterface-travel' ) }
				</legend>
				<div className="wpitravel-grid wpitravel-grid--3">
					<IntegerField
						label={ __( 'Minimum', 'wpinterface-travel' ) }
						value={ groupSize.min }
						onChange={ ( min ) =>
							set( '_wpitravel_group_size', {
								...groupSize,
								min,
							} )
						}
					/>
					<IntegerField
						label={ __( 'Maximum', 'wpinterface-travel' ) }
						help={ __( '0 = no limit.', 'wpinterface-travel' ) }
						value={ groupSize.max }
						onChange={ ( max ) =>
							set( '_wpitravel_group_size', {
								...groupSize,
								max,
							} )
						}
					/>
					<IntegerField
						label={ __( 'Minimum age', 'wpinterface-travel' ) }
						help={ __( '0 = all ages.', 'wpinterface-travel' ) }
						value={ meta._wpitravel_min_age || 0 }
						onChange={ ( value ) =>
							set( '_wpitravel_min_age', value )
						}
					/>
				</div>
			</fieldset>

			<TextField
				label={ __( 'Meeting point', 'wpinterface-travel' ) }
				value={ meta._wpitravel_meeting_point || '' }
				onChange={ ( value ) =>
					set( '_wpitravel_meeting_point', value )
				}
			/>

			<fieldset className="wpitravel-fieldset">
				<legend className="wpitravel-field-label">
					{ __( 'Pickup points', 'wpinterface-travel' ) }
				</legend>
				<p className="description">
					{ __(
						'When you add pickup points, customers choose one when they book. The voucher, emails ({pickup_point}) and the traveler list show it.',
						'wpinterface-travel'
					) }
				</p>
				<Repeater
					items={ meta._wpitravel_pickups || [] }
					onChange={ ( items ) => set( '_wpitravel_pickups', items ) }
					newItem={ () => ( {
						key: '',
						name: '',
						time: '',
						note: '',
					} ) }
					addLabel={ __( 'Add pickup point', 'wpinterface-travel' ) }
					emptyText={ __(
						'No pickup points: travelers come to the meeting point.',
						'wpinterface-travel'
					) }
					itemLabel={ ( point, index ) =>
						point.name ||
						sprintf(
							/* translators: %d: pickup point number. */
							__( 'Pickup point %d', 'wpinterface-travel' ),
							index + 1
						)
					}
					renderItem={ ( point, update ) => (
						<div className="wpitravel-grid wpitravel-grid--3">
							<TextField
								label={ __( 'Place', 'wpinterface-travel' ) }
								value={ point.name }
								onChange={ ( name ) => update( { name } ) }
							/>
							<TextField
								label={ __( 'Time', 'wpinterface-travel' ) }
								help={ __(
									'24-hour, e.g. 06:30',
									'wpinterface-travel'
								) }
								value={ point.time }
								onChange={ ( time ) => update( { time } ) }
							/>
							<TextField
								label={ __( 'Note', 'wpinterface-travel' ) }
								value={ point.note }
								onChange={ ( note ) => update( { note } ) }
							/>
						</div>
					) }
				/>
			</fieldset>

			<StringList
				label={ __( 'Highlights', 'wpinterface-travel' ) }
				items={ meta._wpitravel_highlights || [] }
				onChange={ ( items ) => set( '_wpitravel_highlights', items ) }
				addLabel={ __( 'Add highlight', 'wpinterface-travel' ) }
				placeholder={ __(
					'e.g. Sunrise view from the summit',
					'wpinterface-travel'
				) }
			/>
		</>
	);
}
