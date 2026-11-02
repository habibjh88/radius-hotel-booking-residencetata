<?php
/**
 * The import screen's runs (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

use InvalidArgumentException;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the import for the admin screen (T2b) and keeps the last report.
 *
 * - **Dry run first** (rule 1), enforced here: a real run is refused unless a
 *   dry run with the same source prefix, cutover and entities finished in
 *   the last 24 hours, and that dry run found every legacy table.
 * - The source prefix is saved for the next run; a change is logged.
 * - The last report (no secrets) is kept in an option so the screen shows it
 *   again after a reload.
 */
final class ImportService {

	public const OPTION_LAST = 'rtbp_tata_import_last_report';

	public const DRY_RUN_TTL = DAY_IN_SECONDS;

	/**
	 * MySQL named lock held while a run is going on.
	 */
	public const LOCK = 'rtbp_tata_import';

	/**
	 * What the screen needs before a run.
	 *
	 * @return array
	 */
	public function status(): array {
		global $wpdb;
		$source = new LegacySource();
		return array(
			'source_prefix' => $source->prefix(),
			'site_prefix'   => $wpdb->prefix,
			'tables'        => array_map(
				static fn( $name ) => array(
					'name'   => $source->table( $name ),
					'exists' => $source->has( $name ),
				),
				LegacyImporter::REQUIRED_TABLES
			),
			'entities'      => array_values(
				array_map(
					static fn( $importer ) => array(
						'key'   => $importer->key(),
						'label' => $importer->label(),
					),
					LegacyImporter::entities()
				)
			),
			'last'          => $this->last(),
		);
	}

	/**
	 * Run a dry run or the import.
	 *
	 * @param array $input   `{ source_prefix?, as_of?, only? }`.
	 * @param bool  $dry_run Dry run.
	 * @return array The stored report (as last()).
	 * @throws DomainException 422 on bad input, 409 `dry_run_first`.
	 */
	public function run( array $input, bool $dry_run ): array {
		$params = $this->params( $input );

		if ( ! $dry_run ) {
			$last = $this->last();
			$same = $last && ! empty( $last['dry_run'] ) && $last['params'] === $params && ( time() - (int) $last['finished_at'] ) < self::DRY_RUN_TTL;
			if ( ! $same ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw DomainException::conflict( 'dry_run_first', __( 'Run a dry run with the same settings first, and read its report.', 'radius-hotel-booking-residencetata' ) );
			}
			if ( self::sourceIncomplete( $last ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw DomainException::conflict( 'source_incomplete', __( 'Some legacy tables are missing. Check the table prefix and run the dry run again.', 'radius-hotel-booking-residencetata' ) );
			}
		}

		$this->savePrefix( $params['source_prefix'] );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on the host; the run continues either way.
		}
		// One run at a time (two admins, or the screen and WP-CLI): a second run would create records twice.
		global $wpdb;
		$lock = self::LOCK;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'import_running', __( 'An import or dry run is already running. Wait for it to finish.', 'radius-hotel-booking-residencetata' ) );
		}
		$started = time();
		try {
			$report = ( new LegacyImporter() )->run( new LegacySource( $params['source_prefix'] ), $dry_run, $params['only'], '' !== $params['as_of'] ? $params['as_of'] : null );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'import_invalid', $e->getMessage(), 422 );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}

		$stored = array(
			'dry_run'     => $dry_run,
			'params'      => $params,
			'started_at'  => $started,
			'finished_at' => time(),
			'user_id'     => get_current_user_id(),
			'has_errors'  => $report->hasErrors(),
			'incomplete'  => false,
			'report'      => $report->toArray(),
		);
		$stored['incomplete'] = self::sourceIncomplete( $stored );
		update_option( self::OPTION_LAST, $stored, false );
		return $stored;
	}

	/**
	 * Whether a run found legacy tables missing (an import from it would be partial).
	 *
	 * @param array $run A stored run.
	 * @return bool
	 */
	public static function sourceIncomplete( array $run ): bool {
		foreach ( (array) ( $run['report']['issues'] ?? array() ) as $issue ) {
			if ( 'source' === ( $issue['entity'] ?? '' ) && 'error' === ( $issue['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The last stored run, or null.
	 *
	 * @return array|null
	 */
	public function last(): ?array {
		$last = get_option( self::OPTION_LAST, null );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Normalised run parameters (compared to decide whether a dry run matches).
	 *
	 * @param array $input Input.
	 * @return array{source_prefix:string, as_of:string, only:string[]}
	 * @throws DomainException 422 on an invalid prefix or date.
	 */
	private function params( array $input ): array {
		$prefix = trim( (string) ( $input['source_prefix'] ?? '' ) );
		$prefix = '' !== $prefix ? $prefix : ( new LegacySource() )->prefix();
		$as_of  = trim( (string) ( $input['as_of'] ?? '' ) );
		$only   = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['only'] ?? array() ) ) ) ) );
		sort( $only );

		$errors = array();
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			$errors['source_prefix'] = __( 'Use letters, digits and underscores only.', 'radius-hotel-booking-residencetata' );
		}
		if ( '' !== $as_of && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $as_of ) ) {
			$errors['as_of'] = __( 'Choose a date, or leave it empty for now.', 'radius-hotel-booking-residencetata' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return array(
			'source_prefix' => $prefix,
			'as_of'         => $as_of,
			'only'          => $only,
		);
	}

	/**
	 * Save the source prefix (the site's own prefix is stored as "default").
	 *
	 * @param string $prefix Prefix.
	 * @return void
	 */
	private function savePrefix( string $prefix ): void {
		global $wpdb;
		$old = (string) get_option( LegacySource::OPTION_PREFIX, '' );
		$new = $prefix === $wpdb->prefix ? '' : $prefix;
		if ( $old === $new ) {
			return;
		}
		update_option( LegacySource::OPTION_PREFIX, $new, false );
		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				'import.source',
				null,
				array(
					'before'      => array( 'source_prefix' => '' !== $old ? $old : $wpdb->prefix ),
					'after'       => array( 'source_prefix' => $prefix ),
					/* translators: %s: table prefix. */
					'description' => sprintf( __( 'Set the legacy import source to the tables starting with %s', 'radius-hotel-booking-residencetata' ), $prefix ),
				)
			);
		}
	}
}
