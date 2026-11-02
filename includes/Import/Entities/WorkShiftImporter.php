<?php
/**
 * Work shifts from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\WorkShiftRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\WorkShiftService;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_shifts` → work shifts (M15 T3b). `schedules` is a
 * serialized list of seven `{ day: "Monday"…, is_day_off, is_overnight,
 * start, end }`; the day name gives the weekday (1 = Monday … 7 = Sunday)
 * and an overnight day is simply one that ends before it starts, as here. A
 * shift with every day off is imported as it is and reported (the legacy
 * "Jour" shift: its hours were never filled in). A shift already here under
 * the same name is linked, not changed.
 */
final class WorkShiftImporter extends AbstractImporter {

	private const WEEKDAYS = array(
		'monday'    => 1,
		'tuesday'   => 2,
		'wednesday' => 3,
		'thursday'  => 4,
		'friday'    => 5,
		'saturday'  => 6,
		'sunday'    => 7,
	);

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'work_shift';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Work shifts', 'radius-hotel-booking-residencetata' );
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
		if ( ! $context->source->has( 'hbfwc_payroll_shifts' ) ) {
			return;
		}
		$rows = $context->source->rows( 'SELECT * FROM {hbfwc_payroll_shifts} ORDER BY id' );
		$context->report->found( $this->key(), count( $rows ) );
		$service  = new WorkShiftService();
		$existing = array();
		foreach ( ( new WorkShiftRepository() )->byName() as $shift ) {
			$existing[ mb_strtolower( (string) $shift->name ) ] = (int) $shift->id;
		}

		foreach ( $rows as $row ) {
			$legacy_id = (int) $row['id'];
			$name      = self::text( $row['name'] );
			$days      = self::days( maybe_unserialize( (string) $row['schedules'] ) );
			$input     = array(
				'name'        => $name,
				'description' => self::text( $row['description'] ?? '' ),
				'days'        => $days,
			);
			if ( ! array_filter( $days, static fn( $d ) => empty( $d['is_day_off'] ) ) ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'Every day of this shift is a day off in the old system; set its hours here.', 'radius-hotel-booking-residencetata' ) );
			}
			$this->import(
				$context,
				$legacy_id,
				$input,
				static fn() => $existing[ mb_strtolower( $name ) ] ?? 0,
				fn() => (int) $service->create( $input )->id,
				fn( int $id ) => $service->update( $id, $input ),
				__( 'A work shift with this name already exists; it was linked, not changed.', 'radius-hotel-booking-residencetata' )
			);
		}
	}

	/**
	 * The legacy days as `weekday => { is_day_off, start_time, end_time }`.
	 *
	 * @param mixed $legacy The unserialized `schedules` column.
	 * @return array<int, array>
	 */
	private static function days( $legacy ): array {
		$days = array();
		foreach ( is_array( $legacy ) ? $legacy : array() as $day ) {
			$weekday = self::WEEKDAYS[ strtolower( (string) ( $day['day'] ?? '' ) ) ] ?? 0;
			if ( ! $weekday ) {
				continue;
			}
			$off              = ! empty( $day['is_day_off'] ) || empty( $day['start'] ) || empty( $day['end'] );
			$days[ $weekday ] = array(
				'is_day_off' => $off,
				'start_time' => $off ? null : substr( (string) $day['start'], 0, 5 ),
				'end_time'   => $off ? null : substr( (string) $day['end'], 0, 5 ),
			);
		}
		// A day missing from the legacy list is a day off.
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$days[ $weekday ] = $days[ $weekday ] ?? array(
				'is_day_off' => true,
				'start_time' => null,
				'end_time'   => null,
			);
		}
		ksort( $days );
		return $days;
	}
}
