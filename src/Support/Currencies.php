<?php
/**
 * Currency list.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 4217 currencies with their symbol and minor-unit decimals.
 */
final class Currencies {

	/**
	 * Symbol positions: key => example.
	 *
	 * @return array<string, string>
	 */
	public static function positions(): array {
		return array(
			'left'        => __( 'Left ($99)', 'wpinterface-travel' ),
			'right'       => __( 'Right (99$)', 'wpinterface-travel' ),
			'left_space'  => __( 'Left with space ($ 99)', 'wpinterface-travel' ),
			'right_space' => __( 'Right with space (99 $)', 'wpinterface-travel' ),
		);
	}

	/**
	 * Whether a currency code is supported.
	 *
	 * @param string $code ISO 4217 code.
	 */
	public static function exists( string $code ): bool {
		return array_key_exists( $code, self::all() );
	}

	/**
	 * Decimal places of a currency (2 when unknown).
	 *
	 * @param string $code ISO 4217 code.
	 */
	public static function decimals( string $code ): int {
		return self::all()[ $code ]['decimals'] ?? 2;
	}

	/**
	 * All currencies: code => name, symbol, decimals.
	 *
	 * @return array<string, array{name: string, symbol: string, decimals: int}>
	 */
	public static function all(): array {
		static $currencies = null;

		if ( null !== $currencies ) {
			return $currencies;
		}

		$list = array(
			'AED' => array( __( 'UAE dirham', 'wpinterface-travel' ), 'د.إ', 2 ),
			'AFN' => array( __( 'Afghan afghani', 'wpinterface-travel' ), '؋', 2 ),
			'ALL' => array( __( 'Albanian lek', 'wpinterface-travel' ), 'L', 2 ),
			'AMD' => array( __( 'Armenian dram', 'wpinterface-travel' ), '֏', 2 ),
			'AOA' => array( __( 'Angolan kwanza', 'wpinterface-travel' ), 'Kz', 2 ),
			'ARS' => array( __( 'Argentine peso', 'wpinterface-travel' ), '$', 2 ),
			'AUD' => array( __( 'Australian dollar', 'wpinterface-travel' ), 'A$', 2 ),
			'AZN' => array( __( 'Azerbaijani manat', 'wpinterface-travel' ), '₼', 2 ),
			'BAM' => array( __( 'Bosnia and Herzegovina convertible mark', 'wpinterface-travel' ), 'KM', 2 ),
			'BBD' => array( __( 'Barbadian dollar', 'wpinterface-travel' ), '$', 2 ),
			'BDT' => array( __( 'Bangladeshi taka', 'wpinterface-travel' ), '৳', 2 ),
			'BGN' => array( __( 'Bulgarian lev', 'wpinterface-travel' ), 'лв', 2 ),
			'BHD' => array( __( 'Bahraini dinar', 'wpinterface-travel' ), '.د.ب', 3 ),
			'BND' => array( __( 'Brunei dollar', 'wpinterface-travel' ), '$', 2 ),
			'BOB' => array( __( 'Bolivian boliviano', 'wpinterface-travel' ), 'Bs.', 2 ),
			'BRL' => array( __( 'Brazilian real', 'wpinterface-travel' ), 'R$', 2 ),
			'BSD' => array( __( 'Bahamian dollar', 'wpinterface-travel' ), '$', 2 ),
			'BTN' => array( __( 'Bhutanese ngultrum', 'wpinterface-travel' ), 'Nu.', 2 ),
			'BWP' => array( __( 'Botswana pula', 'wpinterface-travel' ), 'P', 2 ),
			'BZD' => array( __( 'Belize dollar', 'wpinterface-travel' ), '$', 2 ),
			'CAD' => array( __( 'Canadian dollar', 'wpinterface-travel' ), 'C$', 2 ),
			'CHF' => array( __( 'Swiss franc', 'wpinterface-travel' ), 'CHF', 2 ),
			'CLP' => array( __( 'Chilean peso', 'wpinterface-travel' ), '$', 0 ),
			'CNY' => array( __( 'Chinese yuan', 'wpinterface-travel' ), '¥', 2 ),
			'COP' => array( __( 'Colombian peso', 'wpinterface-travel' ), '$', 2 ),
			'CRC' => array( __( 'Costa Rican colón', 'wpinterface-travel' ), '₡', 2 ),
			'CZK' => array( __( 'Czech koruna', 'wpinterface-travel' ), 'Kč', 2 ),
			'DKK' => array( __( 'Danish krone', 'wpinterface-travel' ), 'kr.', 2 ),
			'DOP' => array( __( 'Dominican peso', 'wpinterface-travel' ), 'RD$', 2 ),
			'DZD' => array( __( 'Algerian dinar', 'wpinterface-travel' ), 'د.ج', 2 ),
			'EGP' => array( __( 'Egyptian pound', 'wpinterface-travel' ), 'E£', 2 ),
			'ETB' => array( __( 'Ethiopian birr', 'wpinterface-travel' ), 'Br', 2 ),
			'EUR' => array( __( 'Euro', 'wpinterface-travel' ), '€', 2 ),
			'FJD' => array( __( 'Fijian dollar', 'wpinterface-travel' ), 'FJ$', 2 ),
			'GBP' => array( __( 'Pound sterling', 'wpinterface-travel' ), '£', 2 ),
			'GEL' => array( __( 'Georgian lari', 'wpinterface-travel' ), '₾', 2 ),
			'GHS' => array( __( 'Ghanaian cedi', 'wpinterface-travel' ), '₵', 2 ),
			'GTQ' => array( __( 'Guatemalan quetzal', 'wpinterface-travel' ), 'Q', 2 ),
			'HKD' => array( __( 'Hong Kong dollar', 'wpinterface-travel' ), 'HK$', 2 ),
			'HNL' => array( __( 'Honduran lempira', 'wpinterface-travel' ), 'L', 2 ),
			'HUF' => array( __( 'Hungarian forint', 'wpinterface-travel' ), 'Ft', 2 ),
			'IDR' => array( __( 'Indonesian rupiah', 'wpinterface-travel' ), 'Rp', 2 ),
			'ILS' => array( __( 'Israeli new shekel', 'wpinterface-travel' ), '₪', 2 ),
			'INR' => array( __( 'Indian rupee', 'wpinterface-travel' ), '₹', 2 ),
			'IQD' => array( __( 'Iraqi dinar', 'wpinterface-travel' ), 'ع.د', 3 ),
			'ISK' => array( __( 'Icelandic króna', 'wpinterface-travel' ), 'kr', 0 ),
			'JMD' => array( __( 'Jamaican dollar', 'wpinterface-travel' ), 'J$', 2 ),
			'JOD' => array( __( 'Jordanian dinar', 'wpinterface-travel' ), 'JD', 3 ),
			'JPY' => array( __( 'Japanese yen', 'wpinterface-travel' ), '¥', 0 ),
			'KES' => array( __( 'Kenyan shilling', 'wpinterface-travel' ), 'KSh', 2 ),
			'KGS' => array( __( 'Kyrgyzstani som', 'wpinterface-travel' ), 'сом', 2 ),
			'KHR' => array( __( 'Cambodian riel', 'wpinterface-travel' ), '៛', 2 ),
			'KRW' => array( __( 'South Korean won', 'wpinterface-travel' ), '₩', 0 ),
			'KWD' => array( __( 'Kuwaiti dinar', 'wpinterface-travel' ), 'د.ك', 3 ),
			'KZT' => array( __( 'Kazakhstani tenge', 'wpinterface-travel' ), '₸', 2 ),
			'LAK' => array( __( 'Lao kip', 'wpinterface-travel' ), '₭', 2 ),
			'LBP' => array( __( 'Lebanese pound', 'wpinterface-travel' ), 'ل.ل', 2 ),
			'LKR' => array( __( 'Sri Lankan rupee', 'wpinterface-travel' ), 'Rs', 2 ),
			'MAD' => array( __( 'Moroccan dirham', 'wpinterface-travel' ), 'د.م.', 2 ),
			'MDL' => array( __( 'Moldovan leu', 'wpinterface-travel' ), 'L', 2 ),
			'MGA' => array( __( 'Malagasy ariary', 'wpinterface-travel' ), 'Ar', 2 ),
			'MKD' => array( __( 'Macedonian denar', 'wpinterface-travel' ), 'ден', 2 ),
			'MMK' => array( __( 'Myanmar kyat', 'wpinterface-travel' ), 'K', 2 ),
			'MNT' => array( __( 'Mongolian tögrög', 'wpinterface-travel' ), '₮', 2 ),
			'MUR' => array( __( 'Mauritian rupee', 'wpinterface-travel' ), '₨', 2 ),
			'MVR' => array( __( 'Maldivian rufiyaa', 'wpinterface-travel' ), 'Rf', 2 ),
			'MXN' => array( __( 'Mexican peso', 'wpinterface-travel' ), 'MX$', 2 ),
			'MYR' => array( __( 'Malaysian ringgit', 'wpinterface-travel' ), 'RM', 2 ),
			'MZN' => array( __( 'Mozambican metical', 'wpinterface-travel' ), 'MT', 2 ),
			'NAD' => array( __( 'Namibian dollar', 'wpinterface-travel' ), 'N$', 2 ),
			'NGN' => array( __( 'Nigerian naira', 'wpinterface-travel' ), '₦', 2 ),
			'NIO' => array( __( 'Nicaraguan córdoba', 'wpinterface-travel' ), 'C$', 2 ),
			'NOK' => array( __( 'Norwegian krone', 'wpinterface-travel' ), 'kr', 2 ),
			'NPR' => array( __( 'Nepalese rupee', 'wpinterface-travel' ), 'रु', 2 ),
			'NZD' => array( __( 'New Zealand dollar', 'wpinterface-travel' ), 'NZ$', 2 ),
			'OMR' => array( __( 'Omani rial', 'wpinterface-travel' ), 'ر.ع.', 3 ),
			'PAB' => array( __( 'Panamanian balboa', 'wpinterface-travel' ), 'B/.', 2 ),
			'PEN' => array( __( 'Peruvian sol', 'wpinterface-travel' ), 'S/', 2 ),
			'PGK' => array( __( 'Papua New Guinean kina', 'wpinterface-travel' ), 'K', 2 ),
			'PHP' => array( __( 'Philippine peso', 'wpinterface-travel' ), '₱', 2 ),
			'PKR' => array( __( 'Pakistani rupee', 'wpinterface-travel' ), '₨', 2 ),
			'PLN' => array( __( 'Polish złoty', 'wpinterface-travel' ), 'zł', 2 ),
			'PYG' => array( __( 'Paraguayan guaraní', 'wpinterface-travel' ), '₲', 0 ),
			'QAR' => array( __( 'Qatari riyal', 'wpinterface-travel' ), 'ر.ق', 2 ),
			'RON' => array( __( 'Romanian leu', 'wpinterface-travel' ), 'lei', 2 ),
			'RSD' => array( __( 'Serbian dinar', 'wpinterface-travel' ), 'дин.', 2 ),
			'RUB' => array( __( 'Russian ruble', 'wpinterface-travel' ), '₽', 2 ),
			'RWF' => array( __( 'Rwandan franc', 'wpinterface-travel' ), 'FRw', 0 ),
			'SAR' => array( __( 'Saudi riyal', 'wpinterface-travel' ), 'ر.س', 2 ),
			'SCR' => array( __( 'Seychellois rupee', 'wpinterface-travel' ), '₨', 2 ),
			'SEK' => array( __( 'Swedish krona', 'wpinterface-travel' ), 'kr', 2 ),
			'SGD' => array( __( 'Singapore dollar', 'wpinterface-travel' ), 'S$', 2 ),
			'THB' => array( __( 'Thai baht', 'wpinterface-travel' ), '฿', 2 ),
			'TND' => array( __( 'Tunisian dinar', 'wpinterface-travel' ), 'د.ت', 3 ),
			'TRY' => array( __( 'Turkish lira', 'wpinterface-travel' ), '₺', 2 ),
			'TTD' => array( __( 'Trinidad and Tobago dollar', 'wpinterface-travel' ), 'TT$', 2 ),
			'TWD' => array( __( 'New Taiwan dollar', 'wpinterface-travel' ), 'NT$', 2 ),
			'TZS' => array( __( 'Tanzanian shilling', 'wpinterface-travel' ), 'TSh', 2 ),
			'UAH' => array( __( 'Ukrainian hryvnia', 'wpinterface-travel' ), '₴', 2 ),
			'UGX' => array( __( 'Ugandan shilling', 'wpinterface-travel' ), 'USh', 0 ),
			'USD' => array( __( 'United States dollar', 'wpinterface-travel' ), '$', 2 ),
			'UYU' => array( __( 'Uruguayan peso', 'wpinterface-travel' ), '$U', 2 ),
			'UZS' => array( __( 'Uzbekistani soʻm', 'wpinterface-travel' ), 'soʻm', 2 ),
			'VND' => array( __( 'Vietnamese đồng', 'wpinterface-travel' ), '₫', 0 ),
			'XAF' => array( __( 'Central African CFA franc', 'wpinterface-travel' ), 'FCFA', 0 ),
			'XCD' => array( __( 'East Caribbean dollar', 'wpinterface-travel' ), 'EC$', 2 ),
			'XOF' => array( __( 'West African CFA franc', 'wpinterface-travel' ), 'CFA', 0 ),
			'XPF' => array( __( 'CFP franc', 'wpinterface-travel' ), '₣', 0 ),
			'ZAR' => array( __( 'South African rand', 'wpinterface-travel' ), 'R', 2 ),
			'ZMW' => array( __( 'Zambian kwacha', 'wpinterface-travel' ), 'ZK', 2 ),
		);

		$currencies = array();

		foreach ( $list as $code => $row ) {
			$currencies[ $code ] = array(
				'name'     => $row[0],
				'symbol'   => $row[1],
				'decimals' => $row[2],
			);
		}

		/**
		 * Filters the supported currencies.
		 *
		 * @param array $currencies Code => array( name, symbol, decimals ).
		 */
		$currencies = (array) apply_filters( 'wpitravel_currencies', $currencies );

		return $currencies;
	}
}
