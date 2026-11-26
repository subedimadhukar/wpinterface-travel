/**
 * Coloured status label.
 *
 * @param {Object} props
 * @param {string} props.status Status key (booking or payment status).
 * @param {string} props.label  Label.
 */
export default function StatusBadge( { status, label } ) {
	return (
		<span className={ `wpitravel-badge wpitravel-badge--${ status }` }>
			{ label }
		</span>
	);
}
