<?php
/**
 * Trip search and filters. Submits with GET, so results can be shared and bookmarked.
 *
 * Override: yourtheme/wpitravel/parts/search-form.php
 *
 * @package WPInterface\Travel
 *
 * @var array{fields: list<string>, terms: array<string, list<array{0: string, 1: string, 2: int}>>, values: array<string, mixed>, action: string, button: string, sorts: array<string, string>, difficulties: array<string, string>} $args
 */

defined( 'ABSPATH' ) || exit;

$wpitravel_id     = wp_unique_id( 'wpitravel-search-' );
$wpitravel_has    = static fn( string $field ): bool => in_array( $field, $args['fields'], true );
$wpitravel_labels = array(
	'destination' => __( 'Destination', 'wpinterface-travel' ),
	'activity'    => __( 'Activity', 'wpinterface-travel' ),
	'trip_type'   => __( 'Trip type', 'wpinterface-travel' ),
);
$wpitravel_number = static fn( $value ): string => null === $value ? '' : (string) ( 0 + $value );
?>
<form class="wpitravel-search" role="search" method="get" action="<?php echo esc_url( $args['action'] ); ?>">
	<?php if ( $wpitravel_has( 'q' ) ) : ?>
		<p class="wpitravel-search__field wpitravel-search__field--wide">
			<label for="<?php echo esc_attr( $wpitravel_id ); ?>-q"><?php esc_html_e( 'Search trips', 'wpinterface-travel' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $wpitravel_id ); ?>-q" name="trip_q" value="<?php echo esc_attr( $args['values']['q'] ); ?>" placeholder="<?php esc_attr_e( 'Where do you want to go?', 'wpinterface-travel' ); ?>">
		</p>
	<?php endif; ?>

	<?php foreach ( $args['terms'] as $wpitravel_field => $wpitravel_terms ) : ?>
		<?php if ( $wpitravel_terms ) : ?>
			<p class="wpitravel-search__field">
				<label for="<?php echo esc_attr( $wpitravel_id . '-' . $wpitravel_field ); ?>"><?php echo esc_html( $wpitravel_labels[ $wpitravel_field ] ); ?></label>
				<select id="<?php echo esc_attr( $wpitravel_id . '-' . $wpitravel_field ); ?>" name="<?php echo esc_attr( $wpitravel_field ); ?>">
					<option value=""><?php esc_html_e( 'Any', 'wpinterface-travel' ); ?></option>
					<?php foreach ( $wpitravel_terms as $wpitravel_term ) : ?>
						<option value="<?php echo esc_attr( $wpitravel_term[0] ); ?>" <?php selected( $args['values'][ $wpitravel_field ], $wpitravel_term[0] ); ?>><?php echo esc_html( ( $wpitravel_term[2] ? '— ' : '' ) . $wpitravel_term[1] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( $wpitravel_has( 'difficulty' ) ) : ?>
		<p class="wpitravel-search__field">
			<label for="<?php echo esc_attr( $wpitravel_id ); ?>-difficulty"><?php esc_html_e( 'Difficulty', 'wpinterface-travel' ); ?></label>
			<select id="<?php echo esc_attr( $wpitravel_id ); ?>-difficulty" name="difficulty">
				<option value=""><?php esc_html_e( 'Any', 'wpinterface-travel' ); ?></option>
				<?php foreach ( $args['difficulties'] as $wpitravel_key => $wpitravel_label ) : ?>
					<option value="<?php echo esc_attr( $wpitravel_key ); ?>" <?php selected( $args['values']['difficulty'], $wpitravel_key ); ?>><?php echo esc_html( $wpitravel_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>

	<?php if ( $wpitravel_has( 'duration' ) ) : ?>
		<fieldset class="wpitravel-search__field wpitravel-search__range">
			<legend><?php esc_html_e( 'Days', 'wpinterface-travel' ); ?></legend>
			<div class="wpitravel-search__inputs">
				<label class="screen-reader-text" for="<?php echo esc_attr( $wpitravel_id ); ?>-min-days"><?php esc_html_e( 'Minimum days', 'wpinterface-travel' ); ?></label>
				<input type="number" min="0" inputmode="numeric" id="<?php echo esc_attr( $wpitravel_id ); ?>-min-days" name="min_days" value="<?php echo esc_attr( $wpitravel_number( $args['values']['min_days'] ) ); ?>" placeholder="<?php esc_attr_e( 'Min', 'wpinterface-travel' ); ?>">
				<span aria-hidden="true">–</span>
				<label class="screen-reader-text" for="<?php echo esc_attr( $wpitravel_id ); ?>-max-days"><?php esc_html_e( 'Maximum days', 'wpinterface-travel' ); ?></label>
				<input type="number" min="0" inputmode="numeric" id="<?php echo esc_attr( $wpitravel_id ); ?>-max-days" name="max_days" value="<?php echo esc_attr( $wpitravel_number( $args['values']['max_days'] ) ); ?>" placeholder="<?php esc_attr_e( 'Max', 'wpinterface-travel' ); ?>">
			</div>
		</fieldset>
	<?php endif; ?>

	<?php if ( $wpitravel_has( 'price' ) ) : ?>
		<fieldset class="wpitravel-search__field wpitravel-search__range">
			<legend><?php esc_html_e( 'Price per person', 'wpinterface-travel' ); ?></legend>
			<div class="wpitravel-search__inputs">
				<label class="screen-reader-text" for="<?php echo esc_attr( $wpitravel_id ); ?>-min-price"><?php esc_html_e( 'Minimum price', 'wpinterface-travel' ); ?></label>
				<input type="number" min="0" inputmode="decimal" id="<?php echo esc_attr( $wpitravel_id ); ?>-min-price" name="min_price" value="<?php echo esc_attr( $wpitravel_number( $args['values']['min_price'] ) ); ?>" placeholder="<?php esc_attr_e( 'Min', 'wpinterface-travel' ); ?>">
				<span aria-hidden="true">–</span>
				<label class="screen-reader-text" for="<?php echo esc_attr( $wpitravel_id ); ?>-max-price"><?php esc_html_e( 'Maximum price', 'wpinterface-travel' ); ?></label>
				<input type="number" min="0" inputmode="decimal" id="<?php echo esc_attr( $wpitravel_id ); ?>-max-price" name="max_price" value="<?php echo esc_attr( $wpitravel_number( $args['values']['max_price'] ) ); ?>" placeholder="<?php esc_attr_e( 'Max', 'wpinterface-travel' ); ?>">
			</div>
		</fieldset>
	<?php endif; ?>

	<?php if ( $wpitravel_has( 'sort' ) ) : ?>
		<p class="wpitravel-search__field">
			<label for="<?php echo esc_attr( $wpitravel_id ); ?>-sort"><?php esc_html_e( 'Sort by', 'wpinterface-travel' ); ?></label>
			<select id="<?php echo esc_attr( $wpitravel_id ); ?>-sort" name="sort">
				<?php foreach ( $args['sorts'] as $wpitravel_key => $wpitravel_label ) : ?>
					<option value="<?php echo esc_attr( $wpitravel_key ); ?>" <?php selected( $args['values']['sort'], $wpitravel_key ); ?>><?php echo esc_html( $wpitravel_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>

	<p class="wpitravel-search__actions">
		<button type="submit" class="wpitravel-search__submit wp-element-button"><?php echo esc_html( $args['button'] ); ?></button>
	</p>
</form>
