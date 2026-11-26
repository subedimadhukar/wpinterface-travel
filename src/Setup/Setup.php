<?php
/**
 * Setup state.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the setup wizard was finished (or skipped), and the one-time
 * redirect to it after activation.
 */
final class Setup {

	public const DONE_OPTION     = 'wpitravel_setup_done';
	public const REDIRECT_OPTION = 'wpitravel_setup_redirect';

	/**
	 * Whether setup is finished or skipped.
	 */
	public static function is_done(): bool {
		return (bool) get_option( self::DONE_OPTION, false );
	}

	/**
	 * Marks setup finished.
	 */
	public static function complete(): void {
		update_option( self::DONE_OPTION, time(), false );
		delete_option( self::REDIRECT_OPTION );
	}

	/**
	 * Asks for the wizard to open after activation (first install only).
	 */
	public static function request_redirect(): void {
		if ( ! self::is_done() ) {
			update_option( self::REDIRECT_OPTION, 1, false );
		}
	}
}
