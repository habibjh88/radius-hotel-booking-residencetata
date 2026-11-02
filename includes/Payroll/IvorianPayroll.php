<?php
/**
 * Côte d'Ivoire payroll, plugged into Pro's payroll.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ChangeDiff;
use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Payroll itself is Pro's (feature `payroll`). This add-on supplies:
 *
 * - the Ivorian calculator (`rtbp_pro_payroll_calculator`);
 * - the hotel's work accident rate, 2–5 % (section `tata_payroll`, option
 *   `rtbp_tata_payroll_settings`), saved with `payroll.tax_settings` like
 *   Settings → Payroll, and frozen with each pay run line
 *   (`rtbp_pro_payroll_statutory_settings`).
 *
 * The statutory rates themselves are law, not settings: StatutoryTable.
 */
final class IvorianPayroll {

	public const SECTION = 'tata_payroll';

	public const ACCESS_KEY = 'payroll.tax_settings';

	/**
	 * Hook in (only while Pro's payroll feature is on).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'rtbp_pro_payroll_calculator', static fn() => new IvorianCalculator() );
		add_filter(
			'rtbp_pro_payroll_statutory_settings',
			static function ( $settings ) {
				return array( 'injury_rate' => (string) self::injuryRate() ) + (array) $settings;
			}
		);
		add_action( 'rtbp_settings_schema_init', array( self::class, 'schema' ) );
		add_filter( 'rtbp_settings_access_key', array( self::class, 'accessKey' ), 10, 2 );
		add_action( 'rtbp_settings_updated', array( self::class, 'log' ), 10, 3 );
	}

	/**
	 * Whether Pro's payroll is on (its feature booted before this add-on).
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return did_action( 'rtbp_pro_payroll_booted' ) > 0 && class_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Payroll\\Statutory\\AbstractCalculator' );
	}

	/**
	 * The work accident rate, percent.
	 *
	 * @return float
	 */
	public static function injuryRate(): float {
		return function_exists( 'rtbp_setting' ) ? (float) rtbp_setting( self::SECTION, 'workInjuryRate', 2 ) : 2.0;
	}

	/**
	 * `rtbp_settings_schema_init` listener.
	 *
	 * @return void
	 */
	public static function schema(): void {
		if ( ! class_exists( SettingsSchema::class ) ) {
			return;
		}
		SettingsSchema::register(
			self::SECTION,
			array(
				// Work accident / disability insurance rate, percent of gross (capped at 70 000 a month), 2–5 (15.15).
				'workInjuryRate' => array(
					'type'    => 'float',
					'default' => 2,
					'min'     => 2,
					'max'     => 5,
				),
			)
		);
	}

	/**
	 * `rtbp_settings_access_key`: this section is saved with `payroll.tax_settings`.
	 *
	 * @param string $key     Key.
	 * @param string $section Section.
	 * @return string
	 */
	public static function accessKey( $key, $section ): string {
		return self::SECTION === $section ? self::ACCESS_KEY : (string) $key;
	}

	/**
	 * `rtbp_settings_updated`: log a change of this section.
	 *
	 * @param string $section Section.
	 * @param mixed  $before  Values before.
	 * @param mixed  $after   Values after.
	 * @return void
	 */
	public static function log( $section, $before, $after ): void {
		if ( self::SECTION !== $section || ! function_exists( 'rtbp_activity' ) || ! class_exists( ChangeDiff::class ) ) {
			return;
		}
		$diff = ChangeDiff::between( (array) $before, (array) $after );
		if ( ! $diff['before'] && ! $diff['after'] ) {
			return;
		}
		rtbp_activity(
			self::ACCESS_KEY,
			array(
				'type'  => 'settings',
				'id'    => self::SECTION,
				'label' => __( 'Côte d\'Ivoire payroll settings', 'radius-hotel-booking-residencetata' ),
			),
			$diff
		);
	}
}
