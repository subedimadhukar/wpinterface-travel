/**
 * Bar chart of money received per day or month. Plain SVG; a table with
 * the same numbers is available to screen readers.
 */
import { __ } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { useEffect, useRef, useState } from '@wordpress/element';

const HEIGHT = 220;
const PAD = { top: 16, right: 8, bottom: 28, left: 8 };

export default function RevenueChart( {
	series,
	interval,
	money,
	dateFormat,
} ) {
	const ref = useRef();
	const [ WIDTH, setWidth ] = useState( 720 );
	const hasData = series.some( ( point ) => parseFloat( point.revenue ) > 0 );

	// Draw at the real width, so text keeps its size on any screen.
	useEffect( () => {
		const node = ref.current;

		if ( ! node || ! window.ResizeObserver ) {
			return;
		}

		const observer = new window.ResizeObserver( ( [ entry ] ) =>
			setWidth( Math.max( 280, Math.round( entry.contentRect.width ) ) )
		);
		observer.observe( node );
		return () => observer.disconnect();
	}, [ hasData ] );

	const values = series.map( ( point ) => parseFloat( point.revenue ) || 0 );
	const max = Math.max( ...values, 0 );
	const label = ( point ) =>
		interval === 'month'
			? dateI18n( 'M Y', `${ point.date }-01T12:00:00` )
			: dateI18n( dateFormat, `${ point.date }T12:00:00` );

	if ( max <= 0 ) {
		return (
			<p className="wpitravel-dashboard__empty">
				{ __( 'No payments in this period.', 'wpinterface-travel' ) }
			</p>
		);
	}

	const plotWidth = WIDTH - PAD.left - PAD.right;
	const plotHeight = HEIGHT - PAD.top - PAD.bottom;
	const step = plotWidth / series.length;
	const barWidth = Math.max( 2, Math.min( 40, step * 0.7 ) );
	const anchor = ( index ) => {
		if ( index === 0 ) {
			return 'start';
		}
		return index === series.length - 1 ? 'end' : 'middle';
	};
	const ticks = [
		0,
		Math.floor( ( series.length - 1 ) / 2 ),
		series.length - 1,
	];

	return (
		<figure className="wpitravel-chart" ref={ ref }>
			<svg
				viewBox={ `0 0 ${ WIDTH } ${ HEIGHT }` }
				role="img"
				aria-label={ __(
					'Money received over the period',
					'wpinterface-travel'
				) }
			>
				<line
					className="wpitravel-chart__grid"
					x1={ PAD.left }
					x2={ WIDTH - PAD.right }
					y1={ PAD.top }
					y2={ PAD.top }
				/>
				<text
					className="wpitravel-chart__label"
					x={ WIDTH - PAD.right }
					y={ PAD.top - 4 }
					textAnchor="end"
				>
					{ money( max ) }
				</text>
				<line
					className="wpitravel-chart__axis"
					x1={ PAD.left }
					x2={ WIDTH - PAD.right }
					y1={ PAD.top + plotHeight }
					y2={ PAD.top + plotHeight }
				/>
				{ series.map( ( point, index ) => {
					const value = values[ index ];
					const height = ( value / max ) * plotHeight;
					const x = PAD.left + step * index + ( step - barWidth ) / 2;

					return (
						<rect
							key={ point.date }
							className="wpitravel-chart__bar"
							x={ x }
							y={ PAD.top + plotHeight - Math.max( 0, height ) }
							width={ barWidth }
							height={ Math.max( 0, height ) }
							rx={ Math.min( 3, barWidth / 3 ) }
						>
							<title>{ `${ label( point ) }: ${ money(
								value
							) }` }</title>
						</rect>
					);
				} ) }
				{ [ ...new Set( ticks ) ].map( ( index ) => (
					<text
						key={ index }
						className="wpitravel-chart__label"
						x={ PAD.left + step * index + step / 2 }
						y={ HEIGHT - 8 }
						textAnchor={ anchor( index ) }
					>
						{ label( series[ index ] ) }
					</text>
				) ) }
			</svg>
			<table className="screen-reader-text">
				<caption>
					{ __( 'Money received', 'wpinterface-travel' ) }
				</caption>
				<tbody>
					{ series.map( ( point, index ) => (
						<tr key={ point.date }>
							<th scope="row">{ label( point ) }</th>
							<td>{ money( values[ index ] ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</figure>
	);
}
