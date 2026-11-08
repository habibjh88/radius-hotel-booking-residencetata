<?php
/**
 * Staff photos from the old dashboard.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Legacy
 */

namespace RadiusTheme\RadiusHotelBookingTata\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * The old dashboard kept each staff photo as a media library image in the
 * user meta `hbfwc_profile_image`. The legacy import links it to the staff
 * file; on a site imported before that, this links them once: each staff
 * file without a photo gets its user's old one.
 */
final class StaffPhotos {

	/**
	 * Set once the photos were linked.
	 */
	private const DONE = 'rtbp_tata_staff_photos';

	/**
	 * The old dashboard's user meta.
	 */
	public const META = 'hbfwc_profile_image';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		// On any page an administrator opens (the hotel works in the front-end dashboard).
		add_action( 'init', array( self::class, 'link_once' ), 20 );
	}

	/**
	 * Once: give each staff file without a photo its old one.
	 *
	 * @return void
	 */
	public static function link_once(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || get_option( self::DONE ) ) {
			return;
		}
		$pro = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\';
		// Pro with staff photos (EmployeeResource::avatarUrl()); an older Pro would ignore `avatar_id`.
		if ( ! class_exists( $pro . 'EmployeeRepository' ) || ! class_exists( $pro . 'EmployeeService' ) || ! method_exists( $pro . 'EmployeeResource', 'avatarUrl' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once, over Pro's staff files.
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id FROM %i WHERE avatar_id IS NULL OR avatar_id = 0', \RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeRepository::table() ), ARRAY_A );
		$service = new \RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeService();
		foreach ( (array) $rows as $row ) {
			$photo = (int) get_user_meta( (int) $row['user_id'], self::META, true );
			if ( ! $photo || ! wp_attachment_is_image( $photo ) ) {
				continue;
			}
			try {
				$service->update( (int) $row['id'], array( 'avatar_id' => $photo ) );
			} catch ( \Throwable $e ) {
				unset( $e ); // One file refused (e.g. a protected account); the others still get theirs.
			}
		}
		update_option( self::DONE, 1 );
	}
}
