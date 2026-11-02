<?php
/**
 * Links between the hotel dashboard and the Food Menu front-end dashboard.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Settings
 */

namespace RadiusTheme\RadiusHotelBookingTata\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Residence TATA runs both dashboards (R1-T18): the hotel's menu links to the
 * Food Menu dashboard ("Food Order and Inventory", as on the old dashboard)
 * and the Food Menu dashboard's menu links back ("Hotel Dashboard", through
 * Food Menu Pro's `fmp_frontend_dashboard_nav_extra_items`). Each link shows
 * only to people who may open the other dashboard; each page still checks
 * access itself.
 */
final class DashboardLinks {

	/**
	 * Food Menu Pro's helpers (class and method checked before every call).
	 */
	private const FOOD_MENU_HELPERS = '\\RT\\FoodMenuPro\\Helpers\\FnsPro';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'fmp_frontend_dashboard_nav_extra_items', array( self::class, 'foodMenuNav' ) );
	}

	/**
	 * `fmp_frontend_dashboard_nav_extra_items`: "Hotel Dashboard" in the Food Menu dashboard's menu.
	 *
	 * @param array $items Extra nav items.
	 * @return array
	 */
	public static function foodMenuNav( $items ): array {
		$items = (array) $items;
		$url   = self::hotelDashboardUrl();
		if ( '' === $url ) {
			return $items;
		}
		$items[] = array(
			'key'     => 'hotel-dashboard',
			'label'   => __( 'Hotel Dashboard', 'radius-hotel-booking-residencetata' ),
			'url'     => $url,
			// Lucide "bed-double", drawn like the Food Menu dashboard's own icons.
			'iconSvg' => '<path d="M2 20v-8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v8"/><path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"/><path d="M12 4v6"/><path d="M2 18h20"/>',
		);
		return $items;
	}

	/**
	 * The hotel dashboard page, for someone who may open it ('' otherwise).
	 *
	 * @return string
	 */
	public static function hotelDashboardUrl(): string {
		$caps = '\\RadiusTheme\\RadiusHotelBooking\\Core\\Permissions\\Capabilities';
		$view = defined( $caps . '::VIEW_DASHBOARD' ) ? constant( $caps . '::VIEW_DASHBOARD' ) : '';
		if ( ! function_exists( 'rtbp_page_url' ) || ! ( current_user_can( 'manage_options' ) || ( '' !== $view && current_user_can( $view ) ) ) ) {
			return '';
		}
		return (string) rtbp_page_url( 'dashboardPage' );
	}

	/**
	 * The Food Menu front-end dashboard page, for someone who may open it ('' otherwise,
	 * or when Food Menu Pro is not active).
	 *
	 * @return string
	 */
	public static function foodMenuUrl(): string {
		$helpers = self::FOOD_MENU_HELPERS;
		if ( ! class_exists( $helpers ) || ! method_exists( $helpers, 'get_frontend_order_page' ) ) {
			return '';
		}
		$page_id = (int) $helpers::get_frontend_order_page();
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		// Administrators always; others when Food Menu gives them at least one dashboard menu.
		$allowed = current_user_can( 'manage_options' )
			|| ( method_exists( $helpers, 'get_allowed_dashboard_menus' ) && array() !== (array) $helpers::get_allowed_dashboard_menus() );
		return $allowed ? (string) get_permalink( $page_id ) : '';
	}
}
