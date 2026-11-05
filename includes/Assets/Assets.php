<?php
/**
 * Client add-on admin assets.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Assets
 */

namespace RadiusTheme\RadiusHotelBookingTata\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the add-on's admin bundle into the free plugin's admin app, after
 * the free app and after Pro's bundle, so `window.rtbp` and Pro's JS filters
 * are in place when it runs.
 */
final class Assets {

	/**
	 * Script handle.
	 */
	const HANDLE = 'radius-hotel-booking-residencetata-admin';

	/**
	 * Hook in. Priority 20 runs after Pro, which enqueues on the default 10.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rtbp_admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 20 );
		// Free merges every JSON / .mo of this domain for the bundle (free 1.0.21+; older free ignores it).
		add_filter( 'rtbp_script_translation_domains', array( self::class, 'translation_domain' ) );
	}

	/**
	 * `rtbp_script_translation_domains`: this add-on's text domain and languages folder.
	 *
	 * @param array $domains Text domain => languages folder.
	 * @return array
	 */
	public static function translation_domain( $domains ): array {
		$domains                                       = (array) $domains;
		$domains['radius-hotel-booking-residencetata'] = RADIUS_HOTEL_BOOKING_TATA_DIR . 'languages';
		return $domains;
	}

	/**
	 * Enqueue the admin bundle.
	 *
	 * @param string $free_handle The free admin app's script handle.
	 * @return void
	 */
	public function enqueue_admin( $free_handle ): void {
		$asset_file = RADIUS_HOTEL_BOOKING_TATA_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return; // Not built yet: run `bun run build` in the add-on.
		}

		$asset = require $asset_file;
		$deps  = array_merge( (array) ( $asset['dependencies'] ?? array() ), array( (string) $free_handle ) );

		// Load after Pro's bundle when it is there (it may be unbuilt in dev).
		$pro_assets = '\\RadiusTheme\\RadiusHotelBookingPro\\Assets\\Assets';
		if ( class_exists( $pro_assets ) && defined( $pro_assets . '::HANDLE' ) && wp_script_is( $pro_assets::HANDLE, 'enqueued' ) ) {
			$deps[] = $pro_assets::HANDLE;
		}

		wp_enqueue_script(
			self::HANDLE,
			RADIUS_HOTEL_BOOKING_TATA_URL . 'build/admin.js',
			array_unique( $deps ),
			$asset['version'] ?? RADIUS_HOTEL_BOOKING_TATA_VERSION,
			true
		);

		// What the add-on's screens need to know about the current user.
		wp_localize_script(
			self::HANDLE,
			'rtbp_tata',
			array(
				// The legacy import screen (M18): administrators only, enforced by the API too.
				'can_import' => current_user_can( 'manage_options' ),
				// The Côte d'Ivoire payroll settings tab: only with Pro's payroll.
				'ivorian_payroll' => \RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll::active(),
				// The built-in rates it lists, read-only (R1, as the legacy Tax Info tab).
				'statutory_rates' => \RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll::active() ? \RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll::statutoryRates() : array(),
				// The Food Menu dashboard, for the sidebar link ('' when not available to this user, R1-T18).
				'food_menu_url'   => \RadiusTheme\RadiusHotelBookingTata\Settings\DashboardLinks::foodMenuUrl(),
			)
		);

		wp_set_script_translations( self::HANDLE, 'radius-hotel-booking-residencetata', RADIUS_HOTEL_BOOKING_TATA_DIR . 'languages' );
	}
}
