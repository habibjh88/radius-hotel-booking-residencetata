<?php
/**
 * Côte d'Ivoire payroll figures as settings (the hotel can update them when the law changes).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

use RadiusTheme\RadiusHotelBooking\Helpers\SettingsHelper;
use RadiusTheme\RadiusHotelBooking\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Where the Ivorian calculator's figures live:
 *
 * - the **CNPS contributions** (retirement, family benefits, work accident)
 *   are rows of Pro's Settings → Payroll → Social contributions, found by
 *   code (`CNPS_PENSION`, `CNPS_FAMILY_ALLOWANCE`, `CNPS_WORK_INJURY`); any
 *   other row the hotel adds applies too;
 * - the **DGI taxes** (IS, CN, IGR) are this add-on's Settings → Côte
 *   d'Ivoire payroll (`tata_payroll`).
 *
 * Both start from the values the old system used (fixed in its code, as
 * they were in this add-on before): the defaults below, the import, and a
 * one-time fill of a site imported before this existed.
 */
final class StatutorySettings {

	/**
	 * Option stamped once the contributions are filled on an existing site:
	 * the fingerprint of the rows the fill wrote ('1' when there was nothing
	 * to fill), so an import can still replace them while nobody edited them.
	 */
	const OPTION = 'rtbp_tata_statutory_seeded';

	/**
	 * The old system's work accident rate when it had none saved (percent).
	 */
	const DEFAULT_INJURY_RATE = 2;

	/**
	 * The CNPS rows of Social contributions (rates in percent, monthly caps).
	 *
	 * @param float $injury_rate Work accident / disability rate, percent.
	 * @return array[] `{ code, label, authority, side, rate, cap }`.
	 */
	public static function contributions( float $injury_rate = self::DEFAULT_INJURY_RATE ): array {
		return array(
			array(
				'code'      => 'CNPS_PENSION',
				'label'     => __( 'Retirement fund (employee)', 'radius-hotel-booking-residencetata' ),
				'authority' => 'CNPS',
				'side'      => 'employee',
				'rate'      => 6.3,
				'cap'       => 2700000,
			),
			array(
				'code'      => 'CNPS_PENSION',
				'label'     => __( 'Retirement fund (hotel)', 'radius-hotel-booking-residencetata' ),
				'authority' => 'CNPS',
				'side'      => 'employer',
				'rate'      => 7.7,
				'cap'       => 2700000,
			),
			array(
				'code'      => 'CNPS_FAMILY_ALLOWANCE',
				'label'     => __( 'Family benefits', 'radius-hotel-booking-residencetata' ),
				'authority' => 'CNPS',
				'side'      => 'employer',
				'rate'      => 5.75,
				'cap'       => 70000,
			),
			array(
				'code'      => 'CNPS_WORK_INJURY',
				'label'     => __( 'Work accident / disability insurance', 'radius-hotel-booking-residencetata' ),
				'authority' => 'CNPS',
				'side'      => 'employer',
				'rate'      => $injury_rate,
				'cap'       => 70000,
			),
		);
	}

	/**
	 * The DGI tax settings of `tata_payroll` (percent; brackets `{ upTo, rate }`, monthly).
	 *
	 * @return array<string, array> Key => schema definition.
	 */
	public static function taxSchema(): array {
		$brackets = static function ( array $rows ): array {
			return array_map(
				static fn( $row ) => array(
					'upTo' => null === $row[0] ? '' : $row[0],
					'rate' => $row[1],
				),
				$rows
			);
		};
		$sanitize = class_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Payroll\\PayrollSettings' )
			? array( '\\RadiusTheme\\RadiusHotelBookingPro\\Payroll\\PayrollSettings', 'brackets' )
			: null;
		$scale    = static fn( array $default ) => array(
			'type'     => 'array',
			'default'  => $default,
			'sanitize' => self::guardScale( $sanitize ),
		);
		$mode     = array(
			'type'    => 'enum',
			'options' => array( 'flat', 'progressive' ),
			'default' => 'flat',
		);
		return array(
			// Share of pay (after the employee's contributions) the taxes apply to.
			'taxableRatio'      => array(
				'type'    => 'float',
				'default' => 80,
				'min'     => 0,
				'max'     => 100,
			),
			// Salary tax (IS), on that share.
			'isRate'            => array(
				'type'    => 'float',
				'default' => 1.5,
				'min'     => 0,
				'max'     => 100,
			),
			// National contribution (CN), by monthly brackets of that share.
			'cnMode'            => $mode,
			'cnBrackets'        => $scale(
				$brackets(
					array(
						array( 600000, 0 ),
						array( 1560000, 1.5 ),
						array( 2400000, 5 ),
						array( null, 10 ),
					)
				)
			),
			// General income tax (IGR): its share of what is left after IS and CN, then the brackets.
			'igrRatio'          => array(
				'type'    => 'float',
				'default' => 85,
				'min'     => 0,
				'max'     => 100,
			),
			'igrMode'           => $mode,
			'igrFamilyQuotient' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'igrBrackets'       => $scale(
				$brackets(
					array(
						array( 300000, 0 ),
						array( 547000, 10 ),
						array( 979000, 15 ),
						array( 1519000, 20 ),
						array( 2644000, 25 ),
						array( 4669000, 35 ),
						array( 10106000, 45 ),
						array( null, 60 ),
					)
				)
			),
		);
	}

	/**
	 * The DGI tax settings in force (defaults filled in).
	 *
	 * @return array
	 */
	public static function taxes(): array {
		$saved    = function_exists( 'rtbp_setting' ) && class_exists( SettingsHelper::class )
			? (array) SettingsHelper::get_setting( IvorianPayroll::SECTION )
			: array();
		$defaults = array_map( static fn( $def ) => $def['default'], self::taxSchema() );
		return array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
	}

	/**
	 * Pro's Social contributions rows in force.
	 *
	 * @return array[]
	 */
	public static function contributionRows(): array {
		$rows = function_exists( 'rtbp_setting' ) ? rtbp_setting( 'payroll', 'contributions', array() ) : array();
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * The Social contributions as saved (not the default the add-on gives the
	 * schema): null when never saved, else the saved list (possibly empty).
	 *
	 * @return array[]|null
	 */
	public static function savedContributions(): ?array {
		$saved = get_option( 'rtbp_payroll_settings', null );
		return is_array( $saved ) && array_key_exists( 'contributions', $saved ) && is_array( $saved['contributions'] )
			? array_values( $saved['contributions'] )
			: null;
	}

	/**
	 * Whether Social contributions hold rows the hotel (or an import) saved.
	 *
	 * @return bool
	 */
	public static function hasSavedContributions(): bool {
		return (bool) self::savedContributions();
	}

	/**
	 * Fingerprint of a contributions list.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function fingerprint( array $rows ): string {
		return md5( (string) wp_json_encode( array_values( $rows ) ) );
	}

	/**
	 * Whether the saved contributions are still exactly what the one-time
	 * fill wrote (a site set up before its import: the import may replace
	 * them with the old system's rate).
	 *
	 * @return bool
	 */
	public static function isUntouchedSeed(): bool {
		$saved = self::savedContributions();
		$stamp = (string) get_option( self::OPTION, '' );
		return $saved && 32 === strlen( $stamp ) && hash_equals( $stamp, self::fingerprint( $saved ) );
	}

	/**
	 * The work accident rate saved by the add-on before the contributions
	 * moved to Settings → Payroll (or the old system's default).
	 *
	 * @return float
	 */
	public static function legacyInjuryRate(): float {
		$saved = (array) get_option( 'rtbp_' . IvorianPayroll::SECTION . '_settings', array() );
		return isset( $saved['workInjuryRate'] ) && is_numeric( $saved['workInjuryRate'] ) ? (float) $saved['workInjuryRate'] : (float) self::DEFAULT_INJURY_RATE;
	}

	/**
	 * Fill Social contributions with the CNPS rows when it is empty.
	 *
	 * @param float|null $injury_rate Work accident rate (null: the one saved before).
	 * @return bool Whether it was filled.
	 */
	public static function fillContributions( ?float $injury_rate = null ): bool {
		// What is saved, not the schema default (which is these rows at 2 %).
		if ( ! class_exists( SettingsService::class ) || self::hasSavedContributions() ) {
			return false;
		}
		$rate = min( 5.0, max( 2.0, $injury_rate ?? self::legacyInjuryRate() ) );
		try {
			// Validated and logged like a change made in Settings.
			( new SettingsService() )->updateSection( 'payroll', array( 'contributions' => self::contributions( $rate ) ) );
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Wrap Pro's contributions sanitizer: the CNPS work accident rate must
	 * stay within 2–5 % (the law; the hotel's rate is set by CNPS).
	 *
	 * @param callable|null $inner Pro's sanitizer.
	 * @return callable
	 */
	public static function guardContributions( $inner ): callable {
		return static function ( $value ) use ( $inner ) {
			$clean = is_callable( $inner ) ? call_user_func( $inner, $value ) : $value;
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			foreach ( (array) $clean as $row ) {
				$rate = (float) ( $row['rate'] ?? 0 );
				if ( 'CNPS_WORK_INJURY' === strtoupper( (string) ( $row['code'] ?? '' ) ) && ( $rate < 2 || $rate > 5 ) ) {
					return new \WP_Error( 'rtbp_invalid_setting', __( 'The work accident / disability rate must be between 2 and 5 %.', 'radius-hotel-booking-residencetata' ) );
				}
			}
			return $clean;
		};
	}

	/**
	 * Wrap Pro's brackets sanitizer: a CN or IGR scale needs at least one
	 * bracket (none would tax nothing, silently).
	 *
	 * @param callable|null $inner Pro's sanitizer.
	 * @return callable
	 */
	public static function guardScale( $inner ): callable {
		return static function ( $value ) use ( $inner ) {
			if ( ! is_array( $value ) || ! $value ) {
				return new \WP_Error( 'rtbp_invalid_setting', __( 'Keep at least one bracket in each scale.', 'radius-hotel-booking-residencetata' ) );
			}
			return is_callable( $inner ) ? call_user_func( $inner, $value ) : $value;
		};
	}

	/**
	 * Once per site: fill Social contributions (a site set up or imported
	 * before the CNPS rates were settings). Retried until Pro's payroll
	 * settings exist.
	 *
	 * @return void
	 */
	public static function maybeSeed(): void {
		if ( get_option( self::OPTION ) || ! function_exists( 'rtbp_setting' ) || ! class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema' ) ) {
			return;
		}
		if ( ! \RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema::has( 'payroll' ) ) {
			return;
		}
		// Done when filled (stamped with what was written), or when there was nothing to fill; retried otherwise.
		if ( self::fillContributions() ) {
			update_option( self::OPTION, self::fingerprint( (array) self::savedContributions() ), false );
		} elseif ( self::hasSavedContributions() ) {
			update_option( self::OPTION, '1', false );
		}
	}
}
