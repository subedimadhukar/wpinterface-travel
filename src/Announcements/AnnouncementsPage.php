<?php
/**
 * Announcement bar settings screen.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Announcements;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Trips → Announcement bar: messages, look, timing and where it shows, with
 * a live preview. The form is reusable: Pro edits its extra bars with it.
 */
final class AnnouncementsPage {

	public const SLUG = 'wpitravel-announcement';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_post_wpitravel_save_announcement', array( $this, 'save' ) );
	}

	/**
	 * Adds the page.
	 */
	public function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostTypes::TRIP,
			__( 'Announcement bar', 'wpinterface-travel' ),
			__( 'Announcement bar', 'wpinterface-travel' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'admin_print_scripts-' . $hook, array( self::class, 'assets' ) );
		}
	}

	/**
	 * Admin styles and the live preview script.
	 */
	public static function assets(): void {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wpitravel-announcement', WPITRAVEL_URL . 'assets/css/announcement-bar.css', array(), WPITRAVEL_VERSION );
		wp_enqueue_style( 'wpitravel-announcement-admin', WPITRAVEL_URL . 'assets/css/announcement-admin.css', array(), WPITRAVEL_VERSION );
		wp_enqueue_script( 'wpitravel-announcement-admin', WPITRAVEL_URL . 'assets/js/announcement-admin.js', array( 'wp-color-picker' ), WPITRAVEL_VERSION, true );
		wp_add_inline_script(
			'wpitravel-announcement-admin',
			'window.wpitravelBarSchemes = ' . wp_json_encode( AnnouncementBar::schemes(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
	}

	/**
	 * Saves the form.
	 */
	public function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change the announcement bar.', 'wpinterface-travel' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'wpitravel_save_announcement' );

		$raw = isset( $_POST['wpitravel_bar'] ) && is_array( $_POST['wpitravel_bar'] ) ? wp_unslash( $_POST['wpitravel_bar'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every field is cleaned in AnnouncementBar::sanitize().

		update_option( AnnouncementBar::OPTION, AnnouncementBar::sanitize( (array) $raw ), true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => PostTypes::TRIP,
					'page'      => self::SLUG,
					'updated'   => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		$bar = AnnouncementBar::get();
		?>
		<div class="wrap wpitravel-bar-admin">
			<h1><?php esc_html_e( 'Announcement bar', 'wpinterface-travel' ); ?></h1>
			<p class="description"><?php esc_html_e( 'A slim bar at the top or bottom of your site for offers, news and notices. Up to three messages take turns; visitors can pause them, step through them and close the bar.', 'wpinterface-travel' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a notice. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Announcement bar saved.', 'wpinterface-travel' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpitravel_save_announcement">
				<?php wp_nonce_field( 'wpitravel_save_announcement' ); ?>
				<?php self::fields( $bar, 'wpitravel_bar' ); ?>
				<?php submit_button( __( 'Save announcement bar', 'wpinterface-travel' ) ); ?>
			</form>

			<?php
			/**
			 * Fires under the announcement bar form, for Pro.
			 */
			do_action( 'wpitravel_announcement_page_end' );
			?>
		</div>
		<?php
	}

	/**
	 * Prints the fields of one bar, with a live preview.
	 *
	 * @param array<string, mixed> $bar  Bar settings.
	 * @param string               $name Form field name prefix, e.g. wpitravel_bar.
	 */
	public static function fields( array $bar, string $name ): void {
		$bar      = array_merge( AnnouncementBar::defaults(), $bar );
		$messages = array_values( (array) $bar['messages'] );
		$field    = static function ( string $key ) use ( $name ): string {
			return $name . '[' . $key . ']';
		};

		for ( $i = count( $messages ); $i < AnnouncementBar::MAX_MESSAGES; $i++ ) {
			$messages[] = array(
				'text'    => '',
				'label'   => '',
				'url'     => '',
				'new_tab' => false,
			);
		}
		?>
		<div class="wpitravel-bar-editor" data-bar-editor>
			<input type="hidden" name="<?php echo esc_attr( $field( 'id' ) ); ?>" value="<?php echo esc_attr( (string) $bar['id'] ); ?>">

			<div class="wpitravel-bar-editor__preview" aria-hidden="true">
				<p class="wpitravel-bar-editor__preview-label"><?php esc_html_e( 'Preview', 'wpinterface-travel' ); ?></p>
				<div class="wpitravel-bar wpitravel-bar--preview" data-preview>
					<div class="wpitravel-bar__inner">
						<div class="wpitravel-bar__messages">
							<p class="wpitravel-bar__message is-current">
								<span class="wpitravel-bar__text" data-preview-text></span>
								<span class="wpitravel-bar__countdown" data-preview-count hidden><span class="wpitravel-bar__count-label" data-preview-count-label></span> <span class="wpitravel-bar__count">2d 04h 13m 09s</span></span>
								<span class="wpitravel-bar__button" data-preview-button></span>
							</p>
						</div>
						<span class="wpitravel-bar__close" data-preview-close><span class="wpitravel-bar__icon"></span></span>
					</div>
				</div>
			</div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show the bar', 'wpinterface-travel' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $field( 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $bar['enabled'] ) ); ?>> <?php esc_html_e( 'On', 'wpinterface-travel' ); ?></label></td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Messages', 'wpinterface-travel' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Bold, italic and links are allowed in the text. Empty messages are skipped.', 'wpinterface-travel' ); ?>
				<?php
				/**
				 * Filters help shown under the Messages heading (Pro lists its tokens here).
				 *
				 * @param string $help Plain text.
				 */
				$help = (string) apply_filters( 'wpitravel_announcement_messages_help', '' );
				echo '' !== $help ? ' ' . esc_html( $help ) : '';
				?>
			</p>

			<?php foreach ( $messages as $index => $message ) : ?>
				<?php $base = $name . '[messages][' . $index . ']'; ?>
				<fieldset class="wpitravel-bar-editor__message" data-message="<?php echo esc_attr( (string) $index ); ?>">
					<legend>
						<?php
						/* translators: %d: message number. */
						echo esc_html( sprintf( __( 'Message %d', 'wpinterface-travel' ), $index + 1 ) );
						?>
					</legend>
					<p>
						<label for="<?php echo esc_attr( $name . '-text-' . $index ); ?>"><?php esc_html_e( 'Text', 'wpinterface-travel' ); ?></label>
						<textarea class="large-text" rows="2" id="<?php echo esc_attr( $name . '-text-' . $index ); ?>" name="<?php echo esc_attr( $base . '[text]' ); ?>" data-field="text"><?php echo esc_textarea( (string) $message['text'] ); ?></textarea>
					</p>
					<p class="wpitravel-bar-editor__row">
						<span>
							<label for="<?php echo esc_attr( $name . '-label-' . $index ); ?>"><?php esc_html_e( 'Button text', 'wpinterface-travel' ); ?></label>
							<input type="text" class="regular-text" id="<?php echo esc_attr( $name . '-label-' . $index ); ?>" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( (string) $message['label'] ); ?>" data-field="label">
						</span>
						<span>
							<label for="<?php echo esc_attr( $name . '-url-' . $index ); ?>"><?php esc_html_e( 'Button link', 'wpinterface-travel' ); ?></label>
							<input type="url" class="regular-text" id="<?php echo esc_attr( $name . '-url-' . $index ); ?>" name="<?php echo esc_attr( $base . '[url]' ); ?>" value="<?php echo esc_attr( (string) $message['url'] ); ?>" placeholder="https://">
						</span>
						<label class="wpitravel-bar-editor__check"><input type="checkbox" name="<?php echo esc_attr( $base . '[new_tab]' ); ?>" value="1" <?php checked( ! empty( $message['new_tab'] ) ); ?>> <?php esc_html_e( 'Open in a new tab', 'wpinterface-travel' ); ?></label>
					</p>
				</fieldset>
			<?php endforeach; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $name . '-rotate' ); ?>"><?php esc_html_e( 'Seconds per message', 'wpinterface-travel' ); ?></label></th>
					<td>
						<input type="number" min="3" max="30" class="small-text" id="<?php echo esc_attr( $name . '-rotate' ); ?>" name="<?php echo esc_attr( $field( 'rotate' ) ); ?>" value="<?php echo esc_attr( (string) $bar['rotate'] ); ?>">
						<p class="description"><?php esc_html_e( 'Messages wait while the visitor points at or focuses the bar, and never move on their own for visitors who ask for reduced motion.', 'wpinterface-travel' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $name . '-countdown' ); ?>"><?php esc_html_e( 'Countdown to', 'wpinterface-travel' ); ?></label></th>
					<td>
						<input type="datetime-local" id="<?php echo esc_attr( $name . '-countdown' ); ?>" name="<?php echo esc_attr( $field( 'countdown' ) ); ?>" value="<?php echo esc_attr( (string) $bar['countdown'] ); ?>" data-field="countdown">
						<input type="text" class="regular-text" name="<?php echo esc_attr( $field( 'count_label' ) ); ?>" value="<?php echo esc_attr( (string) $bar['count_label'] ); ?>" placeholder="<?php esc_attr_e( 'Offer ends in', 'wpinterface-travel' ); ?>" aria-label="<?php esc_attr_e( 'Countdown label', 'wpinterface-travel' ); ?>" data-field="count_label">
						<p class="description"><?php esc_html_e( 'Shown with the first message. Leave empty for no countdown. Site time zone.', 'wpinterface-travel' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Look', 'wpinterface-travel' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Colours', 'wpinterface-travel' ); ?></th>
					<td>
						<fieldset class="wpitravel-bar-editor__schemes">
							<legend class="screen-reader-text"><?php esc_html_e( 'Colours', 'wpinterface-travel' ); ?></legend>
							<?php
							$schemes = array(
								'dark'   => __( 'Dark', 'wpinterface-travel' ),
								'light'  => __( 'Light', 'wpinterface-travel' ),
								'accent' => __( 'Crimson', 'wpinterface-travel' ),
								'sun'    => __( 'Sun', 'wpinterface-travel' ),
								'custom' => __( 'My own colours', 'wpinterface-travel' ),
							);
							foreach ( $schemes as $value => $label ) :
								?>
								<label><input type="radio" name="<?php echo esc_attr( $field( 'scheme' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $bar['scheme'], $value ); ?> data-field="scheme"> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<div class="wpitravel-bar-editor__colors" data-custom-colors>
							<?php
							$colors = array(
								'bg'        => __( 'Background', 'wpinterface-travel' ),
								'fg'        => __( 'Text', 'wpinterface-travel' ),
								'button_bg' => __( 'Button', 'wpinterface-travel' ),
								'button_fg' => __( 'Button text', 'wpinterface-travel' ),
							);
							foreach ( $colors as $key => $label ) :
								?>
								<span>
									<span class="wpitravel-bar-editor__color-label"><?php echo esc_html( $label ); ?></span>
									<input type="text" class="wpitravel-color" name="<?php echo esc_attr( $field( $key ) ); ?>" value="<?php echo esc_attr( (string) $bar[ $key ] ); ?>" data-field="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
								</span>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<?php
				$selects = array(
					'size'     => array(
						__( 'Size', 'wpinterface-travel' ),
						array(
							'small'  => __( 'Small', 'wpinterface-travel' ),
							'medium' => __( 'Medium', 'wpinterface-travel' ),
							'large'  => __( 'Large', 'wpinterface-travel' ),
						),
					),
					'align'    => array(
						__( 'Text alignment', 'wpinterface-travel' ),
						array(
							'center' => __( 'Centre', 'wpinterface-travel' ),
							'left'   => __( 'Left', 'wpinterface-travel' ),
						),
					),
					'position' => array(
						__( 'Position', 'wpinterface-travel' ),
						array(
							'top'    => __( 'Top of the page', 'wpinterface-travel' ),
							'bottom' => __( 'Bottom of the screen', 'wpinterface-travel' ),
						),
					),
				);
				foreach ( $selects as $key => $select ) :
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $name . '-' . $key ); ?>"><?php echo esc_html( $select[0] ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( $name . '-' . $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" data-field="<?php echo esc_attr( $key ); ?>">
								<?php foreach ( $select[1] as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $bar[ $key ], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stay on screen', 'wpinterface-travel' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $field( 'sticky' ) ); ?>" value="1" <?php checked( ! empty( $bar['sticky'] ) ); ?>> <?php esc_html_e( 'Keep the bar visible while scrolling', 'wpinterface-travel' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Close button', 'wpinterface-travel' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $field( 'dismissible' ) ); ?>" value="1" <?php checked( ! empty( $bar['dismissible'] ) ); ?> data-field="dismissible"> <?php esc_html_e( 'Visitors can close the bar', 'wpinterface-travel' ); ?></label>
						<p>
							<label>
								<?php esc_html_e( 'Keep it closed for', 'wpinterface-travel' ); ?>
								<input type="number" min="0" max="365" class="small-text" name="<?php echo esc_attr( $field( 'dismiss_days' ) ); ?>" value="<?php echo esc_attr( (string) $bar['dismiss_days'] ); ?>">
								<?php esc_html_e( 'days', 'wpinterface-travel' ); ?>
							</label>
						</p>
						<p class="description"><?php esc_html_e( '0 keeps it closed until the browser closes. Changing the messages shows the bar again to everyone. The choice is kept in the visitor’s browser only.', 'wpinterface-travel' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'When and where', 'wpinterface-travel' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Dates', 'wpinterface-travel' ); ?></th>
					<td>
						<label><?php esc_html_e( 'From', 'wpinterface-travel' ); ?> <input type="datetime-local" name="<?php echo esc_attr( $field( 'start' ) ); ?>" value="<?php echo esc_attr( (string) $bar['start'] ); ?>"></label>
						<label><?php esc_html_e( 'until', 'wpinterface-travel' ); ?> <input type="datetime-local" name="<?php echo esc_attr( $field( 'end' ) ); ?>" value="<?php echo esc_attr( (string) $bar['end'] ); ?>"></label>
						<p class="description"><?php esc_html_e( 'Optional. Site time zone. The bar also starts and stops on time on cached pages.', 'wpinterface-travel' ); ?></p>
					</td>
				</tr>
				<?php
				$rules = array(
					'pages'    => array(
						__( 'Pages', 'wpinterface-travel' ),
						array(
							'all'       => __( 'Every page', 'wpinterface-travel' ),
							'front'     => __( 'Homepage only', 'wpinterface-travel' ),
							'trips'     => __( 'Trip pages and trip lists only', 'wpinterface-travel' ),
							'not_trips' => __( 'Everywhere except trip pages', 'wpinterface-travel' ),
						),
					),
					'audience' => array(
						__( 'Visitors', 'wpinterface-travel' ),
						array(
							'all'     => __( 'Everyone', 'wpinterface-travel' ),
							'guests'  => __( 'Only visitors who are not logged in', 'wpinterface-travel' ),
							'members' => __( 'Only logged-in users', 'wpinterface-travel' ),
						),
					),
					'devices'  => array(
						__( 'Screens', 'wpinterface-travel' ),
						array(
							'all'     => __( 'Phones and computers', 'wpinterface-travel' ),
							'desktop' => __( 'Computers and tablets only', 'wpinterface-travel' ),
							'mobile'  => __( 'Phones only', 'wpinterface-travel' ),
						),
					),
				);
				foreach ( $rules as $key => $rule ) :
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $name . '-' . $key ); ?>"><?php echo esc_html( $rule[0] ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( $name . '-' . $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>">
								<?php foreach ( $rule[1] as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $bar[ $key ], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $name . '-exclude' ); ?>"><?php esc_html_e( 'Hide on', 'wpinterface-travel' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $name . '-exclude' ); ?>" name="<?php echo esc_attr( $field( 'exclude' ) ); ?>" value="<?php echo esc_attr( (string) $bar['exclude'] ); ?>" placeholder="12, 48">
						<p class="description"><?php esc_html_e( 'IDs of pages, posts or trips where the bar should not show, separated by commas.', 'wpinterface-travel' ); ?></p>
					</td>
				</tr>
				<?php
				/**
				 * Fires at the end of a bar's fields, for Pro's targeting.
				 *
				 * @param array<string, mixed> $bar  Bar.
				 * @param string               $name Field name prefix.
				 */
				do_action( 'wpitravel_announcement_fields', $bar, $name );
				?>
			</table>
		</div>
		<?php
	}
}
