<?php
/**
 * Announcement bar.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Announcements;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Frontend\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * A bar at the top or bottom of every page for offers, news and notices.
 *
 * Up to three messages that take turns, each with an optional button; a
 * countdown to a date; colours, size and alignment; a close button that
 * remembers the visitor's choice; a start and end time; and rules for
 * which pages, which visitors and which screens see it.
 *
 * Pro and add-ons can add more bars through `wpitravel_announcement_bars`,
 * hide or show one through `wpitravel_announcement_bar_visible`, and change
 * message text through `wpitravel_announcement_text`.
 */
final class AnnouncementBar {

	public const OPTION = 'wpitravel_announcement';

	/**
	 * Most messages in one bar.
	 */
	public const MAX_MESSAGES = 3;

	/**
	 * Whether a bar was printed at the top of this page already.
	 *
	 * @var bool
	 */
	private bool $printed_top = false;

	/**
	 * Default settings of one bar.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'id'           => 'main',
			'enabled'      => false,
			'messages'     => array(
				array(
					'text'    => '',
					'label'   => '',
					'url'     => '',
					'new_tab' => false,
				),
			),
			'rotate'       => 6,
			'position'     => 'top',
			'sticky'       => false,
			'scheme'       => 'dark',
			'bg'           => '#1b2328',
			'fg'           => '#ffffff',
			'button_bg'    => '#ffffff',
			'button_fg'    => '#1b2328',
			'align'        => 'center',
			'size'         => 'medium',
			'dismissible'  => true,
			'dismiss_days' => 7,
			'pages'        => 'all',
			'exclude'      => '',
			'audience'     => 'all',
			'devices'      => 'all',
			'start'        => '',
			'end'          => '',
			'countdown'    => '',
			'count_label'  => '',
		);
	}

	/**
	 * Colour schemes: slug => [background, text, button background, button text].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function schemes(): array {
		return array(
			'dark'   => array( '#1b2328', '#ffffff', '#ffffff', '#1b2328' ),
			'light'  => array( '#f2f3f3', '#1b2328', '#1b2328', '#ffffff' ),
			'accent' => array( '#d42a45', '#ffffff', '#ffffff', '#d42a45' ),
			'sun'    => array( '#f5c84c', '#1b2328', '#1b2328', '#ffffff' ),
		);
	}

	/**
	 * The site's bar, saved values over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Every bar that may show: the site's own, then any from Pro.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function bars(): array {
		$bars = array( self::get() );

		/**
		 * Filters the announcement bars. Each bar has the keys of
		 * AnnouncementBar::defaults(), plus an optional `priority` (higher
		 * shows first) and `classes`.
		 *
		 * @param list<array<string, mixed>> $bars Bars.
		 */
		$bars = (array) apply_filters( 'wpitravel_announcement_bars', $bars );

		$bars = array_values(
			array_filter(
				array_map(
					static function ( $bar ) {
						return is_array( $bar ) ? array_merge( self::defaults(), $bar ) : null;
					},
					$bars
				)
			)
		);

		usort(
			$bars,
			static function ( $a, $b ) {
				return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
			}
		);

		return $bars;
	}

	/**
	 * Cleans submitted settings of one bar.
	 *
	 * @param array<string, mixed> $raw Submitted values (unslashed).
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$defaults = self::defaults();
		$clean    = array();

		$clean['id']      = isset( $raw['id'] ) && sanitize_key( (string) $raw['id'] ) === (string) $raw['id'] && '' !== (string) $raw['id'] ? (string) $raw['id'] : 'main';
		$clean['enabled'] = ! empty( $raw['enabled'] );

		$messages = array();

		foreach ( array_slice( (array) ( $raw['messages'] ?? array() ), 0, self::MAX_MESSAGES ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$text  = wp_kses( trim( (string) ( $message['text'] ?? '' ) ), self::text_kses() );
			$label = sanitize_text_field( (string) ( $message['label'] ?? '' ) );
			$url   = esc_url_raw( trim( (string) ( $message['url'] ?? '' ) ) );

			if ( '' === $text && '' === $label ) {
				continue;
			}

			$messages[] = array(
				'text'    => $text,
				'label'   => $label,
				'url'     => $url,
				'new_tab' => ! empty( $message['new_tab'] ),
			);
		}

		$clean['messages'] = $messages ? $messages : $defaults['messages'];
		$clean['rotate']   = max( 3, min( 30, absint( $raw['rotate'] ?? 6 ) ) );
		$clean['position'] = self::pick( $raw['position'] ?? '', array( 'top', 'bottom' ), 'top' );
		$clean['sticky']   = ! empty( $raw['sticky'] );
		$clean['scheme']   = self::pick( $raw['scheme'] ?? '', array_merge( array_keys( self::schemes() ), array( 'custom' ) ), 'dark' );

		foreach ( array( 'bg', 'fg', 'button_bg', 'button_fg' ) as $key ) {
			$color         = sanitize_hex_color( trim( (string) ( $raw[ $key ] ?? '' ) ) );
			$clean[ $key ] = $color ? $color : $defaults[ $key ];
		}

		$clean['align']        = self::pick( $raw['align'] ?? '', array( 'center', 'left' ), 'center' );
		$clean['size']         = self::pick( $raw['size'] ?? '', array( 'small', 'medium', 'large' ), 'medium' );
		$clean['dismissible']  = ! empty( $raw['dismissible'] );
		$clean['dismiss_days'] = min( 365, absint( $raw['dismiss_days'] ?? 7 ) );
		$clean['pages']        = self::pick( $raw['pages'] ?? '', array( 'all', 'front', 'trips', 'not_trips' ), 'all' );
		$clean['exclude']      = implode( ',', array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $raw['exclude'] ?? '' ) ) ) ) );
		$clean['audience']     = self::pick( $raw['audience'] ?? '', array( 'all', 'guests', 'members' ), 'all' );
		$clean['devices']      = self::pick( $raw['devices'] ?? '', array( 'all', 'desktop', 'mobile' ), 'all' );
		$clean['start']        = self::sanitize_datetime( $raw['start'] ?? '' );
		$clean['end']          = self::sanitize_datetime( $raw['end'] ?? '' );
		$clean['countdown']    = self::sanitize_datetime( $raw['countdown'] ?? '' );
		$clean['count_label']  = sanitize_text_field( (string) ( $raw['count_label'] ?? '' ) );

		/**
		 * Filters a bar's cleaned settings, for fields added by Pro. Clean
		 * every value you add from $raw.
		 *
		 * @param array<string, mixed> $clean Cleaned settings.
		 * @param array<string, mixed> $raw   Submitted values (unslashed, not cleaned).
		 */
		return (array) apply_filters( 'wpitravel_announcement_sanitize', $clean, $raw );
	}

	/**
	 * One of the allowed values.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $allowed  Allowed.
	 * @param string   $fallback Default.
	 */
	private static function pick( $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A local date and time from a datetime-local field, as Y-m-d\TH:i.
	 *
	 * @param mixed $value Value.
	 */
	public static function sanitize_datetime( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );

		return 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value ) ? $value : '';
	}

	/**
	 * A saved local time as a Unix timestamp (site time zone).
	 *
	 * @param string $local Y-m-d\TH:i.
	 */
	public static function timestamp( string $local ): int {
		if ( '' === $local ) {
			return 0;
		}

		$date = date_create_immutable_from_format( 'Y-m-d\TH:i', $local, wp_timezone() );

		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Inline HTML allowed in a message.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function text_kses(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'span'   => array( 'class' => true ),
			'br'     => array(),
		);
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'dismissed_flag' ), 3 );
		add_action( 'wp_body_open', array( $this, 'print_top' ), 5 );
		add_action( 'wp_footer', array( $this, 'print_bottom' ), 5 );
	}

	/**
	 * The bars that show on this page, at most one per position.
	 *
	 * @return array<string, array<string, mixed>> Position => bar.
	 */
	public function visible_bars(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		foreach ( self::bars() as $bar ) {
			if ( isset( $cache[ $bar['position'] ] ) || ! $this->is_visible( $bar ) ) {
				continue;
			}

			$cache[ $bar['position'] ] = $bar;
		}

		return $cache;
	}

	/**
	 * Whether a bar shows on this page for this visitor. Dates are checked
	 * again in the browser, so a cached page still starts and stops on time.
	 *
	 * @param array<string, mixed> $bar Bar.
	 */
	public function is_visible( array $bar ): bool {
		$visible = ! empty( $bar['enabled'] ) && $this->has_content( $bar ) && ! is_admin();

		$now = time();

		if ( $visible && '' !== $bar['end'] && self::timestamp( (string) $bar['end'] ) <= $now ) {
			$visible = false;
		}

		if ( $visible && 'guests' === $bar['audience'] && is_user_logged_in() ) {
			$visible = false;
		}

		if ( $visible && 'members' === $bar['audience'] && ! is_user_logged_in() ) {
			$visible = false;
		}

		if ( $visible ) {
			$visible = $this->matches_page( $bar );
		}

		/**
		 * Filters whether a bar shows on the current page.
		 *
		 * @param bool                 $visible Visible.
		 * @param array<string, mixed> $bar     Bar.
		 */
		return (bool) apply_filters( 'wpitravel_announcement_bar_visible', $visible, $bar );
	}

	/**
	 * Whether a bar has anything to say.
	 *
	 * @param array<string, mixed> $bar Bar.
	 */
	private function has_content( array $bar ): bool {
		foreach ( (array) $bar['messages'] as $message ) {
			if ( '' !== trim( wp_strip_all_tags( (string) ( $message['text'] ?? '' ) ) ) || '' !== (string) ( $message['label'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Page rules.
	 *
	 * @param array<string, mixed> $bar Bar.
	 */
	private function matches_page( array $bar ): bool {
		$excluded = array_filter( array_map( 'absint', explode( ',', (string) $bar['exclude'] ) ) );

		if ( $excluded && is_singular() && in_array( (int) get_queried_object_id(), $excluded, true ) ) {
			return false;
		}

		$trip_page = is_singular( PostTypes::TRIP ) || is_post_type_archive( PostTypes::TRIP ) || is_tax( get_object_taxonomies( PostTypes::TRIP ) );

		switch ( $bar['pages'] ) {
			case 'front':
				return is_front_page();
			case 'trips':
				return $trip_page;
			case 'not_trips':
				return ! $trip_page;
			default:
				return true;
		}
	}

	/**
	 * Styles and the script, only when a bar shows.
	 */
	public function enqueue(): void {
		if ( ! $this->visible_bars() ) {
			return;
		}

		wp_enqueue_style( 'wpitravel-announcement', WPITRAVEL_URL . 'assets/css/announcement-bar.css', array(), WPITRAVEL_VERSION );
		wp_add_inline_style( 'wpitravel-announcement', $this->color_css() );
		wp_enqueue_script(
			'wpitravel-announcement',
			WPITRAVEL_URL . 'assets/js/announcement-bar.js',
			array(),
			WPITRAVEL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			'wpitravel-announcement',
			'window.wpitravelBar = ' . wp_json_encode(
				array(
					/* translators: Countdown units, shortest form: days, hours, minutes, seconds. */
					'units' => array( _x( 'd', 'days', 'wpinterface-travel' ), _x( 'h', 'hours', 'wpinterface-travel' ), _x( 'm', 'minutes', 'wpinterface-travel' ), _x( 's', 'seconds', 'wpinterface-travel' ) ),
					'pause' => __( 'Pause messages', 'wpinterface-travel' ),
					'play'  => __( 'Play messages', 'wpinterface-travel' ),
					'prev'  => __( 'Previous message', 'wpinterface-travel' ),
					'next'  => __( 'Next message', 'wpinterface-travel' ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);
	}

	/**
	 * Hides bars the visitor already closed before the page paints, so they
	 * do not flash in and out.
	 */
	public function dismissed_flag(): void {
		$keys = array();

		foreach ( $this->visible_bars() as $bar ) {
			if ( ! empty( $bar['dismissible'] ) ) {
				$keys[] = self::dismiss_key( $bar );
			}
		}

		if ( ! $keys ) {
			return;
		}

		wp_print_inline_script_tag(
			'(function(k){try{var n=Date.now(),c="";k.forEach(function(x){var v=window.localStorage.getItem(x)||window.sessionStorage.getItem(x);if(v&&(v==="s"||+v>n)){c+="[data-wpitravel-bar=\""+x+"\"]{display:none!important}";}});if(c){var s=document.createElement("style");s.textContent=c;document.head.appendChild(s);}}catch(e){}})(' . wp_json_encode( $keys, JSON_HEX_TAG | JSON_HEX_AMP ) . ');'
		);
	}

	/**
	 * The storage key for a bar's "closed" choice. It changes when the
	 * messages change, so a new announcement shows again.
	 *
	 * @param array<string, mixed> $bar Bar.
	 */
	public static function dismiss_key( array $bar ): string {
		return 'wpitravel-bar-' . sanitize_key( (string) $bar['id'] ) . '-' . substr( md5( (string) wp_json_encode( $bar['messages'] ) . $bar['countdown'] ), 0, 8 );
	}

	/**
	 * Prints the top bar right after <body>.
	 */
	public function print_top(): void {
		$bars = $this->visible_bars();

		if ( isset( $bars['top'] ) ) {
			$this->printed_top = true;
			$this->render( $bars['top'] );
		}
	}

	/**
	 * Prints the bottom bar, and the top bar on themes without wp_body_open
	 * (the script then moves it to the top).
	 */
	public function print_bottom(): void {
		$bars = $this->visible_bars();

		if ( isset( $bars['top'] ) && ! $this->printed_top ) {
			$this->render( $bars['top'], true );
		}

		if ( isset( $bars['bottom'] ) ) {
			$this->render( $bars['bottom'] );
		}
	}

	/**
	 * Prints one bar.
	 *
	 * @param array<string, mixed> $bar  Bar.
	 * @param bool                 $move Move it to the top of the page with JavaScript.
	 */
	public function render( array $bar, bool $move = false ): void {
		$messages = array();

		foreach ( (array) $bar['messages'] as $message ) {
			/**
			 * Filters a message's text before it is shown (for tokens and translations).
			 *
			 * @param string               $text Message HTML (limited tags).
			 * @param array<string, mixed> $bar  Bar.
			 */
			$text = (string) apply_filters( 'wpitravel_announcement_text', (string) ( $message['text'] ?? '' ), $bar );

			$messages[] = array_merge( (array) $message, array( 'text' => wp_kses( $text, self::text_kses() ) ) );
		}

		$classes = array(
			'wpitravel-bar',
			'wpitravel-bar--' . $bar['position'],
			'wpitravel-bar--' . $bar['size'],
			'wpitravel-bar--align-' . $bar['align'],
		);

		if ( ! empty( $bar['sticky'] ) ) {
			$classes[] = 'is-sticky';
		}

		if ( 'all' !== $bar['devices'] ) {
			$classes[] = 'wpitravel-bar--only-' . $bar['devices'];
		}

		foreach ( (array) ( $bar['classes'] ?? array() ) as $class ) {
			$classes[] = sanitize_html_class( (string) $class );
		}

		Templates::output(
			Templates::part(
				'parts/announcement-bar',
				array(
					'bar'      => $bar,
					'messages' => $messages,
					'classes'  => array_filter( $classes ),
					'key'      => self::dismiss_key( $bar ),
					'start'    => self::timestamp( (string) $bar['start'] ),
					'end'      => self::timestamp( (string) $bar['end'] ),
					'count'    => self::timestamp( (string) $bar['countdown'] ),
					'move'     => $move,
				)
			)
		);
	}

	/**
	 * A bar's colours: its scheme, or its own when the scheme is custom.
	 *
	 * @param array<string, mixed> $bar Bar.
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 */
	public static function colors( array $bar ): array {
		$schemes = self::schemes();

		if ( 'custom' !== $bar['scheme'] && isset( $schemes[ $bar['scheme'] ] ) ) {
			return $schemes[ $bar['scheme'] ];
		}

		return array(
			(string) sanitize_hex_color( (string) $bar['bg'] ),
			(string) sanitize_hex_color( (string) $bar['fg'] ),
			(string) sanitize_hex_color( (string) $bar['button_bg'] ),
			(string) sanitize_hex_color( (string) $bar['button_fg'] ),
		);
	}

	/**
	 * The colour rule of each bar on this page.
	 */
	private function color_css(): string {
		$css = '';

		foreach ( $this->visible_bars() as $bar ) {
			$colors = self::colors( $bar );
			$css   .= sprintf(
				'.wpitravel-bar[data-wpitravel-bar="%1$s"]{--wpitravel-bar-bg:%2$s;--wpitravel-bar-fg:%3$s;--wpitravel-bar-button-bg:%4$s;--wpitravel-bar-button-fg:%5$s}',
				esc_attr( self::dismiss_key( $bar ) ),
				$colors[0] ? $colors[0] : '#1b2328',
				$colors[1] ? $colors[1] : '#ffffff',
				$colors[2] ? $colors[2] : '#ffffff',
				$colors[3] ? $colors[3] : '#1b2328'
			);
		}

		return $css;
	}
}
