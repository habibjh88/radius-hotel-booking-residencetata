<?php
/**
 * Recurring deductions and garnishments from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\DeductionService;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeeDeductionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The user meta `staff_deductions` → an employee's deductions and
 * garnishments (M15 T4d). Each legacy entry is `{ grand_type, type, label,
 * fields }`; its legacy id is `<user id>:<md5 of label>`, as the legacy
 * system identifies it.
 *
 * - deduction (`health_insurance` | `other`): name, authority, remittance
 *   day, per payslip / per month, the employee share and the employer share
 *   (`amount` | `percent` | `none`);
 * - garnishment `court_order`: start, end, total, at most %;
 * - garnishment `other`: start, end, the share, and as its total **what is
 *   still owed** in the old system (its legacy remaining): what the old
 *   system already took stays in the imported pay history, which does not
 *   count against it. Nothing left: imported as completed. The legacy total is
 *   reported when it differs.
 */
final class EmployeeDeductionImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'employee_deduction';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Staff deductions and garnishments', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'employee' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$metas   = $context->source->rows( "SELECT user_id, meta_value FROM {usermeta} WHERE meta_key = 'staff_deductions' ORDER BY user_id" );
		$service = new DeductionService();
		$repo    = new EmployeeDeductionRepository();
		$found   = 0;

		foreach ( $metas as $meta ) {
			$user_id = (int) $meta['user_id'];
			$entries = maybe_unserialize( (string) $meta['meta_value'] );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				++$found;
				$legacy_id   = $user_id . ':' . md5( (string) ( $entry['label'] ?? '' ) );
				$employee_id = $context->map->newId( 'employee', $user_id );
				if ( ! $employee_id ) {
					$context->report->count( $this->key(), 'failed' );
					$context->report->issue( $this->key(), $legacy_id, __( 'Its staff member was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
					continue;
				}
				list( $input, $remaining ) = self::input( $entry );
				$done                      = false;
				if ( null !== $remaining ) {
					$done = (float) $remaining <= 0;
					if ( ! $done && abs( (float) $remaining - (float) $input['total_amount'] ) >= 0.01 ) {
						$context->report->issue(
							$this->key(),
							$legacy_id,
							sprintf(
								/* translators: 1: garnishment name, 2: amount still owed, 3: original total. */
								__( '%1$s was imported with the %2$s still owed (of %3$s); what was already taken stays in the pay history.', 'radius-hotel-booking-residencetata' ),
								$input['name'],
								$remaining,
								$input['total_amount']
							)
						);
					}
					if ( ! $done ) {
						$input['total_amount'] = $remaining;
					}
				}
				$this->import(
					$context,
					$legacy_id,
					$input + array(
						'employee' => $employee_id,
						'done'     => $done,
					),
					static fn() => 0,
					static function () use ( $service, $repo, $employee_id, $input, $done ) {
						$id = (int) $service->create( $employee_id, $input )['id'];
						if ( $done ) {
							// Fully recovered in the old system: kept for the record, never taken again.
							$repo->update(
								$id,
								array(
									'status'           => 'completed',
									'remaining_amount' => '0.00',
								)
							);
						}
						return $id;
					},
					static fn( int $id ) => $service->update( $employee_id, $id, $input )
				);
			}
		}
		$context->report->found( $this->key(), $found );
	}

	/**
	 * The service input of a legacy entry, and the legacy remaining amount (`other` garnishments; else null).
	 *
	 * @param array $entry `{ grand_type, type, label, fields }`.
	 * @return array{0: array, 1: string|null}
	 */
	private static function input( array $entry ): array {
		$f     = is_array( $entry['fields'] ?? null ) ? $entry['fields'] : array();
		$kind  = 'garnishment' === ( $entry['grand_type'] ?? '' ) ? 'garnishment' : 'deduction';
		$type  = (string) ( $entry['type'] ?? 'other' );
		$input = array(
			'kind'           => $kind,
			'type'           => $type,
			'name'           => self::text( $f['name'] ?? '' ),
			'authority'      => self::text( $f['authority'] ?? '' ),
			'remittance_day' => is_numeric( $f['remittance'] ?? null ) ? (int) $f['remittance'] : null,
			'calculation'    => (string) ( $f['calculation'] ?? 'per_payslip' ),
		);
		$share = static function ( $legacy, string $prefix ) {
			$legacy = is_array( $legacy ) ? $legacy : array();
			$type   = (string) ( $legacy['amount_type'] ?? 'none' );
			return array(
				$prefix . 'amount_type' => $type,
				$prefix . 'amount'      => (string) ( $legacy['value'] ?? '' ),
				$prefix . 'percent'     => (string) ( $legacy['percent'] ?? '' ),
			);
		};
		if ( 'deduction' === $kind ) {
			return array( $input + $share( $f['employee'] ?? array(), '' ) + $share( $f['employer'] ?? array(), 'employer_' ), null );
		}
		$input += array(
			'start_date'   => substr( (string) ( $f['start_date'] ?? '' ), 0, 10 ),
			'end_date'     => substr( (string) ( $f['end_date'] ?? '' ), 0, 10 ),
			'total_amount' => (string) ( $f['total'] ?? '' ),
		);
		if ( 'court_order' === $type ) {
			return array( $input + array( 'max_percent' => (string) ( $f['max_percent'] ?? '' ) ), null );
		}
		$remaining = is_numeric( $f['remaining'] ?? null ) ? number_format( max( 0, (float) $f['remaining'] ), 2, '.', '' ) : null;
		return array(
			$input + $share(
				array(
					'amount_type' => $f['amount_type'] ?? 'amount',
					'value'       => $f['value'] ?? '',
					'percent'     => $f['percent'] ?? '',
				),
				''
			),
			$remaining,
		);
	}
}
