<?php
/**
 * The old system's customer accounts on the new booking form.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Legacy
 */

namespace RadiusTheme\RadiusHotelBookingTata\Legacy;

use RadiusTheme\RadiusHotelBookingTata\Import\Entities\GuestImporter;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The hotel's customers stay WordPress users (role `customer`, username =
 * their phone digits). Free links an account to its guest record the first
 * time it books signed in (Settings → Guest accounts); this tells free what
 * the old system kept in the account that free cannot know:
 *
 * - the identity document (`billing_id_type` / `billing_id_number`, the
 *   legacy type names mapped like the import: `consular` → `consular_card`…);
 * - a `{phone}@residencetata.com` address is the old system's placeholder,
 *   not an e-mail: dropped, and its digits stand in for a missing phone;
 * - usernames are phone numbers as the guest typed them, some from before
 *   Côte d'Ivoire's 2021 change to 10 digits: an Ivorian number is also
 *   looked for in its old 8-digit form (the new one is a 2-digit prefix plus
 *   the old number), with and without 225.
 *
 * Only a filter: with an older free plugin it simply never runs.
 */
final class CustomerProfile {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_account_guest_profile', array( self::class, 'profile' ), 10, 2 );
		add_filter( 'rtbp_account_phone_logins', array( self::class, 'logins' ), 10, 2 );
	}

	/**
	 * `rtbp_account_phone_logins`: an Ivorian number's old 8-digit forms.
	 *
	 * @param string[]    $logins Candidates.
	 * @param string|null $e164   The number in E.164, or null.
	 * @return string[]
	 */
	public static function logins( $logins, $e164 ): array {
		$logins = (array) $logins;
		if ( is_string( $e164 ) && preg_match( '/^\+225(0[157]|2[157])(\d{8})$/', $e164, $m ) ) {
			$logins[] = $m[2];
			$logins[] = '225' . $m[2];
		}
		return $logins;
	}

	/**
	 * `rtbp_account_guest_profile`: complete what free read from the account.
	 *
	 * @param array   $profile `{ first_name, last_name, phone, email, id_type, id_number }`.
	 * @param WP_User $user    Account.
	 * @return array
	 */
	public static function profile( $profile, $user ): array {
		$profile = (array) $profile;
		if ( ! $user instanceof WP_User ) {
			return $profile;
		}

		$digits = GuestImporter::placeholderDigits( (string) ( $profile['email'] ?? '' ) );
		if ( null !== $digits ) {
			$profile['email'] = '';
			if ( '' === trim( (string) ( $profile['phone'] ?? '' ) ) ) {
				$profile['phone'] = $digits;
			}
		}

		if ( '' === trim( (string) ( $profile['id_number'] ?? '' ) ) ) {
			$type   = strtolower( trim( (string) get_user_meta( $user->ID, 'billing_id_type', true ) ) );
			$number = trim( (string) get_user_meta( $user->ID, 'billing_id_number', true ) );
			// A document is a type and a number together.
			if ( '' !== $number && isset( GuestImporter::ID_TYPES[ $type ] ) ) {
				$profile['id_type']   = GuestImporter::ID_TYPES[ $type ];
				$profile['id_number'] = $number;
			}
		}

		return $profile;
	}
}
