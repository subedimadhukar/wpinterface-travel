<?php
/**
 * Emails screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Emails\Mailer;
use WPInterface\Travel\Emails\Tags;
use WPInterface\Travel\Emails\Templates;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Travel → Emails: sender settings and every template, with preview and
 * "send test". Saves through POST /settings.
 */
final class EmailsPage {

	public const SLUG = 'wpitravel-emails';

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the submenu.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Emails', 'wpinterface-travel' ),
			__( 'Emails', 'wpinterface-travel' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the app.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$templates = array();

		foreach ( Templates::definitions() as $id => $definition ) {
			$templates[] = array(
				'id'          => $id,
				'title'       => $definition['title'],
				'recipient'   => $definition['recipient'],
				'description' => $definition['description'],
				'tags'        => Tags::describe( $definition['context'] ),
				'defaults'    => array(
					'subject' => $definition['subject'],
					'body'    => $definition['body'],
				),
			);
		}

		Assets::enqueue(
			'emails',
			'wpitravelEmails',
			array(
				'emails'      => (array) wpitravel()->options()->get( 'emails', array() ),
				'templates'   => $templates,
				'adminEmails' => implode( ', ', Mailer::admin_recipients() ),
				'siteName'    => get_bloginfo( 'name' ),
				'userEmail'   => wp_get_current_user()->user_email,
			)
		);
	}

	/**
	 * Page shell.
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Emails', 'wpinterface-travel' ) . '</h1>';

		if ( ! wp_script_is( 'wpitravel-emails', 'enqueued' ) ) {
			echo wp_kses_post( Assets::missing_build_notice() );
		}

		echo '<div id="wpitravel-emails"></div></div>';
	}
}
