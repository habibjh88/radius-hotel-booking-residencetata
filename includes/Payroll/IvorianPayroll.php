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
 * - Settings → Côte d'Ivoire payroll (section `tata_payroll`, option
 *   `rtbp_tata_payroll_settings`): the DGI tax figures (IS, CN, IGR), saved
 *   with `payroll.tax_settings` like Settings → Payroll.
 *
 * The CNPS contributions are rows of Pro's Social contributions
 * (StatutorySettings fills them once with the old system's values).
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
		// A site set up before the CNPS rates were settings gets them once.
		add_action( 'init', array( StatutorySettings::class, 'maybeSeed' ), 99 );
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
	 * `rtbp_settings_schema_init` listener.
	 *
	 * @return void
	 */
	public static function schema(): void {
		if ( ! class_exists( SettingsSchema::class ) ) {
			return;
		}
		// The DGI tax figures (the work accident rate moved to Social contributions).
		SettingsSchema::register( self::SECTION, StatutorySettings::taxSchema() );
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
