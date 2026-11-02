<?php
/**
 * Each staff member's legacy protections and PIN (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingPro\Access\ProfileFields;
use RadiusTheme\RadiusHotelBookingPro\Access\UserAccessService;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Import\LegacyAccessMap;

defined( 'ABSPATH' ) || exit;

/**
 * Per staff user (M13 migration notes): the user meta
 * `hbfwc_rta_protected_dashboard` (+ `_extra`) → **personal overrides**,
 * only where the person differs from the legacy site map (same effective
 * access, no noise); `edit-profile` closed → every profile field restricted
 * for them; the plaintext `user_passcode` → their PIN, **hashed** by
 * `PasscodeService::set_pin()` (logged `security.pin_changed`, never the
 * value). The PIN is set once, on the first import — it is never part of the
 * checksum, the report or the log, so a later PIN change on either side is
 * not carried over. A user who already has overrides or a PIN here is
 * linked, not changed.
 */
final class StaffAccessImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'staff_access';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Staff permissions and PINs', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'employee', 'access_settings' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source = $context->source;
		$site   = LegacyAccessMap::translate( maybe_unserialize( (string) $source->value( "SELECT option_value FROM {options} WHERE option_name = 'hbfwc_rta_protected_dashboard'" ) ) );
		$rows   = $source->rows(
			"SELECT user_id, meta_key, meta_value FROM {usermeta}
			WHERE meta_key IN ( 'hbfwc_rta_protected_dashboard', 'hbfwc_rta_protected_dashboard_extra', 'user_passcode' )"
		);
		$people = array();
		foreach ( $rows as $row ) {
			$people[ (int) $row['user_id'] ][ $row['meta_key'] ] = (string) $row['meta_value'];
		}
		ksort( $people );
		$context->report->found( $this->key(), count( $people ) );

		$employees = new EmployeeService();
		$access    = new UserAccessService();
		$pins      = 0;
		foreach ( $people as $legacy_id => $m ) {
			$employee_id = $context->map->newId( 'employee', $legacy_id );
			if ( ! $employee_id ) {
				$context->report->count( $this->key(), 'skipped' );
				$context->report->issue( $this->key(), $legacy_id, __( 'Not a staff member who was imported; their permissions and PIN were skipped.', 'radius-hotel-booking-residencetata' ) );
				continue;
			}
			$mine      = LegacyAccessMap::translate( maybe_unserialize( $m['hbfwc_rta_protected_dashboard'] ?? '' ), maybe_unserialize( $m['hbfwc_rta_protected_dashboard_extra'] ?? '' ) );
			$overrides = array_diff_assoc( $mine['levels'], $site['levels'] );
			$pin       = trim( $m['user_passcode'] ?? '' );
			$pin_ok    = PasscodeService::is_valid_pin( $pin );
			if ( '' !== $pin && ! $pin_ok ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'The old PIN is not 4 to 8 digits; it was not carried over. Set a new one for this person.', 'radius-hotel-booking-residencetata' ) );
			}
			$restrict_profile = 'locked' === $mine['edit_profile'];
			$data             = array(
				'overrides'        => $overrides,
				'restrict_profile' => $restrict_profile,
				'has_pin'          => $pin_ok,
			);
			if ( $pin_ok ) {
				++$pins;
			}
			$user_of = static fn() => $employee_id > 0 ? (int) $employees->get( $employee_id )->user_id : 0;
			$apply   = function ( int $user_id ) use ( $access, $overrides, $restrict_profile ) {
				$input = array();
				if ( $overrides ) {
					$input['overrides'] = $overrides;
				}
				if ( $restrict_profile ) {
					$input['profile_fields'] = array_fill_keys( array_keys( ProfileFields::fields() ), ProfileFields::RESTRICT );
				}
				if ( $input ) {
					$access->update( $user_id, $input );
				}
			};

			$this->import(
				$context,
				$legacy_id,
				$data,
				static function () use ( $user_of ) {
					$user_id = $user_of();
					return $user_id && ( UserAccessService::overrides( $user_id ) || PasscodeService::has_pin( $user_id ) ) ? $user_id : 0;
				},
				function () use ( $user_of, $apply, $pin, $pin_ok ) {
					$user_id = $user_of();
					$apply( $user_id );
					if ( $pin_ok ) {
						PasscodeService::set_pin( $user_id, $pin );
					}
					return $user_id;
				},
				function ( int $user_id ) use ( $apply ) {
					$apply( $user_id );
				},
				__( 'This person already has permissions or a PIN here; they were kept, not changed.', 'radius-hotel-booking-residencetata' )
			);
		}

		if ( $pins ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					/* translators: %d: number of PINs. */
					_n( '%d PIN is carried over hashed; the old system kept it in plain text.', '%d PINs are carried over hashed; the old system kept them in plain text.', $pins, 'radius-hotel-booking-residencetata' ),
					$pins
				)
			);
		}
	}
}
