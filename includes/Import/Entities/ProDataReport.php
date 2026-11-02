<?php
/**
 * Legacy pricing rules, blocks and iCal feeds: reported, not imported (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\EntityImporter;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy PRO add-on's data — seasonal templates and rules, occupancy and
 * booking-window rules, blocked dates still to come, iCal sync settings — is
 * **counted and reported, not converted** (decided 2026-10-04: the client's
 * copy of 30 Aug holds none of it). Anything found is an error naming where
 * to set it up by hand, so the dry run on the production copy shows whether
 * that changed before cutover. Writes nothing.
 */
final class ProDataReport implements EntityImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'pro_data';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Pricing rules, blocks and calendar sync (report only)', 'radius-hotel-booking-residencetata' );
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
		$source = $context->source;
		$checks = array(
			array(
				'hbfwc_seasonal_templates',
				'SELECT COUNT(*) FROM {hbfwc_seasonal_templates}',
				array(),
				static fn( $n ) => sprintf(
					/* translators: %d: number of records. */
					_n( '%d seasonal pricing template was not imported: recreate it under Pricing rules.', '%d seasonal pricing templates were not imported: recreate them under Pricing rules.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
			array(
				'hbfwc_seasonal_rules',
				'SELECT COUNT(*) FROM {hbfwc_seasonal_rules} WHERE end_date >= %s',
				array( substr( $context->cutover, 0, 10 ) ),
				static fn( $n ) => sprintf(
					/* translators: %d: number of records. */
					_n( '%d seasonal pricing rule still to come was not imported: recreate it under Pricing rules.', '%d seasonal pricing rules still to come were not imported: recreate them under Pricing rules.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
			array(
				'hbfwc_occupancy_rules',
				'SELECT COUNT(*) FROM {hbfwc_occupancy_rules}',
				array(),
				static fn( $n ) => sprintf(
					/* translators: %d: number of records. */
					_n( '%d occupancy pricing rule was not imported: recreate it under Pricing rules.', '%d occupancy pricing rules were not imported: recreate them under Pricing rules.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
			array(
				'hbfwc_booking_window_rules',
				'SELECT COUNT(*) FROM {hbfwc_booking_window_rules}',
				array(),
				static fn( $n ) => sprintf(
					/* translators: %d: number of records. */
					_n( '%d early-bird or last-minute rule was not imported: recreate it under Pricing rules.', '%d early-bird or last-minute rules were not imported: recreate them under Pricing rules.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
			array(
				'hbfwc_blocked_dates',
				'SELECT COUNT(*) FROM {hbfwc_blocked_dates} WHERE until_date >= %s',
				array( substr( $context->cutover, 0, 10 ) ),
				static fn( $n ) => sprintf(
					/* translators: %d: number of records. */
					_n( '%d blocked period still to come was not imported: block it under Blocked dates.', '%d blocked periods still to come were not imported: block them under Blocked dates.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
			array(
				'postmeta',
				"SELECT COUNT( DISTINCT post_id ) FROM {postmeta} WHERE meta_key = '_hbfwc_sync_calendar' AND meta_value NOT IN ( '', '0', 'no', 'a:0:{}' )",
				array(),
				static fn( $n ) => sprintf(
					/* translators: %d: number of room types. */
					_n( '%d room type syncs a calendar (iCal) in the old system: add the feed under Calendar sync.', '%d room types sync a calendar (iCal) in the old system: add the feeds under Calendar sync.', $n, 'radius-hotel-booking-residencetata' ),
					$n
				),
			),
		);

		$total = 0;
		foreach ( $checks as list( $table, $sql, $args, $message ) ) {
			if ( ! $source->has( $table ) ) {
				continue;
			}
			$count = (int) $source->value( $sql, $args );
			if ( $count < 1 ) {
				continue;
			}
			$total += $count;
			for ( $i = 0; $i < $count; $i++ ) {
				$context->report->count( $this->key(), 'skipped' );
			}
			$context->report->issue( $this->key(), $table, $message( $count ), 'error' );
		}
		$context->report->found( $this->key(), $total );
	}
}
