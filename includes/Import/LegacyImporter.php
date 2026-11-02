<?php
/**
 * Runs the legacy import (M18, 18.8).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a run: summarises what the legacy system holds, then runs the
 * registered entity importers (`rtbp_tata_import_entities`) in dependency
 * order. A dry run writes nothing (rule 1); a real run is logged as
 * `import.run` with its counts.
 */
final class LegacyImporter {

	/**
	 * The legacy tables a complete import reads; a missing one is reported.
	 */
	public const REQUIRED_TABLES = array(
		'posts',
		'postmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'termmeta',
		'users',
		'usermeta',
		'options',
		'hbfwc_restata_rooms',
		'hbfwc_restata_booking',
		'hbfwc_rateplans',
		'hbfwc_availability',
		'hbfwc_blocked_dates',
	);

	/**
	 * Registered entity importers, keyed by entity.
	 *
	 * @return array<string, EntityImporter>
	 */
	public static function entities(): array {
		/**
		 * Filter the entity importers of the legacy import.
		 *
		 * @param EntityImporter[] $importers Importers.
		 */
		$list = (array) apply_filters( 'rtbp_tata_import_entities', array() );
		$out  = array();
		foreach ( $list as $importer ) {
			if ( $importer instanceof EntityImporter ) {
				$out[ $importer->key() ] = $importer;
			}
		}
		return $out;
	}

	/**
	 * Run the import.
	 *
	 * @param LegacySource $source  Legacy data.
	 * @param bool         $dry_run Write nothing.
	 * @param string[]     $only    Entity keys to run (empty = all); their dependencies must be imported already or listed too.
	 * @param string|null  $as_of   Cutover date or moment (site time, `Y-m-d` or `Y-m-d H:i:s`); null = now.
	 * @return ImportReport
	 * @throws InvalidArgumentException On an unknown entity in `$only` or a bad date.
	 */
	public function run( LegacySource $source, bool $dry_run, array $only = array(), ?string $as_of = null ): ImportReport {
		if ( null !== $as_of && ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $as_of ) ) {
			throw new InvalidArgumentException( 'Use a date like 2026-03-01 or 2026-03-01 12:00 for the cutover.' );
		}
		// `Y-m-d` → midnight, `Y-m-d H:i` → `:00` seconds.
		$cutover = null;
		if ( null !== $as_of ) {
			$cutover = 10 === strlen( $as_of ) ? $as_of . ' 00:00:00' : ( 16 === strlen( $as_of ) ? $as_of . ':00' : $as_of );
		}
		$context  = new ImportContext( $source, $dry_run, $cutover );
		$entities = self::entities();

		$unknown = array_diff( $only, array_keys( $entities ) );
		if ( $unknown ) {
			$known = $entities ? implode( ', ', array_keys( $entities ) ) : '(none yet)';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown by WP-CLI as plain text.
			throw new InvalidArgumentException( sprintf( 'Unknown entity: %s. Known: %s.', implode( ', ', $unknown ), $known ) );
		}

		$this->summarise( $context );

		foreach ( self::order( $entities ) as $key ) {
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$entities[ $key ]->run( $context );
		}

		// Imported bookings skip the booking hooks: recount every guest's stays (free 1.0.21+).
		if ( ! $dry_run && method_exists( '\RadiusTheme\RadiusHotelBooking\Repositories\GuestRepository', 'refreshStays' ) ) {
			( new \RadiusTheme\RadiusHotelBooking\Repositories\GuestRepository() )->refreshStays();
		}

		if ( ! $dry_run && function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				'import.run',
				null,
				array(
					'source'      => $source->prefix(),
					'only'        => $only,
					'cutover'     => $context->cutover,
					'counts'      => $context->report->toArray()['counts'],
					'description' => __( 'Ran the legacy data import', 'radius-hotel-booking-residencetata' ),
				)
			);
		}

		return $context->report;
	}

	/**
	 * Entity keys in dependency order (a dependency that is not registered is ignored).
	 *
	 * @param array<string, EntityImporter> $entities Importers.
	 * @return string[]
	 * @throws InvalidArgumentException On a dependency cycle.
	 */
	public static function order( array $entities ): array {
		$done  = array();
		$doing = array();
		$visit = static function ( string $key ) use ( &$visit, &$done, &$doing, $entities ) {
			if ( isset( $done[ $key ] ) || ! isset( $entities[ $key ] ) ) {
				return;
			}
			if ( isset( $doing[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a developer error, shown as plain text.
				throw new InvalidArgumentException( 'Import entities depend on each other in a cycle: ' . $key );
			}
			$doing[ $key ] = true;
			foreach ( $entities[ $key ]->dependsOn() as $dependency ) {
				$visit( $dependency );
			}
			unset( $doing[ $key ] );
			$done[ $key ] = true;
		};
		foreach ( array_keys( $entities ) as $key ) {
			$visit( $key );
		}
		return array_keys( $done );
	}

	/**
	 * What the legacy system holds, for the report (dry run and real run).
	 *
	 * @param ImportContext $context The run.
	 * @return void
	 */
	private function summarise( ImportContext $context ): void {
		$source = $context->source;
		$report = $context->report;

		$missing = array_values( array_filter( self::REQUIRED_TABLES, static fn( $name ) => ! $source->has( $name ) ) );
		foreach ( $missing as $name ) {
			$report->issue(
				'source',
				'',
				sprintf(
				/* translators: %s: database table name. */
					__( 'Legacy table %s is missing.', 'radius-hotel-booking-residencetata' ),
					$source->table( $name )
				),
				'error'
			);
		}
		$has = static fn( ...$names ) => ! array_intersect( $names, $missing );

		$now  = $context->cutover;
		$meta = $source->metaPrefix();

		if ( $has( 'posts', 'terms', 'term_taxonomy', 'term_relationships' ) ) {
			$report->source(
				'room_types',
				__( 'Room types (accommodation products)', 'radius-hotel-booking-residencetata' ),
				(int) $source->value(
					"SELECT COUNT( DISTINCT p.ID ) FROM {posts} p
						JOIN {term_relationships} tr ON tr.object_id = p.ID
						JOIN {term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
						JOIN {terms} t ON t.term_id = tt.term_id AND t.slug = 'accommodation'
					WHERE p.post_type = 'product' AND p.post_status <> 'trash'"
				)
			);
		}
		if ( $has( 'term_taxonomy' ) ) {
			$report->source( 'rate_plans', __( 'Rate plans (rate_plan terms, before deduplication)', 'radius-hotel-booking-residencetata' ), (int) $source->value( "SELECT COUNT(*) FROM {term_taxonomy} WHERE taxonomy = 'rate_plan'" ) );
		}
		if ( $has( 'hbfwc_restata_rooms' ) ) {
			$report->source( 'rooms', __( 'Rooms', 'radius-hotel-booking-residencetata' ), (int) $source->value( 'SELECT COUNT(*) FROM {hbfwc_restata_rooms}' ) );
			$report->source( 'floors', __( 'Floors (distinct floor values)', 'radius-hotel-booking-residencetata' ), (int) $source->value( "SELECT COUNT( DISTINCT floor ) FROM {hbfwc_restata_rooms} WHERE floor <> ''" ) );
		}
		if ( $has( 'hbfwc_restata_booking' ) ) {
			$report->source( 'bookings', __( 'Booking lines (all, without trashed)', 'radius-hotel-booking-residencetata' ), (int) $source->value( "SELECT COUNT(*) FROM {hbfwc_restata_booking} WHERE status <> 'trashed'" ) );
			$report->source( 'future_bookings', __( 'Booking lines open at the cutover (pending, confirmed, checked in; to import)', 'radius-hotel-booking-residencetata' ), (int) $source->value( "SELECT COUNT(*) FROM {hbfwc_restata_booking} WHERE status IN ( 'pending', 'confirmed', 'checked_in' ) AND checkout > %s", array( $now ) ) );
		}
		if ( $has( 'hbfwc_blocked_dates' ) ) {
			$report->source( 'blocks', __( 'Blocked dates ending after the cutover', 'radius-hotel-booking-residencetata' ), (int) $source->value( 'SELECT COUNT(*) FROM {hbfwc_blocked_dates} WHERE until_date >= %s', array( substr( $now, 0, 10 ) ) ) );
		}
		if ( $has( 'hbfwc_rateplans' ) ) {
			$report->source( 'rate_calendar', __( 'Price-per-date rows from the cutover day', 'radius-hotel-booking-residencetata' ), (int) $source->value( 'SELECT COUNT(*) FROM {hbfwc_rateplans} WHERE rate_date >= %s', array( substr( $now, 0, 10 ) ) ) );
		}
		if ( $has( 'usermeta' ) ) {
			$report->source( 'staff', __( 'Staff users', 'radius-hotel-booking-residencetata' ), (int) $source->value( 'SELECT COUNT(*) FROM {usermeta} WHERE meta_key = %s AND meta_value LIKE %s', array( $meta . 'capabilities', '%"staff"%' ) ) );
			$report->source( 'customers', __( 'Customer users', 'radius-hotel-booking-residencetata' ), (int) $source->value( 'SELECT COUNT(*) FROM {usermeta} WHERE meta_key = %s AND meta_value LIKE %s', array( $meta . 'capabilities', '%"customer"%' ) ) );
		}
		if ( $source->has( 'wc_orders' ) ) {
			$report->source( 'orders', __( 'WooCommerce orders (HPOS)', 'radius-hotel-booking-residencetata' ), (int) $source->value( "SELECT COUNT(*) FROM {wc_orders} WHERE type = 'shop_order'" ) );
		}
		foreach ( array(
			'hbfwc_seasonal_rules'       => __( 'Seasonal pricing rules', 'radius-hotel-booking-residencetata' ),
			'hbfwc_occupancy_rules'      => __( 'Occupancy pricing rules', 'radius-hotel-booking-residencetata' ),
			'hbfwc_booking_window_rules' => __( 'Booking-window pricing rules', 'radius-hotel-booking-residencetata' ),
		) as $table => $label ) {
			if ( $source->has( $table ) ) {
				$report->source( str_replace( 'hbfwc_', '', $table ), $label, (int) $source->value( "SELECT COUNT(*) FROM {{$table}}" ) );
			}
		}
	}
}
