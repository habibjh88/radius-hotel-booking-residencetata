<?php
/**
 * Guests from the legacy WooCommerce customers (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Repositories\GuestRepository;
use RadiusTheme\RadiusHotelBooking\Services\Guests\GuestService;
use RadiusTheme\RadiusHotelBooking\Support\Phone;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy users with the `customer` role → guests (M09 migration notes,
 * importer rule 7), through `GuestService`:
 *
 * - name from `billing_first_name` / `billing_last_name` (else the profile
 *   name), phone `billing_phone`, e-mail `billing_email` (else the account
 *   e-mail), `billing_id_type` / `billing_id_number` (`consular` →
 *   `consular_card`, `certificate` → `id_certificate`), `_hbfwc_banned` → a ban;
 * - a legacy **placeholder** e-mail (`{phone}@residencetata.com`) counts as
 *   no e-mail — the new system makes and flags its own — and, when the phone
 *   is missing, gives the phone back;
 * - **duplicates** (same phone in any form, or the same real e-mail) are
 *   merged: the second legacy customer is linked to the first guest — or to
 *   a guest already on file — and reported.
 *
 * - an account that **never booked and holds no details** (no order, no
 *   billing name, phone or document: sign-up bots of WooCommerce's My
 *   account page) is skipped and reported, not made a guest;
 * - the guest is **linked to the customer's WordPress account** (the same
 *   user on the live site, a user with the same login or e-mail on a copy),
 *   and that account gets the free plugin's Hotel Guest role next to
 *   WooCommerce's Customer, which stays. No account is created and no
 *   password changes.
 *
 * Guests who booked without an account appear only on their orders; the
 * bookings import creates them when it needs them (T5b).
 */
final class GuestImporter extends AbstractImporter {

	/**
	 * Whether the legacy users are this site's own users (null = decide from the prefix).
	 *
	 * @var bool|null
	 */
	private ?bool $same_site;

	/**
	 * Constructor.
	 *
	 * @param bool|null $same_site Force the account mode (checks); null = from the source prefix.
	 */
	public function __construct( ?bool $same_site = null ) {
		$this->same_site = $same_site;
	}

	/**
	 * Legacy document type => new key.
	 */
	public const ID_TYPES = array(
		'cni'            => 'cni',
		'cni_receipt'    => 'cni_receipt',
		'passport'       => 'passport',
		'consular'       => 'consular_card',
		'consular_card'  => 'consular_card',
		'certificate'    => 'id_certificate',
		'id_certificate' => 'id_certificate',
		'driver_license' => 'driver_license',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'guest';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Guests', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array();
	}

	/**
	 * Whether an e-mail is a legacy placeholder; returns its digits (or '').
	 *
	 * @param string $email E-mail.
	 * @return string|null Digits when a placeholder, null otherwise.
	 */
	public static function placeholderDigits( string $email ): ?string {
		return preg_match( '/^\+?([0-9 ]{6,})@residencetata\.com$/i', trim( $email ), $m ) ? str_replace( ' ', '', $m[1] ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source  = $context->source;
		$service = new GuestService();
		$repo    = new GuestRepository();
		$cap     = $source->metaPrefix() . 'capabilities';
		$keys    = array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_id_type', 'billing_id_number', '_hbfwc_banned' );

		$users = $source->rows(
			'SELECT u.ID, u.user_login, u.user_email, u.display_name FROM {users} u
			JOIN {usermeta} c ON c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s
			ORDER BY u.ID',
			array( $cap, '%"customer"%' )
		);
		$meta = array();
		foreach ( $source->rows(
			'SELECT m.user_id, m.meta_key, m.meta_value FROM {usermeta} m
			JOIN {usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s AND c.meta_value LIKE %s
			WHERE m.meta_key IN ( ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' )',
			array_merge( array( $cap, '%"customer"%' ), $keys )
		) as $row ) {
			$meta[ (int) $row['user_id'] ][ $row['meta_key'] ] = (string) $row['meta_value'];
		}
		$context->report->found( $this->key(), count( $users ) );

		// Customers who placed an order: an account without one, and without details, never booked.
		$ordered = array();
		if ( $source->has( 'wc_orders' ) ) {
			foreach ( $source->rows( 'SELECT DISTINCT customer_id FROM {wc_orders} WHERE customer_id > 0' ) as $row ) {
				$ordered[ (int) $row['customer_id'] ] = true;
			}
		}
		global $wpdb;
		$same_site = $this->same_site ?? ( $source->prefix() === $wpdb->prefix );
		$links     = array(
			'linked'  => 0,
			'role'    => 0,
			'other'   => 0,
			'no_role' => '' === self::guestRole(),
			'account' => array(), // Guest id (planned ones too) => the account linked to it in this run.
		);

		$seen         = array(); // Phone / e-mail keys of this run => [ guest id (planned in a dry run), legacy id, name ].
		$placeholders = 0;
		$all          = array();
		foreach ( $users as $user ) {
			$all[ (int) $user['ID'] ] = $this->data( $user, $meta[ (int) $user['ID'] ] ?? array(), $placeholders );
		}
		// An e-mail that several different people share in the old system (the desk typed the
		// hotel's own for walk-ins) is nobody's: not matched, not stored, like a placeholder.
		$shared = self::sharedEmails( $all );
		$cleared = 0;
		foreach ( $users as $user ) {
			$id   = (int) $user['ID'];
			$data = $all[ $id ];
			if ( ! isset( $ordered[ $id ] ) && self::unused( $meta[ $id ] ?? array() ) ) {
				$context->report->count( $this->key(), 'skipped' );
				$context->report->issue(
					$this->key(),
					$id,
					sprintf(
						/* translators: %s: account username. */
						__( 'Not imported: the account %s never booked and has no name, phone or document (often a sign-up bot). Check it in the old system.', 'radius-hotel-booking-residencetata' ),
						$user['user_login']
					)
				);
				continue;
			}
			if ( '' !== $data['email'] && isset( $shared[ strtolower( $data['email'] ) ] ) ) {
				$data['email'] = '';
				++$cleared;
			}
			$match = self::matchKeys( $data );
			$why   = self::problem( $data );
			if ( '' !== $why ) {
				// Refused the same way GuestService would refuse it, so a dry run shows it too.
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $id, $why, 'error' );
				continue;
			}

			// Who this customer is a duplicate of: an earlier customer of this run, or a guest on file.
			// One phone or e-mail is one guest here; a different name is merged too, but reported.
			$name  = trim( $data['first_name'] . ' ' . $data['last_name'] );
			$fresh = 0 === $context->map->newId( $this->key(), $id );
			$hit   = 0;
			$with  = '';
			$same  = true;
			foreach ( self::seenKeys( $match ) as $key ) {
				if ( isset( $seen[ $key ] ) ) {
					list( $hit, $first, $other ) = $seen[ $key ];
					$same                        = self::sameName( $name, $other );
					$what                        = 'm' === $key[0] ? __( 'e-mail', 'radius-hotel-booking-residencetata' ) : __( 'phone', 'radius-hotel-booking-residencetata' );
					$with                        = $same
						/* translators: 1: legacy customer id, 2: what matched. */
						? sprintf( __( 'Same %2$s as legacy customer #%1$d; merged into that guest.', 'radius-hotel-booking-residencetata' ), $first, $what )
						/* translators: 1: legacy customer id, 2: what matched, 3: this customer's name, 4: the other name. */
						: sprintf( __( 'Same %2$s as legacy customer #%1$d but another name (%3$s, not %4$s); merged into that guest. Check they are the same person.', 'radius-hotel-booking-residencetata' ), $first, $what, $name, $other );
					break;
				}
			}
			if ( ! $hit ) {
				$found = $repo->duplicates( $match['e164'] ?? null, $match['tail'] ?? '', $match['email'] ?? null );
				if ( $found ) {
					$hit   = (int) $found[0]['id'];
					$other = trim( $found[0]['first_name'] . ' ' . $found[0]['last_name'] );
					$same  = self::sameName( $name, $other );
					$what  = 'email' === $found[0]['match'] ? __( 'e-mail', 'radius-hotel-booking-residencetata' ) : __( 'phone', 'radius-hotel-booking-residencetata' );
					$with  = $same
						/* translators: 1: guest reference, 2: what matched. */
						? sprintf( __( 'Same %2$s as guest %1$s already on file; linked, not changed.', 'radius-hotel-booking-residencetata' ), $found[0]['reference'], $what )
						/* translators: 1: guest reference, 2: what matched, 3: this customer's name, 4: the guest's name. */
						: sprintf( __( 'Same %2$s as guest %1$s already on file but another name (%3$s, not %4$s); linked, not changed. Check they are the same person.', 'radius-hotel-booking-residencetata' ), $found[0]['reference'], $what, $name, $other );
				}
			}

			$guest_id = $this->import(
				$context,
				$id,
				$data,
				static fn() => $hit,
				function () use ( $service, $data ) {
					$guest = $service->create( self::fields( $data ) );
					if ( $data['banned'] ) {
						$service->ban( (int) $guest->id, __( 'Banned in the old system.', 'radius-hotel-booking-residencetata' ) );
					}
					return (int) $guest->id;
				},
				function ( int $guest_id ) use ( $service, $data ) {
					$service->update( $guest_id, self::fields( $data ) );
					$guest = $service->get( $guest_id );
					if ( $data['banned'] && 'banned' !== (string) $guest->standing ) {
						$service->ban( $guest_id, __( 'Banned in the old system.', 'radius-hotel-booking-residencetata' ) );
					}
				},
				$with
			);
			if ( 0 !== $guest_id ) {
				$account = $same_site ? $id : self::accountFor( $user );
				$this->linkAccount( $context, $user, $guest_id, $account, $links );
				self::forgetUser( $account );
				foreach ( self::seenKeys( $match, true ) as $key ) {
					$seen[ $key ] = $seen[ $key ] ?? array( $guest_id, $id, $name );
				}
			}
			// A banned customer merged into another guest: the ban follows when it is the same person.
			if ( $fresh && $data['banned'] && 0 !== $hit && $guest_id === $hit ) {
				$this->carryBan( $context, $service, $id, $hit, $same );
			}
		}

		$this->reportLinks( $context, $links );
		if ( $cleared ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					/* translators: %d: number of guests. */
					_n( '%d guest used an e-mail address that several different people share in the old system; it is not kept.', '%d guests used e-mail addresses that several different people share in the old system; they are not kept.', $cleared, 'radius-hotel-booking-residencetata' ),
					$cleared
				)
			);
		}
		if ( $placeholders ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					/* translators: %d: number of guests. */
					_n( '%d guest had a placeholder e-mail in the old system; they are marked as having no e-mail.', '%d guests had a placeholder e-mail in the old system; they are marked as having no e-mail.', $placeholders, 'radius-hotel-booking-residencetata' ),
					$placeholders
				)
			);
		}
	}

	/**
	 * Whether a customer's account holds no detail a booking leaves: no
	 * billing name, phone or document.
	 *
	 * @param array $m User meta.
	 * @return bool
	 */
	private static function unused( array $m ): bool {
		foreach ( array( 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_id_number' ) as $key ) {
			if ( '' !== trim( (string) ( $m[ $key ] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The free plugin's guest role, when this site has it ('' = an older free
	 * plugin: accounts are linked without it).
	 *
	 * @return string
	 */
	private static function guestRole(): string {
		$constant = '\\RadiusTheme\\RadiusHotelBooking\\Core\\Permissions\\Capabilities::GUEST_ROLE';
		$role     = defined( $constant ) ? (string) constant( $constant ) : '';
		return '' !== $role && get_role( $role ) ? $role : '';
	}

	/**
	 * On a copy: this site's user with the legacy login or real e-mail (0 = none).
	 *
	 * @param array $user Legacy user row.
	 * @return int
	 */
	private static function accountFor( array $user ): int {
		$found = get_user_by( 'login', (string) $user['user_login'] );
		if ( ! $found && '' !== (string) $user['user_email'] && null === self::placeholderDigits( (string) $user['user_email'] ) ) {
			$found = get_user_by( 'email', (string) $user['user_email'] );
		}
		return $found ? (int) $found->ID : 0;
	}

	/**
	 * Drop a user WordPress loaded for linking from the in-memory cache. Each
	 * one keeps all its meta there until the request ends, and a shop with
	 * thousands of customers ran a dry run out of memory.
	 *
	 * @param int $user_id User id (0 = none).
	 * @return void
	 */
	private static function forgetUser( int $user_id ): void {
		if ( $user_id > 0 ) {
			wp_cache_delete( $user_id, 'users' );
			wp_cache_delete( $user_id, 'user_meta' );
		}
	}

	/**
	 * Link the guest to the customer's account and give it the guest role.
	 * A guest already linked to another account (a merged duplicate with an
	 * account of its own), or an account another guest has, is left as it is.
	 * Runs on every run, so it is also done for guests imported earlier.
	 *
	 * @param ImportContext $context  The run.
	 * @param array         $user     Legacy user row.
	 * @param int           $guest_id Guest (negative: planned by this dry run).
	 * @param int           $account  This site's user id (0 = none).
	 * @param array         $links    Counts, updated.
	 * @return void
	 */
	private function linkAccount( ImportContext $context, array $user, int $guest_id, int $account, array &$links ): void {
		if ( $account <= 0 || ! get_userdata( $account ) ) {
			return;
		}
		$repo  = new GuestRepository();
		$guest = $guest_id > 0 ? $repo->find( $guest_id ) : null;
		$owner = method_exists( $repo, 'findByUser' ) ? $repo->findByUser( $account ) : null;
		$taken = ( $guest && (int) $guest->wp_user_id && (int) $guest->wp_user_id !== $account ) || ( $owner && (int) $owner->id !== $guest_id )
			// A guest this dry run only plans has no row to look at: what this run linked counts.
			|| ( isset( $links['account'][ $guest_id ] ) && $links['account'][ $guest_id ] !== $account );
		if ( $taken ) {
			++$links['other'];
			return;
		}
		$links['account'][ $guest_id ] = $account;
		$role     = self::guestRole();
		$wp       = get_userdata( $account );
		$link     = ! $guest || (int) $guest->wp_user_id !== $account;
		$add_role = '' !== $role && ! in_array( $role, (array) $wp->roles, true );
		$links['linked'] += (int) $link;
		$links['role']   += (int) $add_role;
		if ( $context->dry_run || ! $guest ) {
			return;
		}
		if ( $link ) {
			$repo->update( $guest_id, array( 'wp_user_id' => $account ) );
		}
		if ( $add_role ) {
			$wp->add_role( $role ); // Next to Customer: the shop keeps working for them.
		}
	}

	/**
	 * The accounts summary of the run.
	 *
	 * @param ImportContext $context The run.
	 * @param array         $links   Counts.
	 * @return void
	 */
	private function reportLinks( ImportContext $context, array $links ): void {
		if ( $links['linked'] || $links['role'] ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					$context->dry_run
						/* translators: 1: number of guests, 2: number of accounts. */
						? _n( '%1$d guest will be linked to their WordPress account (%2$d given the Hotel Guest role).', '%1$d guests will be linked to their WordPress accounts (%2$d given the Hotel Guest role).', $links['linked'], 'radius-hotel-booking-residencetata' )
						/* translators: 1: number of guests, 2: number of accounts. */
						: _n( '%1$d guest was linked to their WordPress account (%2$d given the Hotel Guest role).', '%1$d guests were linked to their WordPress accounts (%2$d given the Hotel Guest role).', $links['linked'], 'radius-hotel-booking-residencetata' ),
					$links['linked'],
					$links['role']
				)
			);
		}
		if ( $links['other'] ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					/* translators: %d: number of accounts. */
					_n( '%d account was not linked: its guest is linked to another account of the same person (a merged duplicate), or the account belongs to another guest.', '%d accounts were not linked: their guest is linked to another account of the same person (a merged duplicate), or the account belongs to another guest.', $links['other'], 'radius-hotel-booking-residencetata' ),
					$links['other']
				)
			);
		}
		if ( $links['no_role'] && ( $links['linked'] || $links['other'] ) ) {
			$context->report->issue( $this->key(), '', __( 'The Hotel Guest role is missing: update Radius Hotel Booking and run the import again to give it to the linked accounts.', 'radius-hotel-booking-residencetata' ) );
		}
	}

	/**
	 * E-mail addresses that three or more different names use (lower case => true).
	 *
	 * @param array[] $all Guest data by legacy id.
	 * @return array<string, bool>
	 */
	private static function sharedEmails( array $all ): array {
		$names = array();
		foreach ( $all as $data ) {
			if ( '' === $data['email'] ) {
				continue;
			}
			$email = strtolower( $data['email'] );
			$name  = trim( $data['first_name'] . ' ' . $data['last_name'] );
			$known = false;
			foreach ( $names[ $email ] ?? array() as $other ) {
				if ( self::sameName( $name, $other ) ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				$names[ $email ][] = $name;
			}
		}
		$shared = array();
		foreach ( $names as $email => $list ) {
			if ( count( $list ) >= 3 ) {
				$shared[ $email ] = true;
			}
		}
		return $shared;
	}

	/**
	 * Apply a merged customer's ban to the guest it was merged into, or report
	 * why not (another name: banning would hit someone else).
	 *
	 * @param ImportContext $context  The run.
	 * @param GuestService  $service  Guests.
	 * @param int           $legacy   Legacy customer id.
	 * @param int           $guest_id Guest merged into (negative: planned by this dry run).
	 * @param bool          $same     Whether the names match.
	 * @return void
	 */
	private function carryBan( ImportContext $context, GuestService $service, int $legacy, int $guest_id, bool $same ): void {
		if ( ! $same ) {
			$context->report->issue( $this->key(), $legacy, __( 'Banned in the old system, but merged into a guest with another name; not banned here. Check by hand.', 'radius-hotel-booking-residencetata' ) );
			return;
		}
		$context->report->issue( $this->key(), $legacy, __( 'Banned in the old system; the guest it was merged into is banned too.', 'radius-hotel-booking-residencetata' ) );
		if ( $context->dry_run || $guest_id <= 0 ) {
			return;
		}
		try {
			if ( 'banned' !== (string) $service->get( $guest_id )->standing ) {
				$service->ban( $guest_id, __( 'Banned in the old system.', 'radius-hotel-booking-residencetata' ) );
			}
		} catch ( DomainException $e ) {
			$context->report->issue( $this->key(), $legacy, self::reason( $e ), 'error' );
		}
	}

	/**
	 * The guest's data from the legacy user.
	 *
	 * @param array $user          User row.
	 * @param array $m             User meta.
	 * @param int   $placeholders  Count of placeholder e-mails (incremented).
	 * @return array
	 */
	private function data( array $user, array $m, int &$placeholders ): array {
		$first = self::text( '' !== trim( $m['billing_first_name'] ?? '' ) ? $m['billing_first_name'] : ( $m['first_name'] ?? '' ) );
		$last  = self::text( '' !== trim( $m['billing_last_name'] ?? '' ) ? $m['billing_last_name'] : ( $m['last_name'] ?? '' ) );
		if ( '' === $first && '' === $last ) {
			$first = self::text( $user['display_name'] );
		}
		$phone  = trim( (string) ( $m['billing_phone'] ?? '' ) );
		$email  = trim( '' !== trim( $m['billing_email'] ?? '' ) ? $m['billing_email'] : (string) $user['user_email'] );
		$digits = self::placeholderDigits( $email );
		if ( null !== $digits ) {
			++$placeholders;
			$email = '';
			$phone = '' !== $phone ? $phone : $digits;
		}
		$type = strtolower( trim( (string) ( $m['billing_id_type'] ?? '' ) ) );
		return array(
			'first_name' => $first,
			'last_name'  => $last,
			'phone'      => $phone,
			'email'      => $email,
			'id_type'    => self::ID_TYPES[ $type ] ?? '',
			'id_number'  => trim( (string) ( $m['billing_id_number'] ?? '' ) ),
			'banned'     => in_array( strtolower( trim( (string) ( $m['_hbfwc_banned'] ?? '' ) ) ), array( '1', 'yes', 'true', 'on' ), true ),
		);
	}

	/**
	 * Why GuestService would refuse this guest ('' = it would not): no name,
	 * a phone it cannot read, neither phone nor e-mail, an invalid e-mail.
	 *
	 * @param array $data Guest data.
	 * @return string
	 */
	private static function problem( array $data ): string {
		if ( '' === $data['first_name'] ) {
			return __( 'The customer has no name.', 'radius-hotel-booking-residencetata' );
		}
		if ( '' !== $data['phone'] && null === Phone::toE164( $data['phone'] ) && '' === Phone::tail( $data['phone'] ) ) {
			/* translators: %s: phone number as stored. */
			return sprintf( __( 'The phone number "%s" cannot be read.', 'radius-hotel-booking-residencetata' ), $data['phone'] );
		}
		if ( '' !== $data['email'] && ! is_email( sanitize_email( $data['email'] ) ) ) {
			/* translators: %s: e-mail as stored. */
			return sprintf( __( 'The e-mail "%s" is not valid.', 'radius-hotel-booking-residencetata' ), $data['email'] );
		}
		if ( '' === $data['phone'] && '' === $data['email'] ) {
			return __( 'The customer has neither a phone number nor an e-mail.', 'radius-hotel-booking-residencetata' );
		}
		return '';
	}

	/**
	 * The GuestService fields (a document number without a known type is dropped).
	 *
	 * @param array $data Guest data.
	 * @return array
	 */
	private static function fields( array $data ): array {
		$fields = array_intersect_key( $data, array_flip( array( 'first_name', 'last_name', 'phone', 'email', 'id_type', 'id_number' ) ) );
		if ( '' === $fields['id_type'] ) {
			$fields['id_number'] = '';
		}
		return $fields;
	}

	/**
	 * This run's duplicate keys, mirroring `GuestRepository::duplicates()`:
	 * the same E.164 number; the same last 8 digits when **either** number has
	 * no E.164; the same real e-mail. `$store` gives the keys a guest is filed
	 * under, otherwise the keys to look up.
	 *
	 * @param array $match Match keys.
	 * @param bool  $store Keys to store (true) or to look up (false).
	 * @return string[]
	 */
	private static function seenKeys( array $match, bool $store = false ): array {
		$keys = array();
		$tail = $match['tail'] ?? '';
		if ( isset( $match['e164'] ) ) {
			$keys[] = 'e:' . $match['e164'];
			if ( '' !== $tail ) {
				// Filed: found by a later number without E.164. Looked up: finds an earlier one without E.164.
				$keys[] = ( $store ? 'te:' : 'tn:' ) . $tail;
			}
		} elseif ( '' !== $tail ) {
			if ( $store ) {
				$keys[] = 'tn:' . $tail;
			} else {
				$keys[] = 'tn:' . $tail;
				$keys[] = 'te:' . $tail;
			}
		}
		if ( isset( $match['email'] ) ) {
			$keys[] = 'm:' . $match['email'];
		}
		return $keys;
	}

	/**
	 * The keys a duplicate is matched on.
	 *
	 * @param array $data Guest data.
	 * @return array<string, string> `e164`, `tail`, `email` (those present).
	 */
	private static function matchKeys( array $data ): array {
		$keys = array();
		if ( '' !== $data['phone'] ) {
			$e164 = Phone::toE164( $data['phone'] );
			$tail = Phone::tail( $data['phone'] );
			if ( null !== $e164 ) {
				$keys['e164'] = $e164;
			}
			if ( '' !== $tail ) {
				$keys['tail'] = $tail;
			}
		}
		if ( '' !== $data['email'] && is_email( $data['email'] ) ) {
			$keys['email'] = strtolower( $data['email'] );
		}
		return $keys;
	}
}
