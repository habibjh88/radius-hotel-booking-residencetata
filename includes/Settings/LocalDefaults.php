<?php
/**
 * Residence TATA's local defaults and numbering rules.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Settings
 */

namespace RadiusTheme\RadiusHotelBookingTata\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What is particular to a hotel in Côte d'Ivoire, kept out of the free
 * plugin (a global product):
 *
 * - Phone fields start on Côte d'Ivoire (`rtbp_settings_schema` default).
 * - Wave and Orange Money are offered as payment methods on a fresh
 *   install, beside cash and bank transfer.
 * - Ivorian numbers (`rtbp_phone_to_e164`): 10 digits since 31 January
 *   2021, a 2-digit operator prefix before the old 8 digits (mobile 01 Moov,
 *   05 MTN, 07 Orange; fixed 21, 25, 27). An old 8-digit **mobile** number
 *   is converted by the published rule — its second digit picks the
 *   operator: 0–3 → 01, 4–6 → 05, 7–9 → 07. An old 8-digit fixed number
 *   (first digit 2 or 3) is not guessed: it is refused.
 */
final class LocalDefaults {

	/**
	 * Côte d'Ivoire's calling code.
	 */
	private const CODE = '225';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_settings_schema', array( self::class, 'defaults' ) );
		add_filter( 'rtbp_phone_to_e164', array( self::class, 'phone' ), 10, 3 );
	}

	/**
	 * `rtbp_settings_schema`: the hotel's defaults (saved settings win).
	 *
	 * @param array $sections Section => key => definition.
	 * @return array
	 */
	public static function defaults( $sections ): array {
		$sections = (array) $sections;
		if ( isset( $sections['phone']['defaultCountry'] ) ) {
			$sections['phone']['defaultCountry']['default'] = 'CI';
		}
		if ( isset( $sections['payments']['methods'] ) ) {
			$sections['payments']['methods']['default'] = array( self::class, 'payment_methods' );
		}
		return $sections;
	}

	/**
	 * Payment methods on a fresh install: the free plugin's, with Wave and
	 * Orange Money after cash.
	 *
	 * @return array[]
	 */
	public static function payment_methods(): array {
		$methods = class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Settings\\PaymentSettings' )
			? \RadiusTheme\RadiusHotelBooking\Settings\PaymentSettings::default_methods()
			: array();
		$local   = array(
			array(
				'key'          => 'wave',
				'label'        => 'Wave',
				/* translators: Keep the {…} merge tags as they are. */
				'instructions' => __( 'Send {amount} by Wave before {deadline}, with {reference} as the message.', 'radius-hotel-booking-residencetata' ),
				'account'      => '',
				'enabled'      => true,
			),
			array(
				'key'          => 'orange_money',
				'label'        => 'Orange Money',
				/* translators: Keep the {…} merge tags as they are. */
				'instructions' => __( 'Send {amount} by Orange Money before {deadline}, with {reference} as the message.', 'radius-hotel-booking-residencetata' ),
				'account'      => '',
				'enabled'      => true,
			),
		);
		array_splice( $methods, $methods ? 1 : 0, 0, $local );
		return $methods;
	}

	/**
	 * `rtbp_phone_to_e164`: Ivorian numbers, written with +225 / 00225, as
	 * 225 + 10 digits without the plus, or without a code when the default
	 * country is Côte d'Ivoire. Other numbers are left to the free rules.
	 *
	 * @param string|false|null $e164    Null, or what an earlier filter decided.
	 * @param string            $raw     As typed.
	 * @param string            $default The default calling code.
	 * @return string|false|null
	 */
	public static function phone( $e164, $raw, $default ) {
		if ( null !== $e164 ) {
			return $e164;
		}
		$raw     = trim( (string) $raw );
		$compact = preg_replace( '/[\s().-]+/', '', $raw );
		$digits  = preg_replace( '/\D+/', '', $raw );

		if ( 0 === strpos( $raw, '+' ) || 0 === strpos( $compact, '00' ) ) {
			if ( 0 === strpos( $compact, '00' ) ) {
				$digits = substr( $digits, 2 );
			}
			if ( 0 !== strpos( $digits, self::CODE ) ) {
				return null; // Another country.
			}
			$national = self::national( substr( $digits, 3 ) );
			return null === $national ? false : '+' . self::CODE . $national;
		}

		if ( self::CODE !== (string) $default ) {
			return null;
		}
		// "225 07 07 12 34 56" typed without the plus.
		if ( 13 === strlen( $digits ) && 0 === strpos( $digits, self::CODE ) ) {
			$digits = substr( $digits, 3 );
		}
		$national = self::national( $digits );
		return null === $national ? false : '+' . self::CODE . $national;
	}

	/**
	 * A 10-digit Ivorian national number from 10 or 8 digits, or null.
	 *
	 * @param string $digits National digits.
	 * @return string|null
	 */
	private static function national( string $digits ): ?string {
		if ( 10 === strlen( $digits ) ) {
			return in_array( substr( $digits, 0, 2 ), array( '01', '05', '07', '21', '25', '27' ), true ) ? $digits : null;
		}
		if ( 8 === strlen( $digits ) ) {
			$first = (int) $digits[0];
			if ( 2 === $first || 3 === $first ) {
				return null; // An old fixed number: its new prefix is not guessed.
			}
			$second = (int) $digits[1];
			$prefix = $second <= 3 ? '01' : ( $second <= 6 ? '05' : '07' );
			return $prefix . $digits;
		}
		return null;
	}
}
