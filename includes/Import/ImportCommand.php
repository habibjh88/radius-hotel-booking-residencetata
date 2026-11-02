<?php
/**
 * `wp radius-hotel-booking import:legacy` (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

use InvalidArgumentException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the client's data from the legacy hotel plugins (18.8).
 */
class ImportCommand {

	/**
	 * Import the legacy hotel data, or report what an import would do.
	 *
	 * Always start with --dry-run: it reads the legacy tables, counts every
	 * entity and lists the discrepancies, and writes nothing. Re-running the
	 * real import updates what changed and creates no duplicates.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report only; write nothing.
	 *
	 * [--only=<entities>]
	 * : Comma-separated entity keys to run (default: all).
	 *
	 * [--as-of=<date>]
	 * : Cutover date for a test run (bookings ending after it are imported). Default: now.
	 *
	 * [--source-prefix=<prefix>]
	 * : Table prefix of the legacy tables (default: the saved one, else this site's).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp radius-hotel-booking import:legacy --dry-run
	 *     wp radius-hotel-booking import:legacy --dry-run --source-prefix=legacy_
	 *     wp radius-hotel-booking import:legacy --only=room_type,room
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ): void {
		unset( $args );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$only    = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['only'] ?? '' ) ) ) ) );

		try {
			$source = new LegacySource( isset( $assoc_args['source-prefix'] ) ? (string) $assoc_args['source-prefix'] : null );
			$report = ( new LegacyImporter() )->run( $source, $dry_run, $only, isset( $assoc_args['as-of'] ) ? (string) $assoc_args['as-of'] : null );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$data = $report->toArray();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::log( sprintf( '%s — legacy tables under "%s" (meta prefix "%s"), cutover %s', $dry_run ? 'DRY RUN, nothing written' : 'IMPORT', $source->prefix(), $source->metaPrefix(), isset( $assoc_args['as-of'] ) ? (string) $assoc_args['as-of'] : 'now' ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Legacy data:' );
		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( $key, $row ) => array(
					'area'  => $row['label'],
					'count' => $row['count'],
				),
				array_keys( $data['source'] ),
				$data['source']
			),
			array( 'area', 'count' )
		);

		if ( $data['counts'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( $dry_run ? 'Would import:' : 'Imported:' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map( static fn( $entity, $row ) => array( 'entity' => $entity ) + $row, array_keys( $data['counts'] ), $data['counts'] ),
				array_merge( array( 'entity', 'found' ), ImportReport::OUTCOMES )
			);
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( 'No entity importers are registered yet.' );
		}

		if ( $data['issues'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Discrepancies (%d):', count( $data['issues'] ) ) );
			\WP_CLI\Utils\format_items( 'table', $data['issues'], array( 'level', 'entity', 'legacy_id', 'message' ) );
		}

		if ( $report->hasErrors() ) {
			WP_CLI::warning( 'Some records could not be imported; see the errors above.' );
		} else {
			WP_CLI::success( $dry_run ? 'Dry run finished.' : 'Import finished.' );
		}
	}
}
