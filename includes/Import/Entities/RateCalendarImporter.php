<?php
/**
 * Price overrides and closed dates from the legacy calendar (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\Availability\CalendarService;
use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_rateplans` (a price per product, rate and date) → the rate's price
 * override on that date; `hbfwc_availability` rows whose `status` is `0`
 * (a `tinyint(1)`: 1 = open, the default) → the whole room type closed on
 * that date (M08 migration notes); a text status `closed`, `unavailable` or
 * `blocked` counts too. Only dates from the cutover on — and not before
 * today, the calendar does not take past dates. Written through
 * `CalendarService::save()`, one cell per legacy row.
 */
final class RateCalendarImporter extends AbstractImporter {

	public const CLOSED = array( 'closed', 'unavailable', 'blocked' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'rate_calendar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Prices and closures by date', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'room_type', 'rate_plan', 'room_type_rate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source  = $context->source;
		$service = new CalendarService();
		$from    = max( substr( $context->cutover, 0, 10 ), Dates::today() );

		$cells = array();
		if ( $source->has( 'hbfwc_rateplans' ) ) {
			foreach ( $source->rows( 'SELECT id, post_id, rate_id, rate_date, price FROM {hbfwc_rateplans} WHERE rate_date >= %s ORDER BY id', array( $from ) ) as $row ) {
				$cells[ 'p' . $row['id'] ] = array(
					'product' => (int) $row['post_id'],
					'term'    => (int) $row['rate_id'],
					'date'    => substr( (string) $row['rate_date'], 0, 10 ),
					'price'   => (float) $row['price'],
				);
			}
		}
		if ( $source->has( 'hbfwc_availability' ) ) {
			foreach ( $source->rows( 'SELECT id, post_id, booking_date, status FROM {hbfwc_availability} WHERE booking_date >= %s ORDER BY id', array( $from ) ) as $row ) {
				$status = strtolower( trim( (string) $row['status'] ) );
				if ( '0' !== $status && ! in_array( $status, self::CLOSED, true ) ) {
					continue;
				}
				$cells[ 'a' . $row['id'] ] = array(
					'product' => (int) $row['post_id'],
					'term'    => 0,
					'date'    => substr( (string) $row['booking_date'], 0, 10 ),
					'closed'  => true,
				);
			}
		}
		$context->report->found( $this->key(), count( $cells ) );

		foreach ( $cells as $legacy_id => $cell ) {
			$type_id = $context->map->newId( 'room_type', $cell['product'] );
			$plan_id = $cell['term'] ? $context->map->newId( 'rate_plan', $cell['term'] ) : 0;
			if ( ! $type_id || ( $cell['term'] && ! $plan_id ) ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, __( 'Its room type or rate plan was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}
			$change = isset( $cell['closed'] )
				? array( 'is_closed' => true )
				: array( 'price_override' => $cell['price'] );

			$write = function () use ( $service, $type_id, $plan_id, $cell, $change ) {
				$service->save(
					$type_id,
					array(
						array(
							'rate_plan_id' => $plan_id,
							'date' => $cell['date'],
						) + $change,
					)
				);
				return $type_id;
			};
			$this->import( $context, $legacy_id, $cell + array( 'room_type_id' => $type_id ), static fn() => 0, $write, $write );
		}
	}
}
