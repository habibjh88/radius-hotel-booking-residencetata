<?php
/**
 * The legacy dashboard protections → the access settings (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\SettingsService;
use RadiusTheme\RadiusHotelBookingPro\Access\AccessSettings;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Import\LegacyAccessMap;

defined( 'ABSPATH' ) || exit;

/**
 * One record (`site`): the legacy site-wide `hbfwc_rta_protected_dashboard`
 * map → the **Staff** role's levels (Settings → Permissions, free
 * `access.roleLevels.rtbp_staff`); `hbfwc_rta_passcode_expiration` → the PIN
 * validity; `hbfwc_rta_passcode_require = yes` (a PIN per page) → the
 * per-key scope; `hbfwc_rta_passcode_fallback` → the fallback PIN, **hashed**
 * by `PasscodeService::set_fallback()` — set once, never in the checksum,
 * the report or the log. Through `SettingsService` (validated, logged).
 * Already-configured staff levels are linked, not overwritten.
 */
final class AccessSettingsImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'access_settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Access settings', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source = $context->source;
		$option = static fn( string $name ) => $source->value( 'SELECT option_value FROM {options} WHERE option_name = %s', array( $name ) );
		$main   = $option( 'hbfwc_rta_protected_dashboard' );
		if ( null === $main ) {
			return;
		}
		$context->report->found( $this->key(), 1 );
		$map      = LegacyAccessMap::translate( maybe_unserialize( (string) $main ), maybe_unserialize( (string) $option( 'hbfwc_rta_protected_dashboard_extra' ) ) );
		$fallback = trim( (string) $option( 'hbfwc_rta_passcode_fallback' ) );
		$validity = (int) $option( 'hbfwc_rta_passcode_expiration' );
		$data     = array(
			'levels'       => $map['levels'],
			'validity'     => $validity > 0 ? min( 240, $validity ) : 5,
			'scope'        => 'yes' === $option( 'hbfwc_rta_passcode_require' ) ? 'per_key' : 'global',
			'has_fallback' => '' !== $fallback,
		);
		if ( $map['unknown'] ) {
			$context->report->issue( $this->key(), 'site', sprintf( /* translators: %s: legacy keys. */ __( 'Not carried over (no matching permission): %s.', 'radius-hotel-booking-residencetata' ), implode( ', ', $map['unknown'] ) ) );
		}
		if ( '' !== $fallback && ! PasscodeService::is_valid_pin( $fallback ) ) {
			$context->report->issue( $this->key(), 'site', __( 'The old fallback PIN is not 4 to 8 digits; it was not carried over. Set one under Settings → Passcodes.', 'radius-hotel-booking-residencetata' ) );
		}

		$settings = new SettingsService();
		$write    = function () use ( $settings, $data ) {
			$levels                = (array) rtbp_setting( 'access', 'roleLevels', array() );
			$levels['rtbp_staff']  = array_merge( (array) ( $levels['rtbp_staff'] ?? array() ), $data['levels'] );
			$settings->updateSection( 'access', array( 'roleLevels' => $levels ) );
			$settings->updateSection(
				AccessSettings::SECTION,
				array(
					'passcodeValidity' => $data['validity'],
					'passcodeScope'    => $data['scope'],
				)
			);
		};

		$this->import(
			$context,
			'site',
			$data,
			static fn() => (array) ( rtbp_setting( 'access', 'roleLevels', array() )['rtbp_staff'] ?? array() ) ? 1 : 0,
			function () use ( $write, $fallback ) {
				$write();
				if ( '' !== $fallback && PasscodeService::is_valid_pin( $fallback ) ) {
					PasscodeService::set_fallback( $fallback );
				}
				return 1;
			},
			function () use ( $write ) {
				$write();
			},
			__( 'The Staff role\'s permissions are already set here; they were kept, not replaced.', 'radius-hotel-booking-residencetata' )
		);
	}
}
