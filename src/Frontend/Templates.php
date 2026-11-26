<?php
/**
 * Template loading.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Admin\Assets;
use WPInterface\Travel\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Loads trip templates and parts, letting themes override them.
 *
 * Classic themes: a theme's own single-wpitravel_trip.php, archive-wpitravel_trip.php
 * or taxonomy-wpitravel_*.php wins; otherwise yourtheme/wpitravel/single-trip.php
 * or archive-trip.php; otherwise the plugin's templates.
 *
 * Parts (itinerary, facts, trip card…): yourtheme/wpitravel/parts/{name}.php,
 * else the plugin's templates/parts/{name}.php.
 *
 * Block themes use BlockTemplates instead.
 */
final class Templates {

	/**
	 * Whether a plugin page template (classic) is rendering this request.
	 *
	 * @var bool
	 */
	private static bool $plugin_template = false;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'template_include', array( $this, 'template_include' ), 20 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Renders a part to a string.
	 *
	 * @param string               $name Part name, e.g. "parts/itinerary".
	 * @param array<string, mixed> $args Data for the template (available as $args).
	 */
	public static function part( string $name, array $args = array() ): string {
		$name     = sanitize_file_name( str_replace( '/', '__', $name ) );
		$name     = str_replace( '__', '/', $name );
		$template = locate_template( 'wpitravel/' . $name . '.php' );

		if ( '' === $template ) {
			$template = WPITRAVEL_PATH . 'templates/' . $name . '.php';
		}

		/**
		 * Filters the file used for a template part.
		 *
		 * @param string $template Absolute path.
		 * @param string $name     Part name.
		 * @param array  $args     Data.
		 */
		$template = (string) apply_filters( 'wpitravel_template_part', $template, $name, $args );

		if ( ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Uses the plugin's page templates on classic themes without their own.
	 *
	 * @param string $template Template chosen by WordPress.
	 */
	public function template_include( $template ): string {
		$template = (string) $template;

		if ( wp_is_block_theme() ) {
			return $template;
		}

		if ( is_singular( PostTypes::TRIP ) ) {
			$theme_specific = array( 'single-' . PostTypes::TRIP . '.php' );
			$ours           = 'single-trip.php';
		} elseif ( is_post_type_archive( PostTypes::TRIP ) || is_tax( PostTypes::taxonomies() ) ) {
			$theme_specific = array( 'archive-' . PostTypes::TRIP . '.php' );

			foreach ( PostTypes::taxonomies() as $taxonomy ) {
				$theme_specific[] = 'taxonomy-' . $taxonomy . '.php';
			}

			$ours = 'archive-trip.php';
		} else {
			return $template;
		}

		// The theme has its own template for trips: respect it.
		if ( in_array( basename( $template ), $theme_specific, true ) || 0 === strpos( basename( $template ), 'taxonomy-wpitravel_' ) ) {
			return $template;
		}

		$override              = locate_template( 'wpitravel/' . $ours );
		self::$plugin_template = true;

		return '' !== $override ? $override : WPITRAVEL_PATH . 'templates/' . $ours;
	}

	/**
	 * HTML the plugin's parts may print: the post allow-list plus forms,
	 * responsive images, maps and the attributes the booking and search
	 * markup use. Parts escape every value; this list is the final check
	 * when a finished part is printed.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$global  = array(
			'class'       => true,
			'id'          => true,
			'style'       => true,
			'title'       => true,
			'role'        => true,
			'hidden'      => true,
			'tabindex'    => true,
			'lang'        => true,
			'dir'         => true,
			'aria-*'      => true,
			'data-*'      => true,
			'aria-hidden' => true,
			'aria-label'  => true,
		);
		$form    = array(
			'form'           => true,
			'formaction'     => true,
			'formmethod'     => true,
			'formnovalidate' => true,
			'formtarget'     => true,
			'autocapitalize' => true,
			'autocomplete'   => true,
			'disabled'       => true,
			'name'           => true,
			'required'       => true,
		);
		$allowed = wp_kses_allowed_html( 'post' );
		$extra   = array(
			'form'     => array(
				'action'         => true,
				'method'         => true,
				'novalidate'     => true,
				'autocomplete'   => true,
				'autocapitalize' => true,
				'name'           => true,
				'target'         => true,
			),
			'input'    => $form + array(
				'type'        => true,
				'value'       => true,
				'placeholder' => true,
				'min'         => true,
				'max'         => true,
				'step'        => true,
				'minlength'   => true,
				'maxlength'   => true,
				'pattern'     => true,
				'inputmode'   => true,
				'checked'     => true,
				'readonly'    => true,
				'size'        => true,
				'list'        => true,
			),
			'select'   => $form + array(
				'multiple' => true,
				'size'     => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
				'label'    => true,
			),
			'optgroup' => array(
				'label'    => true,
				'disabled' => true,
			),
			'textarea' => $form + array(
				'rows'        => true,
				'cols'        => true,
				'placeholder' => true,
				'minlength'   => true,
				'maxlength'   => true,
				'readonly'    => true,
			),
			'button'   => $form + array(
				'type'         => true,
				'value'        => true,
				'aria-pressed' => true,
			),
			'label'    => array( 'for' => true ),
			'fieldset' => array( 'disabled' => true ),
			'legend'   => array(),
			'output'   => array( 'for' => true ),
			'noscript' => array(),
			'details'  => array( 'open' => true ),
			'summary'  => array(),
			'time'     => array( 'datetime' => true ),
			'nav'      => array(),
			'iframe'   => array(
				'src'             => true,
				'title'           => true,
				'width'           => true,
				'height'          => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'sandbox'         => true,
			),
			'img'      => array(
				'srcset'        => true,
				'sizes'         => true,
				'decoding'      => true,
				'fetchpriority' => true,
				'loading'       => true,
			),
			'a'        => array(
				'target'   => true,
				'rel'      => true,
				'download' => true,
				'hreflang' => true,
			),
		);

		foreach ( $extra as $tag => $attributes ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $attributes, $global );
		}

		/**
		 * Filters the HTML the plugin's template parts may print.
		 *
		 * @param array $allowed Tags and attributes, as for wp_kses().
		 */
		$allowed = (array) apply_filters( 'wpitravel_allowed_html', $allowed );

		return $allowed;
	}

	/**
	 * Prints a finished part through the allow-list.
	 *
	 * @param string $html Part HTML.
	 */
	public static function output( string $html ): void {
		echo wp_kses( $html, self::allowed_html() );
	}

	/**
	 * Whether this page's template already places the trip parts (booking
	 * form, reviews…), so they must not also be appended to the content.
	 */
	public static function renders_parts(): bool {
		if ( self::$plugin_template ) {
			return true;
		}

		global $_wp_current_template_content;

		return wp_is_block_theme() && is_string( $_wp_current_template_content ) && false !== strpos( $_wp_current_template_content, '<!-- wp:wpitravel/' );
	}

	/**
	 * Marks the page as using a plugin template (tests, custom loaders).
	 *
	 * @param bool $value Value.
	 */
	public static function set_plugin_template( bool $value ): void {
		self::$plugin_template = $value;
	}

	/**
	 * Adds body classes on trip pages.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();

		if ( self::is_trip_page() ) {
			$classes[] = 'wpitravel';
			$classes[] = is_singular( PostTypes::TRIP ) ? 'wpitravel-single-trip' : 'wpitravel-trip-archive';
		}

		return $classes;
	}

	/**
	 * Loads the frontend stylesheet on trip pages.
	 */
	public function enqueue(): void {
		if ( self::is_trip_page() || self::content_uses_plugin() ) {
			self::enqueue_frontend_style();
		}
	}

	/**
	 * Whether the current page's content has a Travel block or shortcode,
	 * so the stylesheet can load in the head instead of the footer.
	 */
	private static function content_uses_plugin(): bool {
		$post = is_singular() ? get_queried_object() : null;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return false !== strpos( $post->post_content, '<!-- wp:wpitravel/' ) || false !== strpos( $post->post_content, '[wpitravel_' );
	}

	/**
	 * Loads the frontend stylesheet (trip pages, shortcodes). Styles enqueued
	 * after the head print in the footer.
	 */
	public static function enqueue_frontend_style(): void {
		if ( wp_style_is( 'wpitravel-frontend', 'registered' ) ) {
			wp_enqueue_style( 'wpitravel-frontend' );
		} else {
			Assets::enqueue_style( 'frontend' );
		}
	}

	/**
	 * Single trip, trip archive or trip taxonomy archive.
	 */
	public static function is_trip_page(): bool {
		return is_singular( PostTypes::TRIP ) || is_post_type_archive( PostTypes::TRIP ) || is_tax( PostTypes::taxonomies() );
	}
}
