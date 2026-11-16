<?php
/**
 * Night, Sunday and public holiday work: the hotel's starting figures.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for Pro's Settings → Payroll → Night, Sunday and holiday work
 * (`premiums`) and Public holidays (`holidays`), set through LocalDefaults:
 *
 * - switched on, night 21:00–05:00, each kind a percentage of the hourly
 *   rate: night, Sunday and holiday work +75 %, night on a Sunday or holiday
 *   +100 % (the usual Ivorian increases; the hotel's accountant confirms them,
 *   every figure can be changed in the settings);
 * - the public holidays on a fixed date, every year. The religious ones
 *   (Easter Monday, Ascension, Whit Monday, Ramadan, Tabaski, Maouloud) move
 *   each year: the hotel adds them with that year's date.
 *
 * Only defaults: nothing is written, a saved setting wins.
 */
final class WorkPremiumDefaults {

	/**
	 * The `premiums` default.
	 *
	 * @return array
	 */
	public static function premiums(): array {
		$rate = static fn( $value ) => array(
			'calc'  => 'percent',
			'value' => $value,
		);
		return array(
			'enabled'    => true,
			'nightStart' => '21:00',
			'nightEnd'   => '05:00',
			'rates'      => array(
				'night'   => $rate( 75 ),
				'sunday'  => $rate( 75 ),
				'holiday' => $rate( 75 ),
				'special' => $rate( 100 ),
			),
		);
	}

	/**
	 * The `holidays` default: the fixed-date public holidays, every year.
	 *
	 * @return array[]
	 */
	public static function holidays(): array {
		$year = gmdate( 'Y' );
		$list = array(
			'01-01' => __( 'New Year’s Day', 'radius-hotel-booking-residencetata' ),
			'05-01' => __( 'Labour Day', 'radius-hotel-booking-residencetata' ),
			'08-07' => __( 'Independence Day', 'radius-hotel-booking-residencetata' ),
			'08-15' => __( 'Assumption Day', 'radius-hotel-booking-residencetata' ),
			'11-01' => __( 'All Saints’ Day', 'radius-hotel-booking-residencetata' ),
			'11-15' => __( 'National Peace Day', 'radius-hotel-booking-residencetata' ),
			'12-25' => __( 'Christmas Day', 'radius-hotel-booking-residencetata' ),
		);
		$rows = array();
		foreach ( $list as $day => $name ) {
			$rows[] = array(
				'date'   => $year . '-' . $day,
				'name'   => $name,
				'yearly' => true,
			);
		}
		return $rows;
	}
}
