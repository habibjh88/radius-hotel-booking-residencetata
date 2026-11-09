<?php
/**
 * Employee contracts from the legacy payroll contracts (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingPro\Staff\ContractService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Staff\StaffNames;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_contracts` → Pro employee contracts (M12): `job_type`
 * (`full-time` → `full_time`; unknown → other), `job_title`, `department`,
 * `hire_date` → start, `notes`, `status` (`fired` / `terminated` / `ended` →
 * terminated; the legacy table keeps no end date, so the last change is used
 * and reported). The pay fields (`salary_amount`, `hourly_rate`,
 * `payment_type`, `pay_frequency`, `pay_schedule_id`, `shift_id`) are
 * imported by `EmployeePayImporter` (M15 T10); `file_path` documents are
 * reported to attach by hand.
 */
final class EmployeeContractImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'employee_contract';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Staff contracts', 'radius-hotel-booking-residencetata' );
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
		if ( ! $context->source->has( 'hbfwc_payroll_contracts' ) ) {
			return;
		}
		$service = new ContractService();
		$rows    = $context->source->rows( 'SELECT * FROM {hbfwc_payroll_contracts} ORDER BY id' );
		$context->report->found( $this->key(), count( $rows ) );

		foreach ( $rows as $row ) {
			$legacy_id   = (int) $row['id'];
			$employee_id = $context->map->newId( 'employee', (int) $row['user_id'] );
			if ( ! $employee_id ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, __( 'Its staff member was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}
			$files = maybe_unserialize( (string) ( $row['file_path'] ?? '' ) );
			if ( is_array( $files ) ? (bool) array_filter( $files ) : '' !== trim( (string) $files ) ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'The old contract has a document file; attach it to the staff file by hand.', 'radius-hotel-booking-residencetata' ) );
			}

			$type   = str_replace( '-', '_', strtolower( trim( (string) $row['job_type'] ) ) );
			$ended  = in_array( strtolower( (string) $row['status'] ), array( 'fired', 'terminated', 'ended', 'inactive' ), true );
			$input  = array(
				'job_type'   => in_array( $type, array( 'full_time', 'part_time', 'contract', 'internship' ), true ) ? $type : 'other',
				// The hotel's names, not the old free-typed variants (StaffNames).
				'job_title'  => StaffNames::job( self::text( $row['job_title'] ) ),
				'department' => StaffNames::department( self::text( $row['department'] ), self::text( $row['job_title'] ) ),
				'start_date' => substr( (string) $row['hire_date'], 0, 10 ),
				'status'     => $ended ? 'terminated' : 'active',
				'end_date'   => $ended ? substr( (string) ( $row['updated_at'] ? $row['updated_at'] : $row['created_at'] ), 0, 10 ) : '',
				'notes'      => self::text( $row['notes'] ?? '' ),
			);
			if ( $ended ) {
				$context->report->issue( $this->key(), $legacy_id, sprintf( /* translators: %s: date. */ __( 'The contract ended in the old system, which keeps no end date; %s (its last change) was used.', 'radius-hotel-booking-residencetata' ), $input['end_date'] ) );
			}

			$this->import(
				$context,
				$legacy_id,
				$input + array( 'employee' => $employee_id ),
				static fn() => 0,
				fn() => (int) $service->create( $employee_id, $input )->id,
				function ( int $contract_id ) use ( $service, $employee_id, $input ) {
					$service->update( $employee_id, $contract_id, $input );
				}
			);
		}
	}
}
