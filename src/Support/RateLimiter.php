<?php
/**
 * Request rate limits.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per IP in fixed windows, stored in transients.
 *
 * The IP is REMOTE_ADDR. Behind a proxy or CDN, a site can supply the real
 * client IP with the `wpitravel_client_ip` filter.
 */
final class RateLimiter {

	/**
	 * Counts a request and reports whether it is within the limit.
	 *
	 * @param string $bucket Name of the limited action.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		/**
		 * Filters the request limit for an action. Return 0 to disable it.
		 *
		 * @param int    $limit  Requests per window.
		 * @param string $bucket Action, e.g. "bookings".
		 */
		$limit = (int) apply_filters( 'wpitravel_rate_limit', $limit, $bucket );

		if ( $limit <= 0 ) {
			return true;
		}

		$slot  = (int) floor( time() / $window );
		$key   = 'wpitravel_rl_' . md5( $bucket . '|' . self::client_key() . '|' . $slot );
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, $window );

		return $count <= $limit;
	}

	/**
	 * Who is asking, for limits: the logged-in user, else the IP. IPv6
	 * addresses count per /64 network, since one connection usually gets a
	 * whole /64 and could otherwise rotate addresses to dodge limits.
	 */
	public static function client_key(): string {
		$user = get_current_user_id();

		if ( $user > 0 ) {
			return 'user:' . $user;
		}

		$ip = self::client_ip();

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = (string) inet_pton( $ip );
			$ip     = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}

		return 'ip:' . $ip;
	}

	/**
	 * The client IP, validated.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP, e.g. to read a trusted proxy header.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'wpitravel_client_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
