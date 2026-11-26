<?php
/**
 * Frequently asked questions.
 *
 * Override: yourtheme/wpitravel/parts/faqs.php
 *
 * @package WPInterface\Travel
 *
 * @var array{faqs: list<array{question: string, answer: string}>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-faqs">
	<h2 class="wpitravel-section__title"><?php esc_html_e( 'Questions', 'wpinterface-travel' ); ?></h2>
	<?php foreach ( $args['faqs'] as $wpitravel_faq ) : ?>
		<details class="wpitravel-faqs__item">
			<summary><?php echo esc_html( $wpitravel_faq['question'] ); ?></summary>
			<div class="wpitravel-faqs__answer"><?php echo wp_kses_post( wpautop( $wpitravel_faq['answer'] ) ); ?></div>
		</details>
	<?php endforeach; ?>
</section>
