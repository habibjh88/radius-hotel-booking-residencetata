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
 * `rtbp_pro_payroll_bonus_kinds`): 1 % of the base pay for each full year of
 * service, at most 25 % (the hotel's rule, R2-T7; the ceiling is the
 * interprofessional collective agreement's). Years run from the hiring date —
 * the employee's first contract start — so the rate rises on each work
 * anniversary and the first pay after it carries the new percentage.
 *
 * The hotel has one such rule for every employee: `ensure_rule()` adds it once
 * when none exists, so seniority is paid without setting it up by hand.
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
	 * Set once the seniority rule was added (or found).
	 */
	private const RULE_DONE = 'rtbp_tata_seniority_rule';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_pro_payroll_bonus_kinds', array( self::class, 'kinds' ) );
		add_filter( 'rtbp_pro_payroll_bonus_resolve', array( self::class, 'resolve' ), 10, 4 );
		// On any page an administrator opens (the hotel works in the front-end dashboard).
		add_action( 'init', array( self::class, 'ensure_rule' ), 20 );
	}

	/**
	 * Once: add a taxable "Seniority bonus" rule for every employee when the
	 * site has no seniority rule (Pro's Settings → Payroll → Bonuses).
	 *
	 * @return void
	 */
	public static function ensure_rule(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pro  = '\\RadiusTheme\\RadiusHotelBookingPro\\Payroll\\PayrollSettings';
		$free = '\\RadiusTheme\\RadiusHotelBooking\\';
		if ( get_option( self::RULE_DONE ) || ! class_exists( $pro ) || ! method_exists( $pro, 'get' ) || ! defined( $pro . '::SECTION' )
			|| ! class_exists( $free . 'Core\\Container\\Container' ) || ! class_exists( $free . 'Services\\SettingsService' ) ) {
			return;
		}
		$rules = (array) $pro::get( 'bonusRules', array() );
		foreach ( $rules as $rule ) {
			if ( self::KIND === ( $rule['kind'] ?? '' ) ) {
				update_option( self::RULE_DONE, 1 );
				return;
			}
		}
		$rules[] = array(
			'label'   => __( 'Seniority bonus', 'radius-hotel-booking-residencetata' ),
			'kind'    => self::KIND,
			'taxable' => true,
			'applies' => 'all',
			'active'  => true,
		);
		try {
			\RadiusTheme\RadiusHotelBooking\Core\Container\Container::resolve( \RadiusTheme\RadiusHotelBooking\Services\SettingsService::class )
				->updateSection( constant( $pro . '::SECTION' ), array( 'bonusRules' => $rules ) );
			update_option( self::RULE_DONE, 1 );
		} catch ( \Throwable $e ) {
			// Tried again on the next admin page; it can also be added in Settings → Payroll → Bonuses.
			unset( $e );
		}
	}

	/**
	 * `rtbp_pro_payroll_bonus_kinds`.
	 *
	 * @param array $kinds Key => label.
	 * @return array
	 */
	public static function kinds( $kinds ): array {
		$kinds               = (array) $kinds;
		$kinds[ self::KIND ] = __( 'Seniority bonus (1 % per year since hiring, max 25 %)', 'radius-hotel-booking-residencetata' );
		return $kinds;
	}

	/**
	 * `rtbp_pro_payroll_bonus_resolve`: the employee's percentage, or null in their first year.
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
	 * The percentage for full years of service: 1 % a year, at most 25.
	 *
	 * @param int $years Full years.
	 * @return int
	 */
	public static function rate( int $years ): int {
		return max( 0, min( 25, $years ) );
	}

	/**
	 * Full years from the hiring date (the first contract start) to the pay
	 * date, 0 without a contract.
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
