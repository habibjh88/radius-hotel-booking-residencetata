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
 * The **only** place payroll rates, caps and brackets live (the legacy code
 * kept two copies of the brackets). Versioned by effective date: a run uses
 * the version in force on its pay date, and stores the version it used, so
 * a past payslip never changes when the law does.
 *
 * Amounts are monthly francs; rates are fractions (`0.063` = 6.3 %).
 * A scale is `[ [ up_to|null, rate ], … ]` with a `mode`:
 * - `flat` — the rate of the bracket the base falls in applies to the whole
 *   base (the legacy behaviour, copied: `RTA/payroll/class-payroll-tax.php`);
 * - `progressive` — each slice is taxed at its own rate.
 * `family_quotient` (IGR) divides the taxable base by the employee's parts
 * before the scale and multiplies back; the legacy system has none (false).
 * CN and IGR mode and the quotient are to be confirmed with the client's
 * accountant (M15 acceptance 1): the answer is a change to this table only.
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
	 * Effective date (Y-m-d) => table.
	 *
	 * @return array<string, array>
	 */
	private static function versions(): array {
		return array(
			// As run by the old system until the cutover (verify with the accountant).
			'2000-01-01' => array(
				'version'          => 'ci-legacy',
				'cnps_pension'     => array(
					'cap'           => '2700000',
					'employee_rate' => '0.063',
					'employer_rate' => '0.077',
				),
				'family_allowance' => array(
					'cap'  => '70000',
					'rate' => '0.0575',
				),
				'work_injury'      => array(
					'cap'      => '70000',
					'min_rate' => '0.02',
					'max_rate' => '0.05',
				),
				'taxable_ratio'    => '0.8',
				'is_rate'          => '0.015',
				'cn'               => array(
					'mode'     => 'flat',
					'brackets' => array(
						array( '600000', '0' ),
						array( '1560000', '0.015' ),
						array( '2400000', '0.05' ),
						array( null, '0.10' ),
					),
				),
				'igr'              => array(
					'ratio'           => '0.85',
					'mode'            => 'flat',
					'family_quotient' => false,
					'brackets'        => array(
						array( '300000', '0' ),
						array( '547000', '0.10' ),
						array( '979000', '0.15' ),
						array( '1519000', '0.20' ),
						array( '2644000', '0.25' ),
						array( '4669000', '0.35' ),
						array( '10106000', '0.45' ),
						array( null, '0.60' ),
					),
				),
			),
		);
	}

	/**
	 * The table in force on a date.
	 *
	 * @param string $date Y-m-d (the pay date).
	 * @return array
	 * @throws InvalidArgumentException On a bad date or one before every version.
	 */
	public static function for( string $date ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Use a Y-m-d pay date.' );
		}
		$found = null;
		foreach ( self::versions() as $from => $table ) {
			if ( $from <= $date ) {
				$found = $table + array( 'effective_from' => $from );
			}
		}
		if ( null === $found ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'No statutory table for ' . $date . '.' );
		}
		return $found;
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
