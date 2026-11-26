<?php
/**
 * Trip pricing rules.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Pricing;

use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Price options of a trip, each with per-traveler category prices.
 *
 * Stored in the `_wpitravel_pricing` meta as:
 *
 *     options: [ {
 *         key, label, min_pax, max_pax,
 *         categories: [ { key, label, price, sale_price, min, max } ]
 *     } ]
 *
 * Prices are per traveler. Keys are stable slugs that bookings reference,
 * so renaming a label never breaks an existing booking.
 */
final class Pricing {

	/**
	 * Maximum options per trip and categories per option.
	 */
	private const MAX_OPTIONS    = 20;
	private const MAX_CATEGORIES = 10;

	/**
	 * Empty pricing.
	 *
	 * @return array{options: list<array<string, mixed>>}
	 */
	public static function empty_value(): array {
		return array( 'options' => array() );
	}

	/**
	 * Cleans a pricing value. Used as the meta sanitize callback.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $decimals Currency decimals; defaults to the site currency.
	 * @return array{options: list<array<string, mixed>>}
	 */
	public static function sanitize( $value, ?int $decimals = null ): array {
		$decimals = $decimals ?? self::site_decimals();
		$value    = is_array( $value ) ? $value : array();
		$options  = array();
		$used     = array();

		foreach ( array_slice( (array) ( $value['options'] ?? array() ), 0, self::MAX_OPTIONS ) as $index => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$label      = self::text( $option['label'] ?? '' );
			$categories = self::sanitize_categories( (array) ( $option['categories'] ?? array() ), $decimals );

			if ( empty( $categories ) ) {
				continue;
			}

			$key    = self::unique_key( self::key( $option['key'] ?? '', $label, 'option-' . ( $index + 1 ) ), $used );
			$used[] = $key;

			$min_pax = max( 1, absint( $option['min_pax'] ?? 1 ) );
			$max_pax = absint( $option['max_pax'] ?? 0 );

			$options[] = array(
				'key'        => $key,
				'label'      => '' !== $label ? $label : __( 'Standard', 'wpinterface-travel' ),
				'min_pax'    => $min_pax,
				'max_pax'    => ( $max_pax > 0 && $max_pax < $min_pax ) ? $min_pax : $max_pax,
				'categories' => $categories,
			);
		}

		return array( 'options' => $options );
	}

	/**
	 * The effective price of a category: the sale price when it is lower.
	 *
	 * @param array<string, mixed> $category Category.
	 * @param int                  $decimals Currency decimals.
	 */
	public static function effective_price( array $category, int $decimals = 2 ): string {
		$price = (string) ( $category['price'] ?? '' );
		$sale  = (string) ( $category['sale_price'] ?? '' );

		if ( '' !== $sale && Money::to_minor( $sale, $decimals ) < Money::to_minor( $price, $decimals ) ) {
			return $sale;
		}

		return $price;
	}

	/**
	 * Lowest "from" price over all options, using each option's first category
	 * (normally Adult), and whether that price is a sale price.
	 *
	 * @param array<string, mixed> $pricing    Sanitized pricing.
	 * @param string               $option_key Limit to one option; '' for all.
	 * @param int                  $decimals   Currency decimals.
	 * @return array{price: string, regular: string, sale: bool}|null Null when the trip has no prices.
	 */
	public static function from_price( array $pricing, string $option_key = '', int $decimals = 2 ): ?array {
		$best = null;

		foreach ( (array) ( $pricing['options'] ?? array() ) as $option ) {
			if ( '' !== $option_key && $option['key'] !== $option_key ) {
				continue;
			}

			$first = $option['categories'][0] ?? null;

			if ( ! is_array( $first ) ) {
				continue;
			}

			$price = self::effective_price( $first, $decimals );

			if ( null === $best || Money::to_minor( $price, $decimals ) < Money::to_minor( $best['price'], $decimals ) ) {
				$best = array(
					'price'   => $price,
					'regular' => (string) $first['price'],
					'sale'    => $price !== $first['price'],
				);
			}
		}

		return $best;
	}

	/**
	 * Finds an option by key.
	 *
	 * @param array<string, mixed> $pricing Sanitized pricing.
	 * @param string               $key     Option key.
	 * @return array<string, mixed>|null
	 */
	public static function option( array $pricing, string $key ): ?array {
		foreach ( (array) ( $pricing['options'] ?? array() ) as $option ) {
			if ( $option['key'] === $key ) {
				return $option;
			}
		}

		return null;
	}

	/**
	 * Cleans the categories of one option. Rows without a price are dropped.
	 *
	 * @param array<mixed> $categories Raw categories.
	 * @param int          $decimals   Currency decimals.
	 * @return list<array{key: string, label: string, price: string, sale_price: string, min: int, max: int}>
	 */
	private static function sanitize_categories( array $categories, int $decimals ): array {
		$clean = array();
		$used  = array();

		foreach ( array_slice( $categories, 0, self::MAX_CATEGORIES ) as $index => $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$price = Money::normalize( $category['price'] ?? '', $decimals );

			if ( '' === $price ) {
				continue;
			}

			$label = self::text( $category['label'] ?? '' );
			$sale  = Money::normalize( $category['sale_price'] ?? '', $decimals );

			// A sale price must be lower than the regular price, or it is ignored.
			if ( '' !== $sale && Money::to_minor( $sale, $decimals ) >= Money::to_minor( $price, $decimals ) ) {
				$sale = '';
			}

			$key    = self::unique_key( self::key( $category['key'] ?? '', $label, 'category-' . ( $index + 1 ) ), $used );
			$used[] = $key;
			$min    = absint( $category['min'] ?? 0 );
			$max    = absint( $category['max'] ?? 0 );

			$clean[] = array(
				'key'        => $key,
				'label'      => '' !== $label ? $label : __( 'Traveler', 'wpinterface-travel' ),
				'price'      => $price,
				'sale_price' => $sale,
				'min'        => $min,
				'max'        => ( $max > 0 && $max < $min ) ? $min : $max,
			);
		}

		return $clean;
	}

	/**
	 * A key from the given key, else from the label, else the fallback.
	 *
	 * @param mixed  $key      Raw key.
	 * @param string $label    Label.
	 * @param string $fallback Fallback key.
	 */
	private static function key( $key, string $label, string $fallback ): string {
		$key = is_string( $key ) ? sanitize_key( $key ) : '';

		if ( '' === $key ) {
			$key = sanitize_key( sanitize_title( $label ) );
		}

		return '' !== $key ? substr( $key, 0, 64 ) : $fallback;
	}

	/**
	 * Appends -2, -3… until the key is unused.
	 *
	 * @param string   $key  Key.
	 * @param string[] $used Keys already taken.
	 */
	private static function unique_key( string $key, array $used ): string {
		$candidate = $key;
		$suffix    = 2;

		while ( in_array( $candidate, $used, true ) ) {
			$candidate = $key . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Plain text.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Decimals of the site currency.
	 */
	private static function site_decimals(): int {
		return (int) wpitravel()->options()->get( 'currency.decimals', 2 );
	}
}
