<?php
/**
 * One kind of legacy record to import (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

defined( 'ABSPATH' ) || exit;

/**
 * An entity importer (room types, rooms, rate plans, guests, bookings …),
 * registered on `rtbp_tata_import_entities`. It reads through
 * `$context->source`, writes through the free / Pro services (never raw
 * inserts into their tables), records each mapping in `$context->map`, and
 * counts outcomes and discrepancies in `$context->report`. In a dry run
 * (`$context->dry_run`) it must write nothing — it only counts what it
 * would do.
 */
interface EntityImporter {

	/**
	 * Entity key, also the `entity` column of the import map (`room_type`).
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Human label for the report.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Entity keys that must be imported first (rooms need room types).
	 *
	 * @return string[]
	 */
	public function dependsOn(): array;

	/**
	 * Import (or, in a dry run, count) every record of this entity.
	 *
	 * @param ImportContext $context The run.
	 * @return void
	 */
	public function run( ImportContext $context ): void;
}
