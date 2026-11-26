/**
 * Switches between the list, a booking and the new booking form. The view
 * is kept in the URL (?booking=12, ?action=new), so the browser's back
 * button and bookmarks work.
 */
import { useEffect, useState } from '@wordpress/element';
import BookingsList from './BookingsList';
import BookingDetail from './BookingDetail';
import NewBooking from './NewBooking';
import { screenUrl } from './utils';

const readView = () => {
	const params = new URLSearchParams( window.location.search );
	const booking = parseInt( params.get( 'booking' ), 10 );

	if ( booking > 0 ) {
		return { name: 'booking', id: booking };
	}

	return { name: params.get( 'action' ) === 'new' ? 'new' : 'list' };
};

export default function BookingsApp( { config } ) {
	const [ view, setView ] = useState( readView );

	useEffect( () => {
		const onPop = () => setView( readView() );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	const navigate = ( params ) => {
		window.history.pushState( {}, '', screenUrl( params ) );
		setView( readView() );
		window.scrollTo( 0, 0 );
	};

	if ( view.name === 'booking' ) {
		return (
			<BookingDetail
				key={ view.id }
				id={ view.id }
				config={ config }
				navigate={ navigate }
			/>
		);
	}

	if ( view.name === 'new' && config.canManage ) {
		return <NewBooking config={ config } navigate={ navigate } />;
	}

	return <BookingsList config={ config } navigate={ navigate } />;
}
