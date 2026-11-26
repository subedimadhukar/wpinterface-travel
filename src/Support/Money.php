<?php
/**
 * Money helpers.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts are decimal strings ("450.00"); comparisons and sums use integer
 * minor units, so floating-point rounding never changes a price.
 */
final class Money {

	/**
	 * Normalizes user input to a decimal string with `$decimals` places.
	 *
	 * Accepts "1,250.5", "1250,50" (comma decimal), numbers and whitespace.
	 * Returns '' for empty or invalid input and for negative amounts.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $decimals Decimal places of the currency.
	 */
	public static function normalize( $value, int $decimals = 2 ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			$number = (string) $value;
		} elseif ( is_string( $value ) ) {
			$number = self::parse_string( $value );
		} else {
			return '';
		}

		if ( '' === $number || ! is_numeric( $number ) || (float) $number < 0 ) {
			return '';
		}

		return number_format( round( (float) $number, $decimals ), $decimals, '.', '' );
	}

	/**
	 * Amount in minor units (cents), for exact comparison and sums.
	 *
	 * @param string $amount   Decimal string.
	 * @param int    $decimals Decimal places of the currency.
	 */
	public static function to_minor( string $amount, int $decimals = 2 ): int {
		return (int) round( (float) $amount * ( 10 ** $decimals ) );
	}

	/**
	 * Decimal string from minor units.
	 *
	 * @param int $minor    Minor units.
	 * @param int $decimals Decimal places of the currency.
	 */
	public static function from_minor( int $minor, int $decimals = 2 ): string {
		return number_format( $minor / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * Formats an amount for display with the site's currency settings.
	 *
	 * @param string               $amount   Decimal string.
	 * @param array<string, mixed> $currency Currency settings (code, position, separators, decimals).
	 */
	public static function format( string $amount, array $currency ): string {
		$decimals = (int) ( $currency['decimals'] ?? 2 );
		$number   = number_format(
			(float) $amount,
			$decimals,
			(string) ( $currency['decimal_separator'] ?? '.' ),
			(string) ( $currency['thousand_separator'] ?? ',' )
		);
		$code     = (string) ( $currency['code'] ?? 'USD' );
		$symbol   = Currencies::all()[ $code ]['symbol'] ?? $code;

		switch ( $currency['position'] ?? 'left' ) {
			case 'right':
				return $number . $symbol;
			case 'left_space':
				return $symbol . ' ' . $number;
			case 'right_space':
				return $number . ' ' . $symbol;
			default:
				return $symbol . $number;
		}
	}

	/**
	 * Turns "1 250,50", "1,250.50" or "1.250,50" into "1250.50".
	 *
	 * - Both "." and "," present: the last one is the decimal mark.
	 * - One kind, repeated ("1,250,000"): thousands separators.
	 * - A single ",": thousands when exactly three digits follow ("1,250"),
	 *   otherwise the decimal mark ("12,50").
	 * - A single ".": always the decimal mark ("12.3456", "1.250" = 1.25).
	 *
	 * @param string $value Raw input.
	 */
	private static function parse_string( string $value ): string {
		$value = preg_replace( '/[\s\x{00A0}\']/u', '', trim( $value ) );

		if ( null === $value || '' === $value ) {
			return '';
		}

		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			$decimal   = $last_dot > $last_comma ? '.' : ',';
			$thousands = '.' === $decimal ? ',' : '.';

			return str_replace( array( $thousands, $decimal ), array( '', '.' ), $value );
		}

		if ( false !== $last_dot ) {
			return substr_count( $value, '.' ) > 1 ? str_replace( '.', '', $value ) : $value;
		}

		if ( false !== $last_comma ) {
			$digits_after = strlen( $value ) - $last_comma - 1;

			if ( substr_count( $value, ',' ) > 1 || 3 === $digits_after ) {
				return str_replace( ',', '', $value );
			}

			return str_replace( ',', '.', $value );
		}

		return $value;
	}
}
