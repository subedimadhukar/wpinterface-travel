<?php
/**
 * Plugin settings.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel;

use WP_Error;
use WPInterface\Travel\Emails\Templates;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single settings option.
 *
 * Stored values are always merged over defaults() and cast to the type of
 * their default, so callers can rely on every key existing with a known type.
 */
final class Options {

	public const OPTION = 'wpitravel_settings';

	/**
	 * String settings that keep line breaks (bank details, instructions).
	 */
	public const TEXTAREA_KEYS = array( 'instructions', 'description' );

	/**
	 * String settings that may contain post HTML (email bodies and footer).
	 */
	public const HTML_KEYS = array( 'body', 'footer' );

	/**
	 * String settings limited to a fixed set of values, matched exactly.
	 */
	public const CHOICES = array(
		'thousand_separator' => array( ',', '.', ' ', "'", '' ),
		'decimal_separator'  => array( '.', ',' ),
		'who'                => array( 'verified', 'anyone' ),
	);

	/**
	 * Settings merged over defaults, cached for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Default settings. Every setting must have a default here.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array(
			'general'    => array(
				'delete_data_on_uninstall' => false,
			),
			'currency'   => array(
				'code'               => 'USD',
				'position'           => 'left',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
				'decimals'           => 2,
			),
			'tax'        => array(
				'enabled'            => false,
				'prices_include_tax' => false,
				'rate'               => 0.0,
				'label'              => 'Tax',
			),
			'booking'    => array(
				'number_prefix'   => 'TRV-',
				'hold_minutes'    => 15,
				'guest_checkout'  => true,
				'create_accounts' => false,
				'terms_page_id'   => 0,
				'auto_widget'     => true,
				'account_page_id' => 0,
			),
			'emails'     => array(
				'from_name'         => '',
				'from_email'        => '',
				'admin_recipients'  => '',
				'footer'            => '',
				'reminder_days'     => 3,
				'review_delay_days' => 1,
				'templates'         => Templates::default_settings(),
			),
			'reviews'    => array(
				'enabled'               => true,
				'who'                   => 'verified',
				'auto_approve_verified' => true,
			),
			'payments'   => array(
				'confirm_on_payment'     => true,
				'unpaid_timeout_minutes' => 60,
				'gateways'               => Gateways::default_settings(),
			),
			'permalinks' => array(
				'trip_base'        => 'trips',
				'destination_base' => 'destination',
				'activity_base'    => 'activity',
				'trip_type_base'   => 'trip-type',
			),
		);

		/**
		 * Filters the default settings. Pro and add-ons add their own sections.
		 *
		 * @param array $defaults Default settings.
		 */
		return (array) apply_filters( 'wpitravel_default_settings', $defaults );
	}

	/**
	 * All settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = self::sanitize( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return $this->cache;
	}

	/**
	 * One setting by dot path, e.g. "currency.code".
	 *
	 * @param string $path          Dot-separated path.
	 * @param mixed  $default_value Returned when the path does not exist.
	 * @return mixed
	 */
	public function get( string $path, $default_value = null ) {
		$value = $this->all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default_value;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Merges new values into the stored settings, validates and saves them.
	 *
	 * Unknown keys are dropped and known keys are cast to their default's type.
	 * Nothing is saved when a value is invalid.
	 *
	 * @param array<string, mixed> $values Partial settings.
	 * @return array<string, mixed>|WP_Error Saved settings, or the first validation error.
	 */
	public function update( array $values ) {
		$old    = $this->all();
		$merged = array_replace_recursive( $old, $values );
		$clean  = self::normalize( self::sanitize( $merged, self::defaults() ) );
		$error  = self::validate( $clean );

		if ( $error ) {
			return $error;
		}

		update_option( self::OPTION, $clean, true );
		$this->cache = $clean;

		/**
		 * Fires after settings were saved.
		 *
		 * @param array $clean Saved settings.
		 * @param array $old   Settings before the change.
		 */
		do_action( 'wpitravel_settings_updated', $clean, $old );

		return $clean;
	}

	/**
	 * Returns the first problem with a full, sanitized settings array, or null.
	 *
	 * @param array<string, mixed> $settings Settings after sanitize().
	 */
	public static function validate( array $settings ): ?WP_Error {
		$fail = static function ( string $field, string $message ): WP_Error {
			return new WP_Error(
				'wpitravel_invalid_setting',
				$message,
				array(
					'status' => 400,
					'field'  => $field,
				)
			);
		};

		$currency = $settings['currency'];

		if ( ! Currencies::exists( $currency['code'] ) ) {
			return $fail( 'currency.code', __( 'Choose a supported currency.', 'wpinterface-travel' ) );
		}

		if ( ! array_key_exists( $currency['position'], Currencies::positions() ) ) {
			return $fail( 'currency.position', __( 'Choose a currency symbol position.', 'wpinterface-travel' ) );
		}

		if ( $currency['decimals'] < 0 || $currency['decimals'] > 4 ) {
			return $fail( 'currency.decimals', __( 'Decimals must be between 0 and 4.', 'wpinterface-travel' ) );
		}

		if ( $currency['thousand_separator'] === $currency['decimal_separator'] ) {
			return $fail( 'currency.thousand_separator', __( 'The thousand and decimal separators must be different.', 'wpinterface-travel' ) );
		}

		if ( $settings['tax']['rate'] < 0 || $settings['tax']['rate'] > 100 ) {
			return $fail( 'tax.rate', __( 'The tax rate must be between 0 and 100.', 'wpinterface-travel' ) );
		}

		if ( $settings['booking']['hold_minutes'] < 5 || $settings['booking']['hold_minutes'] > 120 ) {
			return $fail( 'booking.hold_minutes', __( 'Seats can be held for 5 to 120 minutes.', 'wpinterface-travel' ) );
		}

		if ( strlen( $settings['booking']['number_prefix'] ) > 20 ) {
			return $fail( 'booking.number_prefix', __( 'The booking number prefix can be at most 20 characters.', 'wpinterface-travel' ) );
		}

		$timeout = (int) $settings['payments']['unpaid_timeout_minutes'];

		if ( $timeout < 0 || $timeout > 10080 ) {
			return $fail( 'payments.unpaid_timeout_minutes', __( 'The unpaid booking timeout must be between 0 and 10080 minutes (one week).', 'wpinterface-travel' ) );
		}

		foreach ( array( 'from_email', 'admin_recipients' ) as $field ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $settings['emails'][ $field ] ) ) ) as $address ) {
				if ( ! is_email( $address ) ) {
					return $fail( 'emails.' . $field, __( 'Enter valid email addresses, separated by commas.', 'wpinterface-travel' ) );
				}
			}
		}

		if ( $settings['emails']['reminder_days'] < 0 || $settings['emails']['reminder_days'] > 60 || $settings['emails']['review_delay_days'] < 0 || $settings['emails']['review_delay_days'] > 60 ) {
			return $fail( 'emails.reminder_days', __( 'Use 0 to 60 days for reminders and review requests.', 'wpinterface-travel' ) );
		}

		$bases = $settings['permalinks'];

		foreach ( $bases as $key => $base ) {
			if ( '' === $base ) {
				return $fail( 'permalinks.' . $key, __( 'Permalink bases cannot be empty.', 'wpinterface-travel' ) );
			}
		}

		if ( count( array_unique( $bases ) ) !== count( $bases ) ) {
			return $fail( 'permalinks.trip_base', __( 'Each permalink base must be different.', 'wpinterface-travel' ) );
		}

		return null;
	}

	/**
	 * Value-specific clean-up that the generic type cast cannot do.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $settings ): array {
		$settings['currency']['code'] = strtoupper( $settings['currency']['code'] );

		foreach ( $settings['permalinks'] as $key => $base ) {
			$settings['permalinks'][ $key ] = sanitize_title( $base );
		}

		/**
		 * Filters settings before validation, for Pro and add-on sections.
		 *
		 * @param array $settings Settings.
		 */
		return (array) apply_filters( 'wpitravel_normalize_settings', $settings );
	}

	/**
	 * Clears the request cache.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Keeps only keys present in $defaults, cast to the default's type.
	 *
	 * @param array<string, mixed> $values   Values to clean.
	 * @param array<string, mixed> $defaults Defaults defining the shape.
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $values, array $defaults ): array {
		$clean = array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				$clean[ $key ] = $default;
				continue;
			}

			$value = $values[ $key ];

			if ( is_array( $default ) ) {
				$clean[ $key ] = self::sanitize( is_array( $value ) ? $value : array(), $default );
			} elseif ( is_bool( $default ) ) {
				$clean[ $key ] = wp_validate_boolean( $value );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( is_float( $default ) ) {
				$clean[ $key ] = (float) $value;
			} elseif ( isset( self::CHOICES[ $key ] ) ) {
				// Exact match, so a space separator is not trimmed away.
				$clean[ $key ] = in_array( $value, self::CHOICES[ $key ], true ) ? $value : $default;
			} elseif ( in_array( $key, self::HTML_KEYS, true ) ) {
				$clean[ $key ] = wp_kses_post( is_scalar( $value ) ? (string) $value : '' );
			} elseif ( in_array( $key, self::TEXTAREA_KEYS, true ) ) {
				$clean[ $key ] = sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
			} else {
				$clean[ $key ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			}
		}

		return $clean;
	}
}
