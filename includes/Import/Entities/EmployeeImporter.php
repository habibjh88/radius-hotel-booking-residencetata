<?php
/**
 * Employee files from the legacy staff users (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingPro\Staff\Employee;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeRepository;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Staff\StaffNames;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy users with the `staff` role → Pro employee files (M12 migration
 * notes): names, `staff_dob`, `staff_address`, `staff_em_contact` (`relasi`
 * → relationship), the phone (`billing_phone`, else a placeholder e-mail
 * holding a full number), the account e-mail (not a placeholder), and from
 * the latest legacy contract the CNPS / tax numbers, department and job
 * title. `_hbfwc_staff_banned` → the file deactivated.
 *
 * **The user account**: on production (same database) the legacy user
 * already exists — its file is attached to it (`EmployeeService::attach()`,
 * Pro), the account and its password unchanged. On a copy (another table
 * prefix) a user with the same login or e-mail is used, else one is created
 * with a random password (`create()`); passwords never move between sites.
 * A user that already has a file is linked. **An employee without a phone is
 * imported and flagged** (user decision 2026-10-04); the screens still ask
 * for one when the file is edited.
 */
final class EmployeeImporter extends AbstractImporter {

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
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'employee';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Staff files', 'radius-hotel-booking-residencetata' );
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
		global $wpdb;
		$source    = $context->source;
		$service   = new EmployeeService();
		$repo      = new EmployeeRepository();
		$same_site = $this->same_site ?? ( $source->prefix() === $wpdb->prefix );
		$cap       = $source->metaPrefix() . 'capabilities';
		$keys      = array( 'first_name', 'last_name', 'billing_phone', 'staff_dob', 'staff_address', 'staff_em_contact', '_hbfwc_staff_banned', 'hbfwc_profile_image' );

		$users = $source->rows(
			'SELECT u.ID, u.user_login, u.user_email, u.display_name FROM {users} u
			JOIN {usermeta} c ON c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s ORDER BY u.ID',
			array( $cap, '%"staff"%' )
		);
		$meta = array();
		foreach ( $source->rows(
			'SELECT m.user_id, m.meta_key, m.meta_value FROM {usermeta} m
			JOIN {usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s AND c.meta_value LIKE %s
			WHERE m.meta_key IN ( ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' )',
			array_merge( array( $cap, '%"staff"%' ), $keys )
		) as $row ) {
			$meta[ (int) $row['user_id'] ][ $row['meta_key'] ] = (string) $row['meta_value'];
		}
		$contracts = array();
		if ( $source->has( 'hbfwc_payroll_contracts' ) ) {
			foreach ( $source->rows( 'SELECT user_id, job_title, department, cnps, tax_number FROM {hbfwc_payroll_contracts} ORDER BY hire_date, id' ) as $row ) {
				$contracts[ (int) $row['user_id'] ] = $row; // The latest wins.
			}
		}
		$context->report->found( $this->key(), count( $users ) );

		foreach ( $users as $user ) {
			$id   = (int) $user['ID'];
			$data = $this->data( $user, $meta[ $id ] ?? array(), $contracts[ $id ] ?? array() );
			if ( '' === $data['first_name'] || '' === $data['last_name'] ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $id, sprintf( /* translators: %s: login. */ __( 'Staff user %s has no first and last name.', 'radius-hotel-booking-residencetata' ), $user['user_login'] ), 'error' );
				continue;
			}
			if ( '' === $data['phone'] ) {
				$context->report->issue( $this->key(), $id, sprintf( /* translators: %s: name. */ __( '%s has no phone number in the old system; add it to the staff file.', 'radius-hotel-booking-residencetata' ), $data['first_name'] . ' ' . $data['last_name'] ) );
			}

			// The account this file belongs to here.
			$account = $same_site ? $id : $this->accountFor( $user );
			$fields  = $data;
			unset( $fields['banned'] );
			// The staff photo: a media library image of this site, so only on the same site.
			$photo = $same_site ? (int) ( $meta[ $id ]['hbfwc_profile_image'] ?? 0 ) : 0;
			if ( $photo && wp_attachment_is_image( $photo ) ) {
				$fields['avatar_id'] = $photo;
			}

			$this->import(
				$context,
				$id,
				$data + array( 'login' => $user['user_login'] ),
				function () use ( $repo, $account ) {
					$file = $account ? $repo->forUser( $account ) : null;
					return $file ? (int) $file->id : 0;
				},
				function () use ( $service, $fields, $account, $user, $data ) {
					if ( $account ) {
						$employee = $service->attach( $account, $fields, array( 'phone_optional' => true ) );
					} else {
						$employee = $service->create(
							$fields + array(
								'username' => sanitize_user( (string) $user['user_login'], true ),
								'password' => '',
							),
							array( 'phone_optional' => true )
						);
					}
					if ( $data['banned'] ) {
						$service->deactivate( (int) $employee->id, __( 'Banned in the old system.', 'radius-hotel-booking-residencetata' ) );
					}
					return (int) $employee->id;
				},
				function ( int $employee_id ) use ( $service, $fields, $data ) {
					$update = $fields;
					if ( '' === $update['phone'] ) {
						unset( $update['phone'] ); // Never blank a phone someone added here.
					}
					if ( ! empty( $service->get( $employee_id )->avatar_id ) ) {
						unset( $update['avatar_id'] ); // Never replace a photo chosen here.
					}
					$service->update( $employee_id, $update );
					if ( $data['banned'] && $service->get( $employee_id )->is_active ) {
						$service->deactivate( $employee_id, __( 'Banned in the old system.', 'radius-hotel-booking-residencetata' ) );
					}
				},
				sprintf( /* translators: %s: login. */ __( 'User %s already has a staff file; it was linked, not changed.', 'radius-hotel-booking-residencetata' ), $user['user_login'] )
			);
		}
	}

	/**
	 * On a copy: this site's user with the legacy login or e-mail (0 = none, one will be created).
	 *
	 * @param array $user Legacy user row.
	 * @return int
	 */
	private function accountFor( array $user ): int {
		$found = get_user_by( 'login', (string) $user['user_login'] );
		if ( ! $found && '' !== (string) $user['user_email'] && null === GuestImporter::placeholderDigits( (string) $user['user_email'] ) ) {
			$found = get_user_by( 'email', (string) $user['user_email'] );
		}
		return $found ? (int) $found->ID : 0;
	}

	/**
	 * The file fields from the legacy user, meta and latest contract.
	 *
	 * @param array $user     User row.
	 * @param array $m        User meta.
	 * @param array $contract Latest contract.
	 * @return array
	 */
	private function data( array $user, array $m, array $contract ): array {
		$email  = trim( (string) $user['user_email'] );
		$digits = GuestImporter::placeholderDigits( $email );
		$phone  = trim( (string) ( $m['billing_phone'] ?? '' ) );
		if ( null !== $digits ) {
			$email = '';
			// A placeholder only gives a phone when it holds a full number (not 565@…).
			if ( '' === $phone && strlen( $digits ) >= 10 ) {
				$phone = $digits;
			}
		}

		$contact   = maybe_unserialize( (string) ( $m['staff_em_contact'] ?? '' ) );
		if ( ! is_array( $contact ) ) {
			$contact = json_decode( (string) ( $m['staff_em_contact'] ?? '' ), true );
			$contact = is_array( $contact ) ? $contact : array();
		}
		$relation  = strtolower( trim( (string) ( $contact['relationship'] ?? $contact['relasi'] ?? '' ) ) );
		$emergency = array(
			'full_name'    => self::text( $contact['full_name'] ?? '' ),
			'address'      => self::text( $contact['address'] ?? '' ),
			'phone'        => trim( (string) ( $contact['phone'] ?? '' ) ),
			'relationship' => '' === $relation ? '' : ( in_array( $relation, Employee::RELATIONSHIPS, true ) ? $relation : 'other' ),
		);
		$dob = trim( (string) ( $m['staff_dob'] ?? '' ) );

		return array(
			'first_name' => self::text( $m['first_name'] ?? '' ),
			'last_name'  => self::text( $m['last_name'] ?? '' ),
			'dob'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ? $dob : '',
			'address'    => self::text( $m['staff_address'] ?? '' ),
			'phone'      => $phone,
			'email'      => $email,
			'emergency'  => $emergency,
			'cnps_no'    => trim( (string) ( $contract['cnps'] ?? '' ) ),
			'tax_no'     => trim( (string) ( $contract['tax_number'] ?? '' ) ),
			// The hotel's names, not the old free-typed variants (StaffNames).
			'department' => StaffNames::department( self::text( $contract['department'] ?? '' ), self::text( $contract['job_title'] ?? '' ) ),
			'job_title'  => StaffNames::job( self::text( $contract['job_title'] ?? '' ) ),
			'banned'     => in_array( strtolower( trim( (string) ( $m['_hbfwc_staff_banned'] ?? '' ) ) ), array( '1', 'yes', 'true', 'on' ), true ),
		);
	}
}
