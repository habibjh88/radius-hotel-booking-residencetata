<?php
/**
 * "Social security" reads "CNPS" for the hotel.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Settings
 */

namespace RadiusTheme\RadiusHotelBookingTata\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * In Côte d'Ivoire the social security office is the CNPS: the free and Pro
 * plugins' generic "Social security" labels (the hotel's employer number in
 * Settings → General, on invoices and payslips; an employee's number in the
 * staff file) read "CNPS" here. PHP strings through the per-domain gettext
 * filters; the dashboard's through the matching JS i18n filters (src/admin/index.js).
 */
final class CnpsLabels {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'gettext_radius-hotel-booking', array( self::class, 'free' ), 10, 2 );
		add_filter( 'gettext_radius-hotel-booking-pro', array( self::class, 'pro' ), 10, 2 );
	}

	/**
	 * Free plugin strings (the invoice).
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @return string
	 */
	public static function free( $translation, $text ) {
		switch ( $text ) {
			case 'Social security number':
				return __( 'CNPS number', 'radius-hotel-booking-residencetata' );
			case 'Social security no. %s':
				/* translators: %s: the hotel's CNPS employer number. */
				return __( 'CNPS no. %s', 'radius-hotel-booking-residencetata' );
		}
		return $translation;
	}

	/**
	 * Pro strings (the payslip, the staff file).
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @return string
	 */
	public static function pro( $translation, $text ) {
		switch ( $text ) {
			case 'Social security number':
				return __( 'CNPS number', 'radius-hotel-booking-residencetata' );
			case 'Social security %s':
				/* translators: %s: a CNPS number. */
				return __( 'CNPS %s', 'radius-hotel-booking-residencetata' );
		}
		return $translation;
	}
}
