<?php
/**
 * The import map table (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Databases
 */

namespace RadiusTheme\RadiusHotelBookingTata\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_tata_import_map`: which new record each legacy record
 * became (`entity` + `legacy_id` → `new_id`), with a checksum of the legacy
 * data last imported. A re-run finds the row and updates (checksum changed)
 * or skips (unchanged) instead of creating a duplicate.
 */
class ImportMapTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'tata_import_map',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'entity', 40 );
				$table->string( 'legacy_id', 64 );
				$table->unsignedBigInteger( 'new_id' )->default( 0 );
				$table->string( 'checksum', 48 )->default( '' );
				$table->timestamps();

				$table->unique( array( 'entity', 'legacy_id' ), 'entity_legacy' );
				$table->index( array( 'entity', 'new_id' ), 'entity_new' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'tata_import_map' );
	}
}
