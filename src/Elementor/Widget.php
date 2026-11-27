<?php
/**
 * Base for the Elementor widgets.
 *
 * Loaded only by Elementor's "widgets/register" hook, when Elementor's
 * Widget_Base exists.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Frontend\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * A widget that prints one of the plugin's shortcodes.
 */
abstract class Widget extends Widget_Base {

	/**
	 * Shortcode it prints.
	 */
	abstract protected function shortcode(): string;

	/**
	 * Shortcode attributes from the widget's settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	abstract protected function atts( array $settings ): array;

	/**
	 * Widget categories.
	 *
	 * @return list<string>
	 */
	public function get_categories(): array {
		return array( 'wpinterface-travel' );
	}

	/**
	 * Search keywords.
	 *
	 * @return list<string>
	 */
	public function get_keywords(): array {
		return array( 'travel', 'trip', 'tour' );
	}

	/**
	 * Prices, seats and reviews change, so Elementor must not cache the output.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * No extra wrapper: the plugin's HTML is the widget.
	 */
	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	/**
	 * Prints the shortcode's HTML through the plugin's allow-list.
	 */
	protected function render(): void {
		global $shortcode_tags;

		$tag = $this->shortcode();

		if ( ! isset( $shortcode_tags[ $tag ] ) || ! is_callable( $shortcode_tags[ $tag ] ) ) {
			return;
		}

		$html = (string) call_user_func( $shortcode_tags[ $tag ], $this->atts( (array) $this->get_settings_for_display() ), '', $tag );

		if ( '' === $html && $this->in_editor() ) {
			$html = '<p class="wpitravel-elementor-empty">' . esc_html__( 'Shows the trip\'s details on a trip page, or pick a trip in the widget settings.', 'wpinterface-travel' ) . '</p>';
		}

		Templates::output( $html );
	}

	/**
	 * Adds a "Trip" choice: the current trip, or a chosen one.
	 */
	protected function trip_control(): void {
		$this->add_control(
			'trip',
			array(
				'label'       => __( 'Trip', 'wpinterface-travel' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => self::trips(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Leave empty on trip pages and trip templates to show the current trip.', 'wpinterface-travel' ),
			)
		);
	}

	/**
	 * The chosen trip ID, or "" for the current trip.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	protected static function trip_id( array $settings ): string {
		$id = absint( $settings['trip'] ?? 0 );

		return $id ? (string) $id : '';
	}

	/**
	 * "yes" or "no" from a switcher.
	 *
	 * @param mixed $value Switcher value.
	 */
	protected static function yes( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Published trips for the "Trip" choice.
	 *
	 * @return array<string, string>
	 */
	protected static function trips(): array {
		$options = array( '' => __( 'Current trip', 'wpinterface-travel' ) );
		$posts   = get_posts(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$options[ (string) $post->ID ] = get_the_title( $post );
		}

		return $options;
	}

	/**
	 * Whether Elementor's editor or preview is drawing the widget.
	 */
	private function in_editor(): bool {
		$plugin = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;

		return $plugin && ( ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) || ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() ) );
	}
}
