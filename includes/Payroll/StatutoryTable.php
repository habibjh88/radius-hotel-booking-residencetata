<?php
/**
 * Côte d'Ivoire statutory rates, caps and scales (M15, 15.14).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The figures the Ivorian calculator uses on a pay date, read from settings
 * (StatutorySettings): the CNPS contributions from Pro's Settings → Payroll
 * → Social contributions, the DGI taxes from Settings → Côte d'Ivoire
 * payroll. They start from the old system's values.
 *
 * Amounts are monthly francs; rates are fractions (`0.063` = 6.3 %).
 * A scale is `[ [ up_to|null, rate ], … ]` with a `mode`:
 * - `flat` — the rate of the bracket the base falls in applies to the whole
 *   base (the legacy behaviour, copied: `RTA/payroll/class-payroll-tax.php`);
 * - `progressive` — each slice is taxed at its own rate.
 * `family_quotient` (IGR) divides the taxable base by the employee's parts
 * before the scale and multiplies back; the legacy system has none (false).
 */
final class StatutoryTable {

	/**
	 * Pay intervals → the monthly factor (monthly amount = per-period amount × factor).
	 */
	public const INTERVALS = array(
		'weekly'       => '52/12',
		'biweekly'     => '26/12',
		'twicemonthly' => '2',
		'monthly'      => '1',
	);

	/**
	 * The table on a pay date: today's settings (Social contributions and
	 * Settings → Côte d'Ivoire payroll, StatutorySettings). A pay run line
	 * keeps the figures it used, so a past payslip never changes when the
	 * settings do; `version` is `ci-legacy` while every figure is still the
	 * old system's, else a short fingerprint of them.
	 *
	 * `contributions` lists every Social contributions row
	 * `{ key, code, label, authority, side, rate (fraction), cap|null }`; the
	 * CNPS ones keep their legacy line keys (PENSION_EMPLOYEE…).
	 *
	 * @param string $date Y-m-d (the pay date).
	 * @return array
	 * @throws InvalidArgumentException On a bad date.
	 */
	public static function for( string $date ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Use a Y-m-d pay date.' );
		}
		$taxes    = StatutorySettings::taxes();
		$fraction = static fn( $percent ) => self::fraction( $percent );
		$scale    = static fn( $rows ) => array_map(
			static fn( $row ) => array(
				'' === (string) ( $row['upTo'] ?? '' ) ? null : self::number( $row['upTo'] ),
				$fraction( $row['rate'] ?? 0 ),
			),
			array_values( (array) $rows )
		);
		$table    = array(
			'contributions' => self::contributions( StatutorySettings::contributionRows() ),
			'taxable_ratio' => $fraction( $taxes['taxableRatio'] ),
			'is_rate'       => $fraction( $taxes['isRate'] ),
			'cn'            => array(
				'mode'     => 'progressive' === $taxes['cnMode'] ? 'progressive' : 'flat',
				'brackets' => $scale( $taxes['cnBrackets'] ),
			),
			'igr'           => array(
				'ratio'           => $fraction( $taxes['igrRatio'] ),
				'mode'            => 'progressive' === $taxes['igrMode'] ? 'progressive' : 'flat',
				'family_quotient' => ! empty( $taxes['igrFamilyQuotient'] ),
				'brackets'        => $scale( $taxes['igrBrackets'] ),
			),
		);
		$legacy  = self::fingerprint( self::legacy() );
		$current = self::fingerprint( $table );
		return $table + array(
			'version'        => $legacy === $current ? 'ci-legacy' : 'ci-' . substr( $current, 0, 10 ),
			'effective_from' => $date,
		);
	}

	/**
	 * The Social contributions rows as the calculator reads them.
	 *
	 * @param array[] $rows Rows `{ code, label, authority, side, rate (%), cap }`.
	 * @return array[]
	 */
	private static function contributions( array $rows ): array {
		$keys = array(
			'CNPS_PENSION|employee'          => 'PENSION_EMPLOYEE',
			'CNPS_PENSION|employer'          => 'PENSION_EMPLOYER',
			'CNPS_FAMILY_ALLOWANCE|employer' => 'FAMILY_ALLOWANCE',
			'CNPS_WORK_INJURY|employer'      => 'WORK_INJURY',
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$code  = strtoupper( (string) ( $row['code'] ?? '' ) );
			$side  = 'employer' === ( $row['side'] ?? '' ) ? 'employer' : 'employee';
			$cap   = (float) ( $row['cap'] ?? 0 );
			$out[] = array(
				// Another row's key cannot match a CNPS one (`C_` prefix); code + side is unique (Pro's sanitizer).
				'key'       => $keys[ $code . '|' . $side ] ?? 'C_' . $code . '_' . strtoupper( $side ),
				'code'      => $code,
				'label'     => (string) ( $row['label'] ?? $code ),
				'authority' => (string) ( $row['authority'] ?? '' ),
				'side'      => $side,
				'rate'      => self::fraction( $row['rate'] ?? 0 ),
				'cap'       => $cap > 0 ? self::number( $cap ) : null,
			);
		}
		return $out;
	}

	/**
	 * The old system's figures (fixed in its code), to tell whether today's
	 * settings still match them. The work accident rate is the hotel's own.
	 *
	 * @return array
	 */
	private static function legacy(): array {
		$taxes = array_map( static fn( $def ) => $def['default'], StatutorySettings::taxSchema() );
		$rows  = StatutorySettings::contributions( self::injuryRate( StatutorySettings::contributionRows() ) );
		$scale = static fn( $rows ) => array_map(
			static fn( $row ) => array(
				'' === (string) $row['upTo'] ? null : self::number( $row['upTo'] ),
				self::fraction( $row['rate'] ),
			),
			$rows
		);
		return array(
			'contributions' => self::contributions( $rows ),
			'taxable_ratio' => self::fraction( $taxes['taxableRatio'] ),
			'is_rate'       => self::fraction( $taxes['isRate'] ),
			'cn'            => array(
				'mode'     => 'flat',
				'brackets' => $scale( $taxes['cnBrackets'] ),
			),
			'igr'           => array(
				'ratio'           => self::fraction( $taxes['igrRatio'] ),
				'mode'            => 'flat',
				'family_quotient' => false,
				'brackets'        => $scale( $taxes['igrBrackets'] ),
			),
		);
	}

	/**
	 * The work accident rate among the rows (percent), else the old default.
	 *
	 * @param array[] $rows Social contributions rows.
	 * @return float
	 */
	private static function injuryRate( array $rows ): float {
		foreach ( $rows as $row ) {
			if ( 'CNPS_WORK_INJURY' === strtoupper( (string) ( $row['code'] ?? '' ) ) ) {
				return (float) ( $row['rate'] ?? 0 );
			}
		}
		return (float) StatutorySettings::DEFAULT_INJURY_RATE;
	}

	/**
	 * The figures that change a result, hashed (labels left out).
	 *
	 * @param array $table Table.
	 * @return string
	 */
	private static function fingerprint( array $table ): string {
		foreach ( $table['contributions'] as &$row ) {
			unset( $row['label'], $row['authority'], $row['key'] );
		}
		unset( $row );
		return md5( (string) wp_json_encode( $table ) );
	}

	/**
	 * A percent as a fraction string: `6.3` → `0.063`.
	 *
	 * @param mixed $percent Percent.
	 * @return string
	 */
	private static function fraction( $percent ): string {
		return self::number( (float) $percent / 100 );
	}

	/**
	 * A number as a plain decimal string (no exponent, no trailing zeros).
	 *
	 * @param mixed $value Number.
	 * @return string
	 */
	private static function number( $value ): string {
		$text = rtrim( rtrim( number_format( (float) $value, 8, '.', '' ), '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}

	/**
	 * The statutory lines: code, authority, side and label (translatable, for payslips and reports).
	 *
	 * @return array<string, array{code:string, authority:string, side:string, kind:string, label:string}>
	 */
	public static function lines(): array {
		return array(
			'PENSION_EMPLOYEE' => array(
				'code'      => 'CNPS_PENSION',
				'authority' => 'CNPS',
				'side'      => 'employee',
				'kind'      => 'contribution',
				'label'     => __( 'Retirement fund', 'radius-hotel-booking-residencetata' ),
			),
			'IS'               => array(
				'code'      => 'IS',
				'authority' => 'DGI',
				'side'      => 'employee',
				'kind'      => 'tax',
				'label'     => __( 'Salary tax (IS)', 'radius-hotel-booking-residencetata' ),
			),
			'CN'               => array(
				'code'      => 'CN',
				'authority' => 'DGI',
				'side'      => 'employee',
				'kind'      => 'contribution',
				'label'     => __( 'National contribution (CN)', 'radius-hotel-booking-residencetata' ),
			),
			'IGR'              => array(
				'code'      => 'IGR',
				'authority' => 'DGI',
				'side'      => 'employee',
				'kind'      => 'tax',
				'label'     => __( 'General income tax (IGR)', 'radius-hotel-booking-residencetata' ),
			),
			'PENSION_EMPLOYER' => array(
				'code'      => 'CNPS_PENSION',
				'authority' => 'CNPS',
				'side'      => 'employer',
				'kind'      => 'contribution',
				'label'     => __( 'Retirement fund', 'radius-hotel-booking-residencetata' ),
			),
			'FAMILY_ALLOWANCE' => array(
				'code'      => 'CNPS_FAMILY_ALLOWANCE',
				'authority' => 'CNPS',
				'side'      => 'employer',
				'kind'      => 'contribution',
				'label'     => __( 'Family benefits', 'radius-hotel-booking-residencetata' ),
			),
			'WORK_INJURY'      => array(
				'code'      => 'CNPS_WORK_INJURY',
				'authority' => 'CNPS',
				'side'      => 'employer',
				'kind'      => 'contribution',
				'label'     => __( 'Work accident / disability insurance', 'radius-hotel-booking-residencetata' ),
			),
		);
	}
}
