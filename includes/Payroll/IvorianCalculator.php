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
 *    each contribution = rate × min( M, cap ) (no cap: M);
 *    BASE80 = ratio × ( M − the employee's contributions );
 *    IS = rate × BASE80; CN = scale( BASE80 ); T = ratio × ( BASE80 − IS − CN );
 *    IGR = scale( T ) (÷ parts × parts with a family quotient).
 *
 * The contributions are Pro's Settings → Payroll → Social contributions
 * (CNPS: retirement, family benefits, work accident — and any row the
 * hotel adds); the tax figures are Settings → Côte d'Ivoire payroll
 * (StatutoryTable reads both). Each pay run line keeps what it used.
 */
final class IvorianCalculator extends AbstractCalculator {

	/**
	 * Pro shows Settings → Payroll → Social contributions for this calculator too.
	 *
	 * @return bool
	 */
	public function usesContributions(): bool {
		return true;
	}

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
	 * @param array  $input   Input (`pay_date`, `parts`).
	 * @return array
	 * @throws InvalidArgumentException On bad input.
	 */
	protected function monthlyLines( string $monthly, array $input ): array {
		$table = StatutoryTable::for( (string) ( $input['pay_date'] ?? '' ) );
		$parts = Decimal::of( (string) ( $input['parts'] ?? '1' ) );
		if ( Decimal::cmp( $parts, '1' ) < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Parts must be 1 or more.' );
		}

		// Every contribution on min( pay, its cap ); the employee's come off before the taxes.
		$employee = array();
		$employer = array();
		$withheld = '0';
		foreach ( $table['contributions'] as $row ) {
			$base   = null === $row['cap'] ? $monthly : Decimal::min( $monthly, $row['cap'] );
			$amount = Decimal::mul( $base, $row['rate'] );
			$line   = array(
				'code'      => $row['code'],
				'authority' => $row['authority'],
				'side'      => $row['side'],
				'kind'      => 'contribution',
				'label'     => $row['label'],
				'base'      => $base,
				'rate'      => $row['rate'],
				'amount'    => $amount,
			);
			if ( 'employee' === $row['side'] ) {
				$employee[ $row['key'] ] = $line;
				$withheld                = Decimal::add( $withheld, $amount );
			} else {
				$employer[ $row['key'] ] = $line;
			}
		}

		$base80   = Decimal::mul( Decimal::sub( $monthly, $withheld ), $table['taxable_ratio'] );
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
		$meta  = StatutoryTable::lines();
		$taxes = array(
			'IS'  => $meta['IS'] + array(
				'base'   => $base80,
				'rate'   => $table['is_rate'],
				'amount' => $is,
			),
			'CN'  => $meta['CN'] + array(
				'base'   => $base80,
				'rate'   => $cn['rate'],
				'amount' => $cn['amount'],
			),
			'IGR' => $meta['IGR'] + array(
				'base'   => $igr_base,
				'rate'   => $igr['rate'],
				'amount' => $igr['amount'],
			),
		);

		return array(
			'version' => (string) $table['version'],
			// The employee's contributions, the taxes, then the hotel's (the legacy order).
			'lines'   => $employee + $taxes + $employer,
		);
	}
}
