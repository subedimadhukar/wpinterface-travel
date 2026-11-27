<?php
/**
 * WPML and Polylang.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Translations of a trip share the original trip's dates, seats, prices,
 * bookings and reviews; each shows its own title and text.
 *
 * - original_trip_id(): any translation's ID → the original (default
 *   language) trip's ID. Bookings, departures and reviews always use it.
 * - translated_id(): the original → its translation in a language, for
 *   titles and links in emails and on "My bookings".
 *
 * Works with WPML and Polylang, and does nothing without them. Other
 * plugins can answer through the two filters.
 */
final class Multilingual {

	/**
	 * Meta copied from the original to new translations and kept in step
	 * (numbers and settings, not words).
	 */
	public const COPY_META = array(
		TripMeta::CODE,
		TripMeta::DURATION,
		TripMeta::DIFFICULTY,
		TripMeta::GROUP_SIZE,
		TripMeta::MIN_AGE,
		TripMeta::GALLERY,
		TripMeta::MAP,
		TripMeta::SCHEDULE,
	);

	/**
	 * Meta copied once when a translation is made, then translated (it
	 * holds words, or words beside numbers, like price option names).
	 */
	public const COPY_ONCE_META = array(
		TripMeta::PRICING,
		TripMeta::PICKUPS,
	);

	/**
	 * Meta that translators translate.
	 */
	public const TRANSLATE_META = array(
		TripMeta::MEETING_POINT,
		TripMeta::HIGHLIGHTS,
		TripMeta::ITINERARY,
		TripMeta::INCLUDES,
		TripMeta::EXCLUDES,
		TripMeta::FAQS,
		TripMeta::FACTS,
	);

	/**
	 * Registers the Polylang integration (WPML reads wpml-config.xml).
	 */
	public function register(): void {
		add_filter( 'pll_get_post_types', array( $this, 'polylang_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( $this, 'polylang_taxonomies' ), 10, 2 );
		add_filter( 'pll_copy_post_metas', array( $this, 'polylang_copy_meta' ), 10, 3 );
	}

	/**
	 * Whether WPML or Polylang is active.
	 */
	public static function active(): bool {
		return self::wpml() || self::polylang();
	}

	/**
	 * The original (default language) trip of a translation, or the ID
	 * itself when it is the original or nothing is translated.
	 *
	 * @param int $trip_id Trip ID, in any language.
	 */
	public static function original_trip_id( int $trip_id ): int {
		$original = $trip_id;

		if ( $trip_id > 0 && self::wpml() ) {
			$default  = (string) apply_filters( 'wpml_default_language', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			$found    = (int) apply_filters( 'wpml_object_id', $trip_id, PostTypes::TRIP, true, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			$original = $found > 0 ? $found : $trip_id;
		} elseif ( $trip_id > 0 && self::polylang() ) {
			$found    = (int) \pll_get_post( $trip_id, (string) \pll_default_language() );
			$original = $found > 0 ? $found : $trip_id;
		}

		/**
		 * Filters the original trip of a translated trip. Bookings, departures,
		 * seats and reviews belong to the original.
		 *
		 * @param int $original Original trip ID.
		 * @param int $trip_id  Trip ID asked about.
		 */
		return (int) apply_filters( 'wpitravel_original_trip_id', $original, $trip_id );
	}

	/**
	 * A post's translation in a language (locale such as "fr_FR", or a
	 * language code such as "fr"), or the post itself when there is none.
	 * Empty language: the language of the current page.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Locale or language code.
	 */
	public static function translated_id( int $post_id, string $language = '' ): int {
		$translated = $post_id;
		$code       = self::language_code( $language );

		if ( $post_id > 0 && self::wpml() ) {
			$found      = (int) apply_filters( 'wpml_object_id', $post_id, (string) get_post_type( $post_id ), true, '' !== $code ? $code : null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			$translated = $found > 0 ? $found : $post_id;
		} elseif ( $post_id > 0 && self::polylang() ) {
			$found      = (int) ( '' !== $code ? \pll_get_post( $post_id, $code ) : \pll_get_post( $post_id ) );
			$translated = $found > 0 ? $found : $post_id;
		}

		/**
		 * Filters a post's translation in a language.
		 *
		 * @param int    $translated Translated post ID.
		 * @param int    $post_id    Post ID asked about.
		 * @param string $language   Locale or language code ('' = current).
		 */
		return (int) apply_filters( 'wpitravel_translated_post_id', $translated, $post_id, $language );
	}

	/**
	 * A trip and all its translations (the trip alone without WPML or Polylang).
	 *
	 * @param int $trip_id Trip ID, in any language.
	 * @return list<int>
	 */
	public static function translations( int $trip_id ): array {
		$ids = array( $trip_id );

		if ( $trip_id > 0 && self::wpml() ) {
			$group = apply_filters( 'wpml_element_trid', null, $trip_id, 'post_' . PostTypes::TRIP ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			$items = (array) apply_filters( 'wpml_get_element_translations', array(), $group, 'post_' . PostTypes::TRIP ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.

			foreach ( $items as $item ) {
				$ids[] = (int) ( is_object( $item ) ? ( $item->element_id ?? 0 ) : 0 );
			}
		} elseif ( $trip_id > 0 && self::polylang() && function_exists( 'pll_get_post_translations' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) \pll_get_post_translations( $trip_id ) ) );
		}

		/**
		 * Filters a trip's translations (including the trip itself).
		 *
		 * @param list<int> $ids     Trip IDs.
		 * @param int       $trip_id Trip ID asked about.
		 */
		$ids = array_map( 'intval', (array) apply_filters( 'wpitravel_trip_translations', $ids, $trip_id ) );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * The language code WPML or Polylang uses for a locale ("fr_FR" → "fr").
	 * Codes are returned as they are.
	 *
	 * @param string $language Locale or code.
	 */
	public static function language_code( string $language ): string {
		if ( '' === $language || ! self::active() ) {
			return '';
		}

		if ( self::polylang() && function_exists( 'pll_languages_list' ) ) {
			$codes   = (array) \pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = (array) \pll_languages_list( array( 'fields' => 'locale' ) );
			$index   = array_search( $language, $locales, true );

			if ( false !== $index && isset( $codes[ $index ] ) ) {
				return (string) $codes[ $index ];
			}

			return in_array( $language, $codes, true ) ? $language : '';
		}

		$languages = (array) apply_filters( 'wpml_active_languages', array(), array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.

		foreach ( $languages as $code => $details ) {
			$locale = is_array( $details ) ? (string) ( $details['default_locale'] ?? '' ) : '';

			if ( (string) $code === $language || $locale === $language ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * Switches WPML or Polylang to a language (locale or code) and clears the
	 * plugin's settings cache, so translated settings are read again.
	 *
	 * @param string $language Locale or language code.
	 * @return string|null The language that was current (pass it back to
	 *                     restore it), or null when nothing was switched.
	 */
	public static function switch_language( string $language ): ?string {
		$code = self::language_code( $language );

		if ( '' === $code ) {
			return null;
		}

		$previous = '';

		if ( self::wpml() ) {
			$previous = (string) apply_filters( 'wpml_current_language', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.

			if ( $previous === $code ) {
				return null;
			}

			do_action( 'wpml_switch_language', $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		} elseif ( self::polylang() && function_exists( 'PLL' ) && function_exists( 'pll_current_language' ) ) {
			$previous = (string) \pll_current_language();
			$polylang = \PLL();

			if ( $previous === $code || ! isset( $polylang->model ) || ! is_object( $polylang->model ) || ! method_exists( $polylang->model, 'get_language' ) ) {
				return null;
			}

			$found = $polylang->model->get_language( $code );

			if ( ! $found ) {
				return null;
			}

			$polylang->curlang = $found;
		} else {
			return null;
		}

		wpitravel()->options()->flush();

		return $previous;
	}

	/**
	 * Makes trips translatable in Polylang without a trip to its settings.
	 *
	 * @param array<string, string> $post_types Post types.
	 * @param bool                  $is_settings Whether for the settings screen.
	 * @return array<string, string>
	 */
	public function polylang_post_types( $post_types, $is_settings ): array {
		$post_types = (array) $post_types;

		if ( ! $is_settings ) {
			$post_types[ PostTypes::TRIP ] = PostTypes::TRIP;
		}

		return $post_types;
	}

	/**
	 * Makes destinations, activities and trip types translatable in Polylang.
	 *
	 * @param array<string, string> $taxonomies  Taxonomies.
	 * @param bool                  $is_settings Whether for the settings screen.
	 * @return array<string, string>
	 */
	public function polylang_taxonomies( $taxonomies, $is_settings ): array {
		$taxonomies = (array) $taxonomies;

		if ( ! $is_settings ) {
			foreach ( array( PostTypes::DESTINATION, PostTypes::ACTIVITY, PostTypes::TRIP_TYPE ) as $taxonomy ) {
				$taxonomies[ $taxonomy ] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Meta Polylang copies to translations: kept-in-step meta always, and
	 * copy-once meta when the translation is made.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param bool               $sync Whether Polylang is synchronising (true) or making a translation (false).
	 * @param int                $from Source post ID.
	 * @return array<int, string>
	 */
	public function polylang_copy_meta( $keys, $sync, $from = 0 ): array {
		$keys = (array) $keys;

		if ( PostTypes::TRIP !== get_post_type( (int) $from ) ) {
			return $keys;
		}

		$copy = self::copy_meta();

		if ( ! $sync ) {
			$copy = array_merge( $copy, self::copy_once_meta() );
		}

		return array_values( array_unique( array_merge( $keys, $copy ) ) );
	}

	/**
	 * Kept-in-step meta, with add-ons' keys.
	 *
	 * @return list<string>
	 */
	public static function copy_meta(): array {
		/**
		 * Filters the trip meta that translations share with the original
		 * (numbers and settings). Pro adds its own keys.
		 *
		 * @param list<string> $keys Meta keys.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'wpitravel_multilingual_copy_meta', self::COPY_META ) ) ) );
	}

	/**
	 * Meta copied once to new translations, with add-ons' keys.
	 *
	 * @return list<string>
	 */
	public static function copy_once_meta(): array {
		/**
		 * Filters the trip meta copied once to a new translation and then
		 * translated (words beside numbers, like price option names).
		 *
		 * @param list<string> $keys Meta keys.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'wpitravel_multilingual_copy_once_meta', self::COPY_ONCE_META ) ) ) );
	}

	/**
	 * Whether WPML is active.
	 */
	private static function wpml(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_object_id' );
	}

	/**
	 * Whether Polylang is active.
	 */
	private static function polylang(): bool {
		return function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' );
	}
}
