<?php
/**
 * Plugin Name:       Radius Hotel Booking - Residence TATA
 * Description:       Residence TATA's own features for Radius Hotel Booking (payroll and HR, the data migration, local settings). Built for this hotel only.
 * Plugin URI:        https://radiustheme.com
 * Version:           1.0.0
 * Author:            RadiusTheme
 * Author URI:        https://radiustheme.com
 * Text Domain:       radius-hotel-booking-residencetata
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.5
 * Requires Plugins:  radius-hotel-booking, radius-hotel-booking-pro
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package RadiusTheme\RadiusHotelBookingTata
 */

defined( 'ABSPATH' ) || exit;

define( 'RADIUS_HOTEL_BOOKING_TATA_VERSION', '1.0.0' );
define( 'RADIUS_HOTEL_BOOKING_TATA_DB_VERSION', '1.0.10' );
define( 'RADIUS_HOTEL_BOOKING_TATA_FILE', __FILE__ );
define( 'RADIUS_HOTEL_BOOKING_TATA_DIR', plugin_dir_path( __FILE__ ) );
define( 'RADIUS_HOTEL_BOOKING_TATA_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( RADIUS_HOTEL_BOOKING_TATA_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Radius Hotel Booking - Residence TATA is missing its vendor folder. Run `composer install` in the plugin directory.', 'radius-hotel-booking-residencetata' ) . '</p></div>';
		}
	);
	return;
}

require_once RADIUS_HOTEL_BOOKING_TATA_DIR . 'vendor/autoload.php';

RadiusTheme\RadiusHotelBookingTata\Plugin::instance();
