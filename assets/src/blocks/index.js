/**
 * Block editor side of the plugin's blocks. Every block is rendered by PHP;
 * the editor shows a server-side preview plus settings in the sidebar.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
	CheckboxControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import trips from '../../../blocks/trips/block.json';
import tripSearch from '../../../blocks/trip-search/block.json';
import tripPrice from '../../../blocks/trip-price/block.json';
import tripFacts from '../../../blocks/trip-facts/block.json';
import tripHighlights from '../../../blocks/trip-highlights/block.json';
import tripItinerary from '../../../blocks/trip-itinerary/block.json';
import tripIncludes from '../../../blocks/trip-includes/block.json';
import tripFaqs from '../../../blocks/trip-faqs/block.json';
import tripGallery from '../../../blocks/trip-gallery/block.json';
import tripMap from '../../../blocks/trip-map/block.json';
import bookingForm from '../../../blocks/booking-form/block.json';
import tripReviews from '../../../blocks/trip-reviews/block.json';
import tripEnquiry from '../../../blocks/trip-enquiry/block.json';

import './editor.scss';

const sorts = window.wpitravelBlocks?.sorts || [];

/**
 * Server-rendered preview, with links and forms disabled.
 *
 * @param {Object} props            Props.
 * @param {string} props.name       Block name.
 * @param {Object} props.attributes Attributes.
 * @param {Object} props.context    Block context.
 */
function Preview( { name, attributes, context } ) {
	return (
		<Disabled>
			<ServerSideRender
				block={ name }
				attributes={ attributes }
				urlQueryArgs={
					context?.postId ? { post_id: context.postId } : {}
				}
				skipBlockSupportAttributes
			/>
		</Disabled>
	);
}

/**
 * Trip ID setting shared by all trip section blocks.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 */
function TripPicker( { attributes, setAttributes } ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type="number"
			min={ 0 }
			label={ __( 'Trip ID', 'wpinterface-travel' ) }
			help={ __(
				'Leave at 0 to show the trip of the page it is on.',
				'wpinterface-travel'
			) }
			value={ attributes.tripId || '' }
			onChange={ ( value ) =>
				setAttributes( { tripId: parseInt( value, 10 ) || 0 } )
			}
		/>
	);
}

const sectionEdit = ( extra ) =>
	function Edit( props ) {
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody title={ __( 'Trip', 'wpinterface-travel' ) }>
						<TripPicker { ...props } />
						{ extra?.( props ) }
					</PanelBody>
				</InspectorControls>
				<Preview { ...props } />
			</div>
		);
	};

registerBlockType( trips, {
	edit: function TripsEdit( props ) {
		const { attributes, setAttributes } = props;
		const text = ( key, label, help ) => (
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				help={ help }
				value={ attributes[ key ] }
				onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
			/>
		);

		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody title={ __( 'Trips', 'wpinterface-travel' ) }>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Use the page’s trips',
								'wpinterface-travel'
							) }
							help={ __(
								'On trip archive and destination pages, list the trips of that page.',
								'wpinterface-travel'
							) }
							checked={ attributes.inherit }
							onChange={ ( inherit ) =>
								setAttributes( { inherit } )
							}
						/>
						{ ! attributes.inherit && (
							<>
								<RangeControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Number of trips',
										'wpinterface-travel'
									) }
									min={ 1 }
									max={ 48 }
									value={ attributes.number }
									onChange={ ( number ) =>
										setAttributes( { number } )
									}
								/>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Sort by',
										'wpinterface-travel'
									) }
									options={ sorts }
									value={ attributes.sort }
									onChange={ ( sort ) =>
										setAttributes( { sort } )
									}
								/>
								{ text(
									'destination',
									__(
										'Destination (slug)',
										'wpinterface-travel'
									)
								) }
								{ text(
									'activity',
									__(
										'Activity (slug)',
										'wpinterface-travel'
									)
								) }
								{ text(
									'tripType',
									__(
										'Trip type (slug)',
										'wpinterface-travel'
									)
								) }
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Only these trips (IDs)',
										'wpinterface-travel'
									) }
									help={ __(
										'Comma-separated, in the order to show them.',
										'wpinterface-travel'
									) }
									value={ attributes.ids.join( ',' ) }
									onChange={ ( value ) =>
										setAttributes( {
											ids: value
												.split( ',' )
												.map( ( id ) =>
													parseInt( id, 10 )
												)
												.filter( ( id ) => id > 0 ),
										} )
									}
								/>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Follow the search form',
										'wpinterface-travel'
									) }
									help={ __(
										'Apply filters from a Trip Search block on this page.',
										'wpinterface-travel'
									) }
									checked={ attributes.filters }
									onChange={ ( filters ) =>
										setAttributes( { filters } )
									}
								/>
							</>
						) }
					</PanelBody>
					<PanelBody title={ __( 'Display', 'wpinterface-travel' ) }>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Layout', 'wpinterface-travel' ) }
							options={ [
								{
									value: 'grid',
									label: __( 'Grid', 'wpinterface-travel' ),
								},
								{
									value: 'list',
									label: __( 'List', 'wpinterface-travel' ),
								},
							] }
							value={ attributes.layout }
							onChange={ ( layout ) =>
								setAttributes( { layout } )
							}
						/>
						{ 'grid' === attributes.layout && (
							<RangeControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Columns', 'wpinterface-travel' ) }
								min={ 1 }
								max={ 4 }
								value={ attributes.columns }
								onChange={ ( columns ) =>
									setAttributes( { columns } )
								}
							/>
						) }
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Price', 'wpinterface-travel' ) }
							checked={ attributes.showPrice }
							onChange={ ( showPrice ) =>
								setAttributes( { showPrice } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Rating', 'wpinterface-travel' ) }
							checked={ attributes.showRating }
							onChange={ ( showRating ) =>
								setAttributes( { showRating } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Excerpt', 'wpinterface-travel' ) }
							checked={ attributes.showExcerpt }
							onChange={ ( showExcerpt ) =>
								setAttributes( { showExcerpt } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<Preview { ...props } />
			</div>
		);
	},
} );

const searchFields = () => [
	{ value: 'q', label: __( 'Keyword', 'wpinterface-travel' ) },
	{ value: 'destination', label: __( 'Destination', 'wpinterface-travel' ) },
	{ value: 'activity', label: __( 'Activity', 'wpinterface-travel' ) },
	{ value: 'trip_type', label: __( 'Trip type', 'wpinterface-travel' ) },
	{ value: 'difficulty', label: __( 'Difficulty', 'wpinterface-travel' ) },
	{ value: 'duration', label: __( 'Days', 'wpinterface-travel' ) },
	{ value: 'price', label: __( 'Price', 'wpinterface-travel' ) },
	{ value: 'sort', label: __( 'Sort', 'wpinterface-travel' ) },
];

registerBlockType( tripSearch, {
	edit: function TripSearchEdit( props ) {
		const { attributes, setAttributes } = props;

		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody title={ __( 'Fields', 'wpinterface-travel' ) }>
						{ searchFields().map( ( field ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ field.value }
								label={ field.label }
								checked={ attributes.fields.includes(
									field.value
								) }
								onChange={ ( checked ) =>
									setAttributes( {
										fields: checked
											? [
													...attributes.fields,
													field.value,
											  ]
											: attributes.fields.filter(
													( f ) => f !== field.value
											  ),
									} )
								}
							/>
						) ) }
					</PanelBody>
					<PanelBody title={ __( 'Results', 'wpinterface-travel' ) }>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Show results on',
								'wpinterface-travel'
							) }
							options={ [
								{
									value: 'archive',
									label: __(
										'The trips page',
										'wpinterface-travel'
									),
								},
								{
									value: 'current',
									label: __(
										'This page (with a Trips block that follows the search form)',
										'wpinterface-travel'
									),
								},
							] }
							value={ attributes.target }
							onChange={ ( target ) =>
								setAttributes( { target } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Button text', 'wpinterface-travel' ) }
							value={ attributes.buttonText }
							placeholder={ __(
								'Find trips',
								'wpinterface-travel'
							) }
							onChange={ ( buttonText ) =>
								setAttributes( { buttonText } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<Preview { ...props } />
			</div>
		);
	},
} );

[
	tripPrice,
	tripFacts,
	tripHighlights,
	tripIncludes,
	tripFaqs,
	tripGallery,
	tripMap,
	bookingForm,
	tripReviews,
	tripEnquiry,
].forEach( ( metadata ) =>
	registerBlockType( metadata, { edit: sectionEdit() } )
);

registerBlockType( tripItinerary, {
	edit: sectionEdit( ( { attributes, setAttributes } ) => (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ __( 'Expand all days', 'wpinterface-travel' ) }
			checked={ attributes.open }
			onChange={ ( open ) => setAttributes( { open } ) }
		/>
	) ),
} );
