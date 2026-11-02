<?php
/**
 * Read-only access to the legacy hotel tables (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy system's data, read through `$wpdb` under a table prefix.
 *
 * - On production the legacy plugins and the new ones share the database: the
 *   prefix is the site's own (`$wpdb->prefix`, the default).
 * - On a development site the legacy tables are copied in under their own
 *   prefix (`bin/copy-legacy-tables.py`, e.g. `legacy_`).
 *
 * **Read-only** (importer rule 3): select() refuses anything but a SELECT or
 * SHOW. Meta keys that embed the legacy site's prefix (`wp_capabilities`)
 * use metaPrefix(), read from the legacy options, not the table prefix.
 */
final class LegacySource {

	/**
	 * Option: the source table prefix chosen on the import screen.
	 */
	public const OPTION_PREFIX = 'rtbp_tata_import_source_prefix';

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Detected meta prefix (cache).
	 *
	 * @var string|null
	 */
	private ?string $meta_prefix = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $prefix Table prefix; null = the saved one, else the site's.
	 * @throws InvalidArgumentException On a prefix with other than letters, digits and `_`.
	 */
	public function __construct( ?string $prefix = null ) {
		global $wpdb;
		$prefix = null !== $prefix && '' !== $prefix ? $prefix : (string) get_option( self::OPTION_PREFIX, '' );
		$prefix = '' !== $prefix ? $prefix : $wpdb->prefix;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new InvalidArgumentException( 'The source prefix may contain only letters, digits and underscores.' );
		}
		$this->prefix = $prefix;
	}

	/**
	 * The table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * A legacy table's full name.
	 *
	 * @param string $name Name without the prefix, e.g. `hbfwc_restata_rooms`, `posts`.
	 * @return string
	 * @throws InvalidArgumentException On an invalid name.
	 */
	public function table( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			throw new InvalidArgumentException( 'Invalid legacy table name.' );
		}
		return $this->prefix . $name;
	}

	/**
	 * Whether a legacy table exists.
	 *
	 * @param string $name Name without the prefix.
	 * @return bool
	 */
	public function has( string $name ): bool {
		global $wpdb;
		$table = $this->table( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the legacy schema.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Rows of a read-only query. `{name}` placeholders become legacy table
	 * names (`{hbfwc_restata_rooms}`); values go through `$wpdb->prepare()`.
	 *
	 * @param string $sql  SELECT or SHOW.
	 * @param array  $args Values for the `%s` / `%d` placeholders.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( string $sql, array $args = array() ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- select() prepares it; read-only legacy data, read once per run.
		return (array) $wpdb->get_results( $this->select( $sql, $args ), ARRAY_A );
	}

	/**
	 * One value of a read-only query.
	 *
	 * @param string $sql  SELECT or SHOW.
	 * @param array  $args Values.
	 * @return mixed
	 */
	public function value( string $sql, array $args = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- select() prepares it; read-only legacy data.
		return $wpdb->get_var( $this->select( $sql, $args ) );
	}

	/**
	 * The prefix the legacy site used inside meta keys and option names
	 * (`wp_capabilities`, `wp_user_roles`), read from its options.
	 *
	 * @return string
	 */
	public function metaPrefix(): string {
		if ( null === $this->meta_prefix ) {
			$name              = $this->has( 'options' ) ? (string) $this->value( 'SELECT option_name FROM {options} WHERE option_name LIKE %s ORDER BY LENGTH( option_name ) LIMIT 1', array( '%\\_user\\_roles' ) ) : '';
			$this->meta_prefix = '' !== $name ? substr( $name, 0, -strlen( 'user_roles' ) ) : $this->prefix;
		}
		return $this->meta_prefix;
	}

	/**
	 * The final SQL: table placeholders resolved, values prepared, and only
	 * if it reads.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return string
	 * @throws InvalidArgumentException When the query would write.
	 */
	private function select( string $sql, array $args ): string {
		global $wpdb;
		if ( ! preg_match( '/^\s*(SELECT|SHOW)\s/i', $sql ) || false !== strpos( $sql, ';' ) ) {
			throw new InvalidArgumentException( 'The legacy source is read-only: only one SELECT or SHOW at a time.' );
		}
		$sql = preg_replace_callback( '/\{([a-z0-9_]+)\}/', fn( $m ) => '`' . $this->table( $m[1] ) . '`', $sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are validated identifiers; values are placeholders.
		return $args ? (string) $wpdb->prepare( $sql, $args ) : $sql;
	}
}
