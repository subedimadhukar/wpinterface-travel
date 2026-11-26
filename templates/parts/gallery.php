<?php
/**
 * Photo gallery. Each photo links to the full-size image.
 *
 * Override: yourtheme/wpitravel/parts/gallery.php
 *
 * @package WPInterface\Travel
 *
 * @var array{images: list<int>, title: string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wpitravel-gallery wpitravel-gallery--<?php echo esc_attr( (string) min( 5, count( $args['images'] ) ) ); ?>">
	<?php foreach ( $args['images'] as $wpitravel_index => $wpitravel_image ) : ?>
		<a class="wpitravel-gallery__item" href="<?php echo esc_url( (string) wp_get_attachment_image_url( $wpitravel_image, 'full' ) ); ?>">
			<?php
			echo wp_get_attachment_image(
				$wpitravel_image,
				0 === $wpitravel_index ? 'large' : 'medium_large',
				false,
				array(
					'loading' => 0 === $wpitravel_index ? 'eager' : 'lazy',
					'alt'     => (string) get_post_meta( $wpitravel_image, '_wp_attachment_image_alt', true ) ? (string) get_post_meta( $wpitravel_image, '_wp_attachment_image_alt', true ) : $args['title'],
				)
			);
			?>
		</a>
	<?php endforeach; ?>
</div>
