<?php
/**
 * Time off from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\TimeOffRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\TimeOffService;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_time_off` → time off requests (M15 T8a), as history: the
 * rows are written as they were, without the quota and overlap checks a
 * new request gets (a past decision stands). `rejected` → declined; an
 * empty end date is a one-day request; days = calendar days, both ends
 * included, as the legacy counted them. Who asked and who decided are WordPress
 * users of the old site: kept only when the import runs on the same site.
 * The vacation balance needs nothing else: here as in the legacy system it is
 * the sum of approved vacation days in the year.
 */
final class TimeOffImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'time_off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Time off', 'radius-hotel-booking-residencetata' );
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
		if ( ! $context->source->has( 'hbfwc_payroll_time_off' ) ) {
			return;
		}
		global $wpdb;
		$rows = $context->source->rows( 'SELECT * FROM {hbfwc_payroll_time_off} ORDER BY id' );
		$context->report->found( $this->key(), count( $rows ) );
		$repo      = new TimeOffRepository();
		$same_site = $context->source->prefix() === $wpdb->prefix;

		foreach ( $rows as $row ) {
			$legacy_id   = (int) $row['id'];
			$employee_id = $context->map->newId( 'employee', (int) $row['user_id'] );
			if ( ! $employee_id ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, __( 'Its staff member was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}
			$start = substr( (string) $row['start_date'], 0, 10 );
			$end   = substr( (string) ( $row['end_date'] ? $row['end_date'] : $row['start_date'] ), 0, 10 );
			if ( $end < $start ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'The last day was before the first; it was set to the first day.', 'radius-hotel-booking-residencetata' ) );
				$end = $start;
			}
			$type   = in_array( $row['type'], TimeOffService::TYPES, true ) ? (string) $row['type'] : 'other';
			$status = array(
				'pending'  => 'pending',
				'approved' => 'approved',
				'rejected' => 'declined',
			)[ (string) $row['status'] ] ?? 'pending';
			$user   = static fn( $id ) => $same_site && (int) $id > 0 ? (int) $id : null;
			$data   = array(
				'employee_id'  => $employee_id,
				'type'         => $type,
				'start_date'   => $start,
				'end_date'     => $end,
				'days'         => (int) ( new \DateTimeImmutable( $start ) )->diff( new \DateTimeImmutable( $end ) )->days + 1,
				'reason'       => self::text( $row['reason'] ?? '' ),
				'status'       => $status,
				'requested_by' => $user( $row['requested_by'] ?? 0 ),
				'requested_at' => $row['requested_at'] ? (string) $row['requested_at'] : (string) $row['created_at'],
				'reviewed_by'  => 'pending' === $status ? null : $user( $row['approved_by'] ?? 0 ),
				'reviewed_at'  => 'pending' === $status || ! $row['approved_at'] ? null : (string) $row['approved_at'],
			);
			$this->import(
				$context,
				$legacy_id,
				$data,
				static fn() => 0,
				static fn() => $repo->put( $data ),
				static fn( int $id ) => $repo->put( $data, $id )
			);
		}
	}
}
