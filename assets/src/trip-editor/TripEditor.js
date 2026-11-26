/**
 * Tabs of the trip details box.
 */
import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import useTripMeta from './useTripMeta';
import OverviewTab from './tabs/OverviewTab';
import PricingTab from './tabs/PricingTab';
import DatesTab from './tabs/DatesTab';
import ItineraryTab from './tabs/ItineraryTab';
import IncludesTab from './tabs/IncludesTab';
import FaqsTab from './tabs/FaqsTab';
import FactsTab from './tabs/FactsTab';
import GalleryTab from './tabs/GalleryTab';
import MapTab from './tabs/MapTab';

const TABS = [
	{
		name: 'overview',
		title: __( 'Overview', 'wpinterface-travel' ),
		Component: OverviewTab,
	},
	{
		name: 'pricing',
		title: __( 'Pricing', 'wpinterface-travel' ),
		Component: PricingTab,
	},
	{
		name: 'dates',
		title: __( 'Dates', 'wpinterface-travel' ),
		Component: DatesTab,
	},
	{
		name: 'itinerary',
		title: __( 'Itinerary', 'wpinterface-travel' ),
		Component: ItineraryTab,
	},
	{
		name: 'includes',
		title: __( 'Included / Excluded', 'wpinterface-travel' ),
		Component: IncludesTab,
	},
	{
		name: 'faqs',
		title: __( 'FAQs', 'wpinterface-travel' ),
		Component: FaqsTab,
	},
	{
		name: 'facts',
		title: __( 'Facts', 'wpinterface-travel' ),
		Component: FactsTab,
	},
	{
		name: 'gallery',
		title: __( 'Gallery', 'wpinterface-travel' ),
		Component: GalleryTab,
	},
	{
		name: 'map',
		title: __( 'Map', 'wpinterface-travel' ),
		Component: MapTab,
	},
];

export default function TripEditor() {
	const [ meta, set ] = useTripMeta();

	/**
	 * Lets Pro add tabs ({ name, title, Component }). Each Component gets
	 * meta and set( key, value ) for its own registered trip meta.
	 */
	const tabs = useMemo(
		() =>
			applyFilters( 'wpitravel.tripEditor.tabs', TABS ).filter(
				( tab ) => tab && tab.name && tab.Component
			),
		[]
	);

	return (
		<TabPanel className="wpitravel-trip-editor" tabs={ tabs }>
			{ ( tab ) => {
				const { Component } = tabs.find(
					( item ) => item.name === tab.name
				);
				return (
					<div className="wpitravel-trip-editor__panel">
						<Component meta={ meta } set={ set } />
					</div>
				);
			} }
		</TabPanel>
	);
}
