<?php
/**
 * One import run's shared state (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

defined( 'ABSPATH' ) || exit;

/**
 * What every entity importer of a run shares: the legacy source, the map,
 * the report, and whether this is a dry run.
 */
final class ImportContext {

	/**
	 * The legacy data.
	 *
	 * @var LegacySource
	 */
	public LegacySource $source;

	/**
	 * Legacy id → new id.
	 *
	 * @var ImportMap
	 */
	public ImportMap $map;

	/**
	 * Counts and discrepancies.
	 *
	 * @var ImportReport
	 */
	public ImportReport $report;

	/**
	 * Whether nothing may be written.
	 *
	 * @var bool
	 */
	public bool $dry_run;

	/**
	 * The cutover moment, site time `Y-m-d H:i:s`: bookings and blocks that
	 * end after it are "future" and imported. Now, unless a test run sets it.
	 *
	 * @var string
	 */
	public string $cutover;

	/**
	 * Constructor.
	 *
	 * @param LegacySource $source  Source.
	 * @param bool         $dry_run Dry run.
	 * @param string|null  $cutover Cutover moment (site time); null = now.
	 */
	public function __construct( LegacySource $source, bool $dry_run, ?string $cutover = null ) {
		$this->source  = $source;
		$this->dry_run = $dry_run;
		$this->cutover = null !== $cutover ? $cutover : current_time( 'mysql' );
		$this->map     = new ImportMap( $dry_run );
		$this->report  = new ImportReport();
	}
}
