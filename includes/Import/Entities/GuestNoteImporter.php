<?php
/**
 * Guest notes from the legacy notes table (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\Notes\NoteService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_notes` rows with `user_role = customer` → notes on the
 * imported guest (M09), type kept (`general|caution|warning`), through
 * `NoteService::add()`. The author becomes whoever runs the import; the
 * legacy date leads the text, so it is not lost. Never updated afterwards
 * (notes are edited by staff).
 */
final class GuestNoteImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'guest_note';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Guest notes', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'guest' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		if ( ! $context->source->has( 'hbfwc_payroll_notes' ) ) {
			return;
		}
		$service = new NoteService();
		$rows    = $context->source->rows( "SELECT id, user_id, note_type, note_date, note_text, created_at FROM {hbfwc_payroll_notes} WHERE user_role = 'customer' ORDER BY id" );
		$context->report->found( $this->key(), count( $rows ) );

		foreach ( $rows as $row ) {
			$guest_id = $context->map->newId( 'guest', (int) $row['user_id'] );
			if ( ! $guest_id ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), (int) $row['id'], __( 'Its guest was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}
			$type = in_array( $row['note_type'], array( 'general', 'caution', 'warning' ), true ) ? $row['note_type'] : 'general';
			$date = substr( (string) ( $row['note_date'] ? $row['note_date'] : $row['created_at'] ), 0, 10 );
			$body = trim( ( '' !== $date ? sprintf( /* translators: %s: date. */ __( '(Old system, %s)', 'radius-hotel-booking-residencetata' ), $date ) . "\n" : '' ) . self::text( $row['note_text'] ) );

			$this->import(
				$context,
				(int) $row['id'],
				array(
					'guest' => $guest_id,
					'type'  => $type,
					'body'  => $body,
				),
				static fn() => 0,
				fn() => (int) $service->add(
					array(
						'notable_type' => 'guest',
						'notable_id'   => $guest_id,
						'type'         => $type,
						'body'         => $body,
					)
				)->id
			);
		}
	}
}
