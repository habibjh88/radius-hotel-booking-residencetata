<?php
/**
 * The Côte d'Ivoire seniority bonus (prime d'ancienneté) as a Pro bonus rule kind.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Payroll → Bonuses gets a "Seniority bonus" kind (R1-T17, Pro's
 * `rtbp_pro_payroll_bonus_kinds`): after 2 full years of service, 2 % of the
 * base pay, plus 1 % for each further year, at most 25 % — the rule of the
 * interprofessional collective agreement. Years of service run from the
 * employee's first contract start to the pay date. Our design until the hotel
 * sends its own bonus list (Q3).
 */
final class SeniorityBonus {

	/**
	 * The kind's key.
	 */
	public const KIND = 'seniority_ci';

	/**
	 * Pro's contracts repository (class checked before use).
	 */
	private const CONTRACTS = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeContractRepository';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_pro_payroll_bonus_kinds', array( self::class, 'kinds' ) );
		add_filter( 'rtbp_pro_payroll_bonus_resolve', array( self::class, 'resolve' ), 10, 4 );
	}

	/**
	 * `rtbp_pro_payroll_bonus_kinds`.
	 *
	 * @param array $kinds Key => label.
	 * @return array
	 */
	public static function kinds( $kinds ): array {
		$kinds               = (array) $kinds;
		$kinds[ self::KIND ] = __( 'Seniority bonus (2 % after 2 years, +1 % a year, max 25 %)', 'radius-hotel-booking-residencetata' );
		return $kinds;
	}

	/**
	 * `rtbp_pro_payroll_bonus_resolve`: the employee's percentage, or null under 2 years.
	 *
	 * @param array|null $resolved    Resolved so far.
	 * @param array      $rule        Rule.
	 * @param int        $employee_id Employee id.
	 * @param string     $pay_date    `Y-m-d`.
	 * @return array|null
	 */
	public static function resolve( $resolved, $rule, $employee_id, $pay_date ) {
		if ( self::KIND !== ( $rule['kind'] ?? '' ) ) {
			return $resolved;
		}
		$years = self::yearsOfService( (int) $employee_id, (string) $pay_date );
		$rate  = self::rate( $years );
		if ( $rate <= 0 ) {
			return null;
		}
		return array(
			'kind'  => 'percent',
			'value' => (string) $rate,
			/* translators: 1: bonus name, 2: percentage, 3: years of service. */
			'label' => sprintf( __( '%1$s (%2$d %%, %3$d years)', 'radius-hotel-booking-residencetata' ), (string) ( $rule['label'] ?? '' ), $rate, $years ),
		);
	}

	/**
	 * The percentage for full years of service: 0 under 2, then 2 + (years − 2), at most 25.
	 *
	 * @param int $years Full years.
	 * @return int
	 */
	public static function rate( int $years ): int {
		return $years < 2 ? 0 : min( 25, $years );
	}

	/**
	 * Full years from the first contract start to the pay date (0 without a contract).
	 *
	 * @param int    $employee_id Employee id.
	 * @param string $pay_date    `Y-m-d`.
	 * @return int
	 */
	public static function yearsOfService( int $employee_id, string $pay_date ): int {
		$repo = self::CONTRACTS;
		if ( ! class_exists( $repo ) || ! method_exists( $repo, 'forEmployee' ) ) {
			return 0;
		}
		$first = '';
		foreach ( ( new $repo() )->forEmployee( $employee_id ) as $contract ) {
			$start = substr( (string) ( $contract->start_date ?? '' ), 0, 10 );
			if ( '' !== $start && ( '' === $first || $start < $first ) ) {
				$first = $start;
			}
		}
		if ( '' === $first || $first > $pay_date ) {
			return 0;
		}
		return (int) ( new \DateTimeImmutable( $first ) )->diff( new \DateTimeImmutable( $pay_date ) )->y;
	}
}
