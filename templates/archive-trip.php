<?php
/**
 * Trip archive and destination/activity/trip type pages for classic themes.
 *
 * Override: copy to yourtheme/wpitravel/archive-trip.php.
 *
 * @package WPInterface\Travel
 */

use WPInterface\Travel\Frontend\Parts;
use WPInterface\Travel\Frontend\Templates;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="wpitravel-page wpitravel-page--archive">
	<header class="wpitravel-archive__header">
		<?php if ( is_tax() ) : ?>
			<h1 class="wpitravel-archive__title"><?php single_term_title(); ?></h1>
			<?php the_archive_description( '<div class="wpitravel-archive__description">', '</div>' ); ?>
		<?php else : ?>
			<h1 class="wpitravel-archive__title"><?php post_type_archive_title(); ?></h1>
		<?php endif; ?>
	</header>

	<?php
	// On a destination page, the destination filter is implied by the page.
	$wpitravel_fields = array( 'q', 'destination', 'activity', 'trip_type', 'duration', 'price', 'sort' );

	if ( is_tax( 'wpitravel_destination' ) ) {
		$wpitravel_fields = array_diff( $wpitravel_fields, array( 'destination' ) );
	} elseif ( is_tax( 'wpitravel_activity' ) ) {
		$wpitravel_fields = array_diff( $wpitravel_fields, array( 'activity' ) );
	} elseif ( is_tax( 'wpitravel_trip_type' ) ) {
		$wpitravel_fields = array_diff( $wpitravel_fields, array( 'trip_type' ) );
	}

	Templates::output(
		Parts::search(
			array(
				'fields' => array_values( $wpitravel_fields ),
				'target' => 'current',
			)
		)
	);
	Templates::output(
		Parts::trips(
			array(
				'inherit' => true,
				'columns' => 3,
			)
		)
	);
	?>
</main>
<?php
get_footer();
