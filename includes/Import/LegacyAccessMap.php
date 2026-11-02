<?php
/**
 * The legacy dashboard protections, translated (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy `hbfwc_rta_protected_dashboard` structure — `pages.section.*`,
 * `pages.analytics.*`, `action.*`, plus the `_extra` actions — mapped onto
 * the access keys (M13 registry table), and its levels: `open` → open,
 * `protected` → passcode, `locked` / `closed` → locked. `edit-profile` is not
 * an access key: it says whether staff may edit their own profile at all.
 */
final class LegacyAccessMap {

	/**
	 * Legacy key (`page:section/x`, `page:analytics/x` or `action:x`) => access key.
	 */
	public const KEYS = array(
		'page:section/rooms'          => 'page.rooms',
		'page:section/customers'      => 'page.guests',
		'page:section/booking'        => 'page.bookings',
		'page:section/availability'   => 'page.availability',
		'page:section/payroll'        => 'page.payroll',
		'page:section/my-payroll'     => 'page.my_payroll',
		'page:section/user-activity'  => 'page.activity_log',
		'page:section/booking-backup' => 'page.exports',
		'page:analytics/sales'        => 'page.reports_sales',
		'page:analytics/rooms'        => 'page.reports_rooms',
		'action:add-booking'          => 'bookings.create',
		'action:approve-booking'      => 'bookings.approve',
		'action:decline-booking'      => 'bookings.decline',
		'action:cancel-booking'       => 'bookings.cancel',
		'action:checked-in'           => 'bookings.check_in',
		'action:check-in'             => 'bookings.check_in',
		'action:checked-out'          => 'bookings.check_out',
		'action:check-out'            => 'bookings.check_out',
		'action:add-booking-item'     => 'bookings.line_add',
		'action:edit-booking-item'    => 'bookings.line_edit',
		'action:remove-booking-item'  => 'bookings.line_remove',
		'action:add-order-note'       => 'bookings.note_add',
		'action:edit-order-note'      => 'bookings.note_edit',
		'action:remove-order-note'    => 'bookings.note_remove',
		'action:mark-as-paid'         => 'payments.record',
		'action:modify-order-status'  => 'payments.change_status',
		'action:edit-customer'        => 'guests.edit',
		'action:ban-users'            => 'guests.ban',
		'action:add-user-note'        => 'guests.note_add',
		'action:edit-user-note'       => 'guests.note_edit',
		'action:remove-user-note'     => 'guests.note_remove',
		'action:add-customer-note'    => 'guests.note_add',
		'action:edit-customer-note'   => 'guests.note_edit',
		'action:remove-customer-note' => 'guests.note_remove',
		'action:manage-room-number'   => 'rooms.manage',
		'action:generate-booking-backup' => 'exports.generate',
		'action:download-booking-backup' => 'exports.download',
		'action:remove-booking-backup'   => 'exports.delete',
		'action:add-new-staff'        => 'staff.create',
		'action:edit-staff'           => 'staff.edit',
		'action:remove-staff'         => 'staff.deactivate',
		'action:manage-contracts'     => 'staff.contracts',
		'action:add-staff-note'       => 'staff.note_add',
		'action:edit-staff-note'      => 'staff.note_edit',
		'action:remove-staff-note'    => 'staff.note_remove',
		'action:run-payroll'          => 'payroll.run',
		'action:finalize-payroll'     => 'payroll.finalize',
		'action:settle-payroll'       => 'payroll.settle',
		'action:void-payroll'         => 'payroll.void',
		'action:revert-payroll'       => 'payroll.revert',
		'action:remove-payroll-run'   => 'payroll.remove',
		'action:edit-payroll-item'    => 'payroll.edit_line',
		'action:manage-pay-schedules' => 'payroll.schedules',
		'action:manage-work-shifts'   => 'payroll.shifts',
		'action:record-statutory-payment' => 'payroll.statutory_pay',
		'action:edit-tax-info'        => 'payroll.tax_settings',
		'action:approve-time-off'     => 'timeoff.approve',
		'action:edit-business-info'   => 'settings.general',
	);

	/**
	 * A legacy level as an access level, or null when unknown.
	 *
	 * @param mixed $level Legacy level.
	 * @return string|null
	 */
	public static function level( $level ): ?string {
		$map = array(
			'open'      => 'open',
			'protected' => 'passcode',
			'locked'    => 'locked',
			'closed'    => 'locked',
		);
		return $map[ strtolower( trim( (string) $level ) ) ] ?? null;
	}

	/**
	 * Translate a legacy protections structure (and its `_extra` actions).
	 *
	 * @param mixed $main  `{ pages: { section: {…}, analytics: {…} }, action: {…} }`.
	 * @param mixed $extra `{ action-key: level }`.
	 * @return array{levels: array<string,string>, unknown: string[], edit_profile: string|null}
	 *               `levels` only keys the registry knows (else in `unknown`).
	 */
	public static function translate( $main, $extra = array() ): array {
		$flat = array();
		$main = is_array( $main ) ? $main : array();
		foreach ( (array) ( $main['pages'] ?? array() ) as $group => $pages ) {
			foreach ( (array) $pages as $page => $level ) {
				$flat[ 'page:' . $group . '/' . $page ] = $level;
			}
		}
		foreach ( array_merge( (array) ( $main['action'] ?? array() ), is_array( $extra ) ? $extra : array() ) as $action => $level ) {
			$flat[ 'action:' . $action ] = $level;
		}

		$out = array(
			'levels'       => array(),
			'unknown'      => array(),
			'edit_profile' => null,
		);
		foreach ( $flat as $legacy => $raw ) {
			$level = self::level( $raw );
			if ( 'action:edit-profile' === $legacy ) {
				$out['edit_profile'] = $level;
				continue;
			}
			$key = self::KEYS[ $legacy ] ?? null;
			$has = $key && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Access\\AccessRegistry' ) && \RadiusTheme\RadiusHotelBooking\Access\AccessRegistry::has( $key );
			if ( null === $level || ! $has ) {
				$out['unknown'][] = $legacy . '=' . (string) $raw;
				continue;
			}
			$out['levels'][ $key ] = $level;
		}
		ksort( $out['levels'] );
		return $out;
	}
}
