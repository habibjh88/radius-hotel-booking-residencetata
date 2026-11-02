<?php
/**
 * The legacy activity log, kept once as a CSV file (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;
use RadiusTheme\RadiusHotelBookingTata\Import\EntityImporter;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportMap;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_restata_staff_log` is **not** imported into the hash-chained
 * activity log (M14 migration notes): it is exported **once** to a CSV in
 * the protected files (kind `tata_legacy`, administrators only), and the
 * report gives its link, to keep with the cutover backup (user decision
 * 2026-10-04: not listed on the Activity log screen). A later run with the
 * same rows does nothing; with new legacy rows (during the parallel run) it
 * writes a new file and says so.
 */
final class LegacyLogExport implements EntityImporter {

	public const KIND = 'tata_legacy';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'legacy_log';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Old activity log (kept as a file)', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array();
	}

	/**
	 * `rtbp_file_kinds`: the add-on's protected files, administrators only.
	 *
	 * @param array $kinds Kinds.
	 * @return array
	 */
	public static function kind( $kinds ): array {
		$kinds                = (array) $kinds;
		$kinds[ self::KIND ] = array( 'capability' => 'manage_options' );
		return $kinds;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		if ( ! $context->source->has( 'hbfwc_restata_staff_log' ) ) {
			return;
		}
		$rows = $context->source->rows( 'SELECT id, created_at, staff_id, user_role, event_type, action, description, object_type, object_id, ip_address, user_agent, hit_count FROM {hbfwc_restata_staff_log} ORDER BY id' );
		$context->report->found( $this->key(), $rows ? 1 : 0 );
		if ( ! $rows ) {
			return;
		}
		$checksum = ImportMap::checksum( array( count( $rows ), end( $rows )['id'], md5( (string) wp_json_encode( $rows ) ) ) );
		$status   = $context->map->status( $this->key(), 'log', $checksum );
		if ( 'unchanged' === $status ) {
			$context->report->count( $this->key(), 'unchanged' );
			return;
		}
		if ( $context->dry_run ) {
			$context->report->count( $this->key(), $status );
			$context->report->issue( $this->key(), 'log', sprintf( /* translators: %d: number of rows. */ _n( '%d old log entry will be kept as a CSV file.', '%d old log entries will be kept as a CSV file.', count( $rows ), 'radius-hotel-booking-residencetata' ), count( $rows ) ) );
			return;
		}

		$csv = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream.
		fputcsv( $csv, array_keys( $rows[0] ), ',', '"', '' );
		foreach ( $rows as $row ) {
			// A cell starting with = + - @ would run as a formula in a spreadsheet.
			fputcsv( $csv, array_map( static fn( $cell ) => preg_match( '/^[=+\-@]/', (string) $cell ) ? "'" . $cell : $cell, $row ), ',', '"', '' );
		}
		rewind( $csv );
		$contents = (string) stream_get_contents( $csv );
		fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the in-memory stream.

		try {
			$file = ProtectedFiles::put( self::KIND, "\xEF\xBB\xBF" . $contents, 'legacy-activity-log-' . gmdate( 'Ymd-His' ) . '.csv', 'text/csv' );
		} catch ( DomainException $e ) {
			$context->report->count( $this->key(), 'failed' );
			$context->report->issue( $this->key(), 'log', $e->getMessage(), 'error' );
			return;
		}
		$context->map->record( $this->key(), 'log', (int) $file->id, $checksum );
		$context->report->count( $this->key(), $status );
		$context->report->issue(
			$this->key(),
			'log',
			sprintf(
				/* translators: 1: number of rows, 2: download link. */
				_n( '%1$d old log entry kept as a file; keep it with the cutover backup: %2$s', '%1$d old log entries kept as a file; keep it with the cutover backup: %2$s', count( $rows ), 'radius-hotel-booking-residencetata' ),
				count( $rows ),
				ProtectedFiles::url( (string) $file->token )
			)
		);
	}
}
