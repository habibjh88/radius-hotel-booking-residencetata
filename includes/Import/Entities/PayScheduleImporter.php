<?php
/**
 * Pay schedules from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayScheduleRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_pay_schedule` → pay schedules (M15 T3a): `name`,
 * `pay_interval` (the same four intervals), `end_period` → the anchor
 * period end, `next_pay_day`. The schedule service checks the pair (pay day
 * after the period end, at most an interval later, twice a month ending on
 * the 15th or a month end); a legacy pair it refuses fails the record with
 * the reason. One already here under the same name is linked.
 */
final class PayScheduleImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'pay_schedule';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Pay schedules', 'radius-hotel-booking-residencetata' );
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
		if ( ! $context->source->has( 'hbfwc_payroll_pay_schedule' ) ) {
			return;
		}
		$rows = $context->source->rows( 'SELECT * FROM {hbfwc_payroll_pay_schedule} ORDER BY id' );
		$context->report->found( $this->key(), count( $rows ) );
		$service  = new PayScheduleService();
		$existing = array();
		foreach ( ( new PayScheduleRepository() )->byName() as $schedule ) {
			$existing[ mb_strtolower( (string) $schedule->name ) ] = (int) $schedule->id;
		}
		foreach ( $rows as $row ) {
			$name  = self::text( $row['name'] );
			$input = array(
				'name'         => $name,
				'pay_interval' => strtolower( trim( (string) $row['pay_interval'] ) ),
				'period_end'   => substr( (string) $row['end_period'], 0, 10 ),
				'next_pay_day' => substr( (string) $row['next_pay_day'], 0, 10 ),
			);
			$this->import(
				$context,
				(int) $row['id'],
				$input,
				static fn() => $existing[ mb_strtolower( $name ) ] ?? 0,
				fn() => (int) $service->create( $input )->id,
				fn( int $id ) => $service->update( $id, $input ),
				__( 'A pay schedule with this name already exists; it was linked, not changed.', 'radius-hotel-booking-residencetata' )
			);
		}
	}
}
