<?php
/**
 * Côte d'Ivoire payroll taxes and contributions (M15, 15.14 / 15.15).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

use InvalidArgumentException;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Decimal;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory\AbstractCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Pro's payroll with the Ivorian rules (registered on
 * `rtbp_pro_payroll_calculator`). On the monthly taxable pay M, as the
 * legacy system (`class-request.php` `run_payroll`):
 *
 *    CNPS employee = rate × min( M, cap ); BASE80 = ratio × ( M − CNPS );
 *    IS = rate × BASE80; CN = scale( BASE80 ); T = ratio × ( BASE80 − IS − CN );
 *    IGR = scale( T ) (÷ parts × parts with a family quotient);
 *    employer: pension, family allowance, work injury on min( M, cap ).
 *
 * Rates, caps and scales are in StatutoryTable, versioned by pay date. The
 * hotel's work accident rate (2–5 %) is frozen with each line
 * (`injury_rate`, `rtbp_pro_payroll_statutory_settings`).
 */
final class IvorianCalculator extends AbstractCalculator {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'ci';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Côte d\'Ivoire (CNPS, IS, CN, IGR)', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * Ivorian payroll counts in whole CFA francs, whatever the site's display decimals.
	 *
	 * @return int|null
	 */
	public function places(): ?int {
		return 0;
	}

	/**
	 * Tax authority.
	 *
	 * @return string
	 */
	public function taxAuthority(): string {
		return 'DGI';
	}

	/**
	 * The lines.
	 *
	 * @param string $monthly Monthly taxable pay.
	 * @param array  $input   Input (`pay_date`, `injury_rate` in percent, `parts`).
	 * @return array
	 * @throws InvalidArgumentException On bad input.
	 */
	protected function monthlyLines( string $monthly, array $input ): array {
		$table  = StatutoryTable::for( (string) ( $input['pay_date'] ?? '' ) );
		$injury = Decimal::div( Decimal::of( (string) ( $input['injury_rate'] ?? IvorianPayroll::injuryRate() ) ), '100' );
		if ( Decimal::cmp( $injury, $table['work_injury']['min_rate'] ) < 0 || Decimal::cmp( $injury, $table['work_injury']['max_rate'] ) > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'The work accident rate must be between 2 and 5 %.' );
		}
		$parts = Decimal::of( (string) ( $input['parts'] ?? '1' ) );
		if ( Decimal::cmp( $parts, '1' ) < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Parts must be 1 or more.' );
		}

		$pension  = $table['cnps_pension'];
		$capped   = Decimal::min( $monthly, $pension['cap'] );
		$cnps_emp = Decimal::mul( $capped, $pension['employee_rate'] );
		$base80   = Decimal::mul( Decimal::sub( $monthly, $cnps_emp ), $table['taxable_ratio'] );
		$is       = Decimal::mul( $base80, $table['is_rate'] );
		$cn       = self::scale( $base80, $table['cn']['brackets'], (string) ( $table['cn']['mode'] ?? 'flat' ) );
		$igr_base = Decimal::max( '0', Decimal::mul( Decimal::sub( $base80, Decimal::add( $is, $cn['amount'] ) ), $table['igr']['ratio'] ) );
		$igr_mode = (string) ( $table['igr']['mode'] ?? 'flat' );
		if ( ! empty( $table['igr']['family_quotient'] ) ) {
			$per_part = self::scale( Decimal::div( $igr_base, $parts ), $table['igr']['brackets'], $igr_mode );
			$igr      = array(
				'amount' => Decimal::mul( $per_part['amount'], $parts ),
				'rate'   => $per_part['rate'],
			);
		} else {
			$igr = self::scale( $igr_base, $table['igr']['brackets'], $igr_mode );
		}
		$family     = $table['family_allowance'];
		$family_b   = Decimal::min( $monthly, $family['cap'] );
		$injury_b   = Decimal::min( $monthly, $table['work_injury']['cap'] );
		$monthly_of = array(
			'PENSION_EMPLOYEE' => array( $capped, $pension['employee_rate'], $cnps_emp ),
			'IS'               => array( $base80, $table['is_rate'], $is ),
			'CN'               => array( $base80, $cn['rate'], $cn['amount'] ),
			'IGR'              => array( $igr_base, $igr['rate'], $igr['amount'] ),
			'PENSION_EMPLOYER' => array( $capped, $pension['employer_rate'], Decimal::mul( $capped, $pension['employer_rate'] ) ),
			'FAMILY_ALLOWANCE' => array( $family_b, $family['rate'], Decimal::mul( $family_b, $family['rate'] ) ),
			'WORK_INJURY'      => array( $injury_b, $injury, Decimal::mul( $injury_b, $injury ) ),
		);

		$lines = array();
		foreach ( StatutoryTable::lines() as $key => $meta ) {
			list( $base, $rate, $amount ) = $monthly_of[ $key ];
			$lines[ $key ]                = $meta + array(
				'base'   => $base,
				'rate'   => $rate,
				'amount' => $amount,
			);
		}
		return array(
			'version' => (string) $table['version'],
			'lines'   => $lines,
		);
	}
}
