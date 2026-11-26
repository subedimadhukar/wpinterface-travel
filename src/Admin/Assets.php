<?php
/**
 * Admin script loader.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues a wp-scripts build entry with its generated dependencies.
 */
final class Assets {

	/**
	 * Enqueues assets/build/{entry}.js and its stylesheet when present.
	 *
	 * @param string               $entry    Entry name from webpack.config.js.
	 * @param string               $var_name Global JS variable for $data.
	 * @param array<string, mixed> $data       Data passed to the script.
	 * @param string[]             $style_deps Stylesheet dependencies; admin screens use wp-components,
	 *                                         the front-end widget none.
	 * @return bool False when the build is missing (run `npm run build`).
	 */
	public static function enqueue( string $entry, string $var_name = '', array $data = array(), array $style_deps = array( 'wp-components' ) ): bool {
		$asset_file = WPITRAVEL_PATH . 'assets/build/' . $entry . '.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return false;
		}

		$asset  = require $asset_file;
		$handle = 'wpitravel-' . $entry;

		wp_enqueue_script(
			$handle,
			WPITRAVEL_URL . 'assets/build/' . $entry . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( $handle, 'wpinterface-travel' );

		// wp-scripts writes CSS imported from a style.scss file to style-{entry}.css, plus an RTL copy.
		$css = 'assets/build/style-' . $entry . '.css';

		if ( is_readable( WPITRAVEL_PATH . $css ) ) {
			wp_enqueue_style( $handle, WPITRAVEL_URL . $css, $style_deps, $asset['version'] );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		if ( '' !== $var_name ) {
			wp_add_inline_script( $handle, 'var ' . $var_name . ' = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}

		return true;
	}

	/**
	 * Enqueues only the stylesheet of an entry (styles shared between entries
	 * are built into the first entry that uses them).
	 *
	 * @param string $entry Entry name.
	 */
	public static function enqueue_style( string $entry ): void {
		$css        = 'assets/build/style-' . $entry . '.css';
		$asset_file = WPITRAVEL_PATH . 'assets/build/' . $entry . '.asset.php';

		if ( ! is_readable( WPITRAVEL_PATH . $css ) || ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_style( 'wpitravel-' . $entry, WPITRAVEL_URL . $css, array(), $asset['version'] );
		wp_style_add_data( 'wpitravel-' . $entry, 'rtl', 'replace' );
	}

	/**
	 * Notice shown in place of a React screen when the build is missing.
	 */
	public static function missing_build_notice(): string {
		return '<div class="notice notice-error inline"><p>' . esc_html__( 'The plugin\'s scripts are missing. Reinstall the plugin, or run `npm run build` in a development copy.', 'wpinterface-travel' ) . '</p></div>';
	}
}
