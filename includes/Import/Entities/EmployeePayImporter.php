<?php
/**
 * Employee pay profiles from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeePayRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeePayService;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayItemTypes;

defined( 'ABSPATH' ) || exit;

/**
 * The pay part of `hbfwc_payroll_contracts` plus the user meta
 * `staff_additional_pays` → the employee's pay profile (M15 T4b, T4c):
 *
 * - `payment_type` `salary` → salaried, `hourly` → hourly;
 * - `salary_amount` per `pay_frequency` (`weekly|monthly|yearly` is the
 *   period the salary is quoted in, not how often it is paid) → amount per
 *   week / month / year; `hourly_rate` for hourly pay;
 * - `pay_schedule_id`, `shift_id` → the imported schedule and shift (none
 *   when 0 or not imported, reported);
 * - each additional pay with `value = yes` and its fields (the same keys:
 *   T4c copied the legacy catalogue).
 *
 * The legacy system reads a user's **first** contract row; later rows of the
 * same user are reported and skipped. The legacy id is the contract id.
 */
final class EmployeePayImporter extends AbstractImporter {

	private const PERIODS = array(
		'weekly'  => 'week',
		'monthly' => 'month',
		'yearly'  => 'year',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'employee_pay';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Staff pay', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'employee', 'pay_schedule', 'work_shift' );
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
		$rows = $context->source->rows( 'SELECT * FROM {hbfwc_payroll_contracts} ORDER BY user_id, id' );
		$context->report->found( $this->key(), count( $rows ) );
		$extras = array();
		foreach ( $context->source->rows( "SELECT user_id, meta_value FROM {usermeta} WHERE meta_key = 'staff_additional_pays'" ) as $meta ) {
			$extras[ (int) $meta['user_id'] ] = maybe_unserialize( (string) $meta['meta_value'] );
		}
		$service = new EmployeePayService();
		$profile = new EmployeePayRepository();
		$seen    = array();

		foreach ( $rows as $row ) {
			$legacy_id = (int) $row['id'];
			$user_id   = (int) $row['user_id'];
			if ( isset( $seen[ $user_id ] ) ) {
				$context->report->count( $this->key(), 'skipped' );
				$context->report->issue( $this->key(), $legacy_id, __( 'A later contract of the same staff member; the old system only used the first one.', 'radius-hotel-booking-residencetata' ) );
				continue;
			}
			$seen[ $user_id ] = true;
			$employee_id      = $context->map->newId( 'employee', $user_id );
			if ( ! $employee_id ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, __( 'Its staff member was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}

			$hourly = 'hourly' === strtolower( trim( (string) $row['payment_type'] ) );
			$period = self::PERIODS[ strtolower( trim( (string) $row['pay_frequency'] ) ) ] ?? 'month';
			$input  = array(
				'pay_type'        => $hourly ? 'hourly' : 'salaried',
				'amount'          => number_format( (float) ( $hourly ? $row['hourly_rate'] : $row['salary_amount'] ), 2, '.', '' ),
				'amount_period'   => $hourly ? 'hour' : $period,
				'pay_schedule_id' => $this->linked( $context, 'pay_schedule', (int) $row['pay_schedule_id'], $legacy_id, __( 'Its pay schedule was not imported; choose one on the Pay tab.', 'radius-hotel-booking-residencetata' ) ),
				'work_shift_id'   => $this->linked( $context, 'work_shift', (int) $row['shift_id'], $legacy_id, __( 'Its work shift was not imported; choose one on the Pay tab.', 'radius-hotel-booking-residencetata' ) ),
			);
			if ( ! $hourly && ! isset( self::PERIODS[ strtolower( trim( (string) $row['pay_frequency'] ) ) ] ) ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'The salary period was missing or unknown; a monthly salary was assumed.', 'radius-hotel-booking-residencetata' ) );
			}
			$items = self::items( $extras[ $user_id ] ?? array() );

			$save = function () use ( $service, $employee_id, $input, $items ) {
				$service->save( $employee_id, $input );
				$service->saveItems( $employee_id, $items );
			};
			$this->import(
				$context,
				$legacy_id,
				$input + array(
					'employee' => $employee_id,
					'items'    => $items,
				),
				// A profile set up here already: keep it (linked), as any record found.
				static fn() => $profile->forEmployee( $employee_id ) ? $employee_id : 0,
				static function () use ( $save, $employee_id ) {
					$save();
					return $employee_id;
				},
				static fn() => $save(),
				__( 'This staff member already has pay set up here; it was kept.', 'radius-hotel-booking-residencetata' )
			);
		}
	}

	/**
	 * The new id of a legacy schedule or shift, or null (reported when the legacy one was set).
	 *
	 * @param ImportContext $context   The run.
	 * @param string        $entity    pay_schedule|work_shift.
	 * @param int           $legacy    Legacy id (0 = none).
	 * @param int           $record    The contract (for the report).
	 * @param string        $missing   Report text when it was set but not imported.
	 * @return int|null
	 */
	private function linked( ImportContext $context, string $entity, int $legacy, int $record, string $missing ): ?int {
		if ( $legacy <= 0 ) {
			return null;
		}
		$id = $context->map->newId( $entity, $legacy );
		if ( 0 === $id ) {
			$context->report->issue( $this->key(), $record, $missing );
			return null;
		}
		// A negative id is one a dry run plans to create: there is nothing to point at yet.
		return $id > 0 ? $id : null;
	}

	/**
	 * The legacy additional pays as `type => settings` (only those switched on and known here).
	 *
	 * @param mixed $legacy `{ key: { label, value: 'yes', fields } }`.
	 * @return array
	 */
	private static function items( $legacy ): array {
		$known = PayItemTypes::all();
		$items = array();
		foreach ( is_array( $legacy ) ? $legacy : array() as $type => $item ) {
			if ( ! isset( $known[ $type ] ) || 'yes' !== ( $item['value'] ?? '' ) ) {
				continue;
			}
			$items[ $type ] = is_array( $item['fields'] ?? null ) ? $item['fields'] : array();
		}
		ksort( $items );
		return $items;
	}
}
