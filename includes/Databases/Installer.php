<?php
/**
 * Client add-on schema installer.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Databases
 */

namespace RadiusTheme\RadiusHotelBookingTata\Databases;

defined( 'ABSPATH' ) || exit;

/**
 * The add-on's tables. Listed on the free `rtbp_migration_classes` filter (so
 * the free installer and `artisan migrate` create them too), with the
 * add-on's own gate: when `RADIUS_HOTEL_BOOKING_TATA_DB_VERSION` differs from
 * the stored one, every table's `up()` runs again (dbDelta, idempotent).
 */
final class Installer {

	const OPTION = 'rtbp_tata_db_version';

	const LOCK = 'rtbp_tata_upgrading';

	/**
	 * Table classes, in dependency order, with their table names.
	 *
	 * @return array<string, string> Class => table name without the prefix.
	 */
	public static function tables(): array {
		return array(
			ImportMapTable::class => 'tata_import_map',
		);
	}

	/**
	 * Hook in. Runs from Plugin::boot(), after the free plugin and Pro loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rtbp_migration_classes', array( self::class, 'register' ) );
		self::maybe_upgrade();
	}

	/**
	 * `rtbp_migration_classes`: append the add-on's tables.
	 *
	 * @param array $classes Migration classes.
	 * @return array
	 */
	public static function register( $classes ): array {
		return array_merge( (array) $classes, array_keys( self::tables() ) );
	}

	/**
	 * Create or upgrade the tables when the add-on's DB version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) === RADIUS_HOTEL_BOOKING_TATA_DB_VERSION || get_transient( self::LOCK ) ) {
			return;
		}
		if ( ! class_exists( 'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Schema\\Schema' ) || ! function_exists( 'rtbp_table_prefix' ) ) {
			return;
		}

		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );
		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			foreach ( array_keys( self::tables() ) as $class ) {
				( new $class() )->up();
			}

			// Stamp the version only when every table exists: a failed CREATE is retried.
			global $wpdb;
			foreach ( self::tables() as $name ) {
				$table = rtbp_table_prefix() . $name;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check during install.
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
					return;
				}
			}
			update_option( self::OPTION, RADIUS_HOTEL_BOOKING_TATA_DB_VERSION );
		} finally {
			delete_transient( self::LOCK );
		}
	}
}
