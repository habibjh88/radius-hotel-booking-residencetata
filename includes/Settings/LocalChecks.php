<?php
/**
 * Residence TATA's site checks (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Settings
 */

namespace RadiusTheme\RadiusHotelBookingTata\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The hotel is in Abidjan: the site must run on `Africa/Abidjan` (18.3), or
 * arrivals, payment deadlines and the day boundaries of every report shift.
 * Added to the free plugin's administrator warnings through
 * `rtbp_site_checks`; it replaces the free *fixed UTC offset* warning, which
 * describes the same setting less precisely. (Payroll's bcmath check is Pro's.)
 */
final class LocalChecks {

	public const TIMEZONE = 'Africa/Abidjan';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_site_checks', array( self::class, 'checks' ) );
	}

	/**
	 * `rtbp_site_checks`: warn when the site time zone is not Abidjan.
	 *
	 * @param array $checks Failing checks so far.
	 * @return array
	 */
	public static function checks( $checks ): array {
		$checks = (array) $checks;
		if ( self::TIMEZONE === (string) get_option( 'timezone_string', '' ) ) {
			return $checks;
		}

		$checks   = array_values( array_filter( $checks, static fn( $check ) => 'timezone_offset' !== ( $check['id'] ?? '' ) ) );
		$checks[] = array(
			'id'           => 'tata_timezone',
			'message'      => sprintf(
				/* translators: 1: the site's current time zone, 2: Africa/Abidjan. */
				__( 'The site time zone is %1$s, not %2$s. Arrival times, payment deadlines and reports will be off until it is changed.', 'radius-hotel-booking-residencetata' ),
				wp_timezone_string(),
				self::TIMEZONE
			),
			'action_label' => __( 'Set the time zone to Abidjan', 'radius-hotel-booking-residencetata' ),
			'action_url'   => admin_url( 'options-general.php#timezone_string' ),
		);
		return $checks;
	}
}
