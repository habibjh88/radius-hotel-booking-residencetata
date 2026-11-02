<?php
/**
 * Residence TATA's dashboard languages: English and French, as on the old dashboard.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Settings
 */

namespace RadiusTheme\RadiusHotelBookingTata\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The account menu's Language (free `rtbp_dashboard_languages`, R1-T6) offers
 * exactly the two languages of the old hotel dashboard, and a staff member's
 * old choice (`hbfwc_dash_locale`) carries over until they pick again.
 */
final class LocalLanguages {

	/**
	 * The old dashboard's per-user language.
	 */
	private const LEGACY_META = 'hbfwc_dash_locale';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_dashboard_languages', array( self::class, 'languages' ) );
		add_filter( 'rtbp_user_language', array( self::class, 'legacy' ), 10, 2 );
	}

	/**
	 * English and French, with their flags.
	 *
	 * @return array[]
	 */
	public static function languages(): array {
		return array(
			array(
				'locale' => 'en_US',
				'label'  => 'English',
				'flag'   => '🇬🇧',
			),
			array(
				'locale' => 'fr_FR',
				'label'  => 'Français',
				'flag'   => '🇫🇷',
			),
		);
	}

	/**
	 * The old dashboard's choice, while the user has not picked one here.
	 *
	 * @param string $locale  Locale picked in the new dashboard, or ''.
	 * @param int    $user_id User id.
	 * @return string
	 */
	public static function legacy( $locale, $user_id ): string {
		$locale = (string) $locale;
		if ( '' !== $locale || (int) $user_id <= 0 ) {
			return $locale;
		}
		$old = (string) get_user_meta( (int) $user_id, self::LEGACY_META, true );
		return in_array( $old, array( 'en_US', 'fr_FR' ), true ) ? $old : '';
	}
}
