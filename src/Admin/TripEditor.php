<?php
/**
 * Trip details box in the block editor.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WP_Post;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Trip details" box under the content.
 *
 * The box is a React app that edits the trip's meta through the block
 * editor's data store, so details save with the post's Save/Update button
 * and need no separate form.
 */
final class TripEditor {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . PostTypes::TRIP, array( $this, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the box.
	 */
	public function add_box(): void {
		add_meta_box(
			'wpitravel-trip-details',
			__( 'Trip details', 'wpinterface-travel' ),
			array( $this, 'render' ),
			PostTypes::TRIP,
			'normal',
			'high',
			array( '__back_compat_meta_box' => false )
		);
	}

	/**
	 * Loads the editor app on trip edit screens.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || PostTypes::TRIP !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_media();

		$difficulties = array();

		foreach ( TripMeta::difficulties() as $value => $label ) {
			$difficulties[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$meals = array();

		foreach ( TripMeta::meals() as $value => $label ) {
			$meals[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$code = (string) wpitravel()->options()->get( 'currency.code', 'USD' );

		Assets::enqueue(
			'trip-editor',
			'wpitravelTripEditor',
			array(
				'difficulties' => $difficulties,
				'meals'        => $meals,
				'currency'     => array(
					'code'     => $code,
					'symbol'   => Currencies::all()[ $code ]['symbol'] ?? $code,
					'decimals' => (int) wpitravel()->options()->get( 'currency.decimals', 2 ),
				),
				'today'        => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Box shell; the app mounts into #wpitravel-trip-editor.
	 *
	 * @param WP_Post $post Trip.
	 */
	public function render( $post ): void {
		if ( $post instanceof WP_Post && ! use_block_editor_for_post( $post ) ) {
			echo '<p>' . esc_html__( 'Trip details need the block editor. Disable the Classic Editor for trips to edit them.', 'wpinterface-travel' ) . '</p>';
			return;
		}

		if ( ! wp_script_is( 'wpitravel-trip-editor', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
			return;
		}

		echo '<div id="wpitravel-trip-editor"></div>';
	}
}
