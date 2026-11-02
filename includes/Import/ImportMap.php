<?php
/**
 * Legacy id → new id map (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `tata_import_map`, which makes the importer idempotent
 * (importer rule 2): a record already imported is found by
 * `entity` + `legacy_id`; its checksum says whether the legacy data changed
 * since. In a dry run nothing is written: record() keeps the mapping in
 * memory (the run's plan), so a later entity sees the parents it depends
 * on — linked ones with their real id, ones that would be created with a
 * negative placeholder id (planned()).
 */
final class ImportMap {

	/**
	 * Checksum prefix marking a linked record.
	 */
	public const LINKED = 'L';

	/**
	 * Whether writes are skipped.
	 *
	 * @var bool
	 */
	private bool $dry_run;

	/**
	 * Dry-run plan: entity => legacy id => [ new_id, checksum ].
	 *
	 * @var array<string, array<string, array{0:int, 1:string}>>
	 */
	private array $plan = array();

	/**
	 * Last placeholder id handed out.
	 *
	 * @var int
	 */
	private int $placeholder = 0;

	/**
	 * Constructor.
	 *
	 * @param bool $dry_run Dry run.
	 */
	public function __construct( bool $dry_run ) {
		$this->dry_run = $dry_run;
	}

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'tata_import_map';
	}

	/**
	 * The map row of a legacy record, or null.
	 *
	 * @param string     $entity    Entity key, e.g. `room`.
	 * @param string|int $legacy_id Legacy id.
	 * @return array{new_id:int, checksum:string}|null
	 */
	public function find( string $entity, $legacy_id ): ?array {
		if ( isset( $this->plan[ $entity ][ (string) $legacy_id ] ) ) {
			list( $new_id, $checksum ) = $this->plan[ $entity ][ (string) $legacy_id ];
			return array(
				'new_id'   => $new_id,
				'checksum' => $checksum,
			);
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the map changes during the run.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT new_id, checksum FROM %i WHERE entity = %s AND legacy_id = %s', self::table(), $entity, (string) $legacy_id ), ARRAY_A );
		return $row ? array(
			'new_id'   => (int) $row['new_id'],
			'checksum' => (string) $row['checksum'],
		) : null;
	}

	/**
	 * The new id of a legacy record (0 when not imported).
	 *
	 * @param string     $entity    Entity key.
	 * @param string|int $legacy_id Legacy id.
	 * @return int
	 */
	public function newId( string $entity, $legacy_id ): int {
		$row = $this->find( $entity, $legacy_id );
		return $row ? $row['new_id'] : 0;
	}

	/**
	 * What importing this legacy record means: `created` (not mapped yet),
	 * `updated` (mapped, the data changed) or `unchanged`.
	 *
	 * @param string     $entity    Entity key.
	 * @param string|int $legacy_id Legacy id.
	 * @param string     $checksum  Checksum of the legacy data.
	 * @return string
	 */
	public function status( string $entity, $legacy_id, string $checksum ): string {
		$row = $this->find( $entity, $legacy_id );
		if ( ! $row ) {
			return 'created';
		}
		return self::bare( $row['checksum'] ) === $checksum ? 'unchanged' : 'updated';
	}

	/**
	 * Whether a mapped record was linked (an existing record adopted) rather
	 * than created: the importer never changes it afterwards.
	 *
	 * @param string     $entity    Entity key.
	 * @param string|int $legacy_id Legacy id.
	 * @return bool
	 */
	public function isLinked( string $entity, $legacy_id ): bool {
		$row = $this->find( $entity, $legacy_id );
		return $row && 0 === strpos( $row['checksum'], self::LINKED );
	}

	/**
	 * A checksum without the linked mark.
	 *
	 * @param string $checksum Stored checksum.
	 * @return string
	 */
	private static function bare( string $checksum ): string {
		return 0 === strpos( $checksum, self::LINKED ) ? substr( $checksum, strlen( self::LINKED ) ) : $checksum;
	}

	/**
	 * Store the mapping after the new record was written. No-op in a dry run.
	 *
	 * @param string     $entity    Entity key.
	 * @param string|int $legacy_id Legacy id.
	 * @param int        $new_id    New record id.
	 * @param string     $checksum  Checksum of the legacy data.
	 * @return void
	 */
	public function record( string $entity, $legacy_id, int $new_id, string $checksum ): void {
		if ( $this->dry_run ) {
			$this->plan[ $entity ][ (string) $legacy_id ] = array( $new_id, $checksum );
			return;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an upsert on the unique key.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( entity, legacy_id, new_id, checksum, created_at, updated_at ) VALUES ( %s, %s, %d, %s, %s, %s ) ON DUPLICATE KEY UPDATE new_id = VALUES( new_id ), checksum = VALUES( checksum ), updated_at = VALUES( updated_at )', self::table(), $entity, (string) $legacy_id, $new_id, $checksum, $now, $now ) );
	}

	/**
	 * Every record of an entity imported by an earlier run (stored, not this dry run's plan).
	 *
	 * @param string $entity Entity key.
	 * @return array<string, int> Legacy id => new id.
	 */
	public function stored( string $entity ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the map changes during the run.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT legacy_id, new_id FROM %i WHERE entity = %s', self::table(), $entity ), ARRAY_A );
		return array_map( 'intval', array_column( (array) $rows, 'new_id', 'legacy_id' ) );
	}

	/**
	 * A placeholder id for a record a dry run would create (negative, never a real id).
	 *
	 * @return int
	 */
	public function planned(): int {
		return --$this->placeholder;
	}

	/**
	 * Checksum of legacy data (any array; keys sorted so order does not matter).
	 *
	 * @param array $data Legacy data.
	 * @return string 40 hex characters.
	 */
	public static function checksum( array $data ): string {
		$sort = static function ( $value ) use ( &$sort ) {
			if ( is_array( $value ) ) {
				ksort( $value );
				return array_map( $sort, $value );
			}
			return $value;
		};
		return sha1( (string) wp_json_encode( $sort( $data ) ) );
	}
}
