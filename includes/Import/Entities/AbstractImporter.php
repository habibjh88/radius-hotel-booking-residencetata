<?php
/**
 * Shared helpers of the entity importers (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBookingTata\Import\EntityImporter;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportMap;

defined( 'ABSPATH' ) || exit;

/**
 * The common import step: compare the legacy record with the map, then
 * create, update or skip it through a free / Pro service, and count the
 * outcome. A service's refusal (a DomainException) becomes a `failed` count
 * and an error in the report; the run goes on with the next record.
 */
abstract class AbstractImporter implements EntityImporter {

	/**
	 * Import one legacy record.
	 *
	 * A record not mapped yet is first looked for among the existing ones
	 * (`$find`, also in a dry run): found, it is **linked** — mapped, not
	 * changed — and reported; `$find` may throw a DomainException for a
	 * conflict (a number taken by another record), which fails the record.
	 *
	 * @param ImportContext $context   The run.
	 * @param string|int    $legacy_id Legacy id.
	 * @param array         $data      The legacy data the checksum covers.
	 * @param callable      $find      `fn(): int` — an existing record to link (0 = none; negative = one this dry run plans); may throw.
	 * @param callable      $create    `fn(): int` — creates the record, returns its id.
	 * @param callable|null $update    `fn( int $id ): void` — updates the mapped record; null = never update.
	 * @param string        $linked    Report text when an existing record is linked.
	 * @return int The record's id (a negative placeholder for one a dry run would create), 0 when not imported.
	 */
	protected function import( ImportContext $context, $legacy_id, array $data, callable $find, callable $create, ?callable $update = null, string $linked = '' ): int {
		$key      = $this->key();
		$checksum = ImportMap::checksum( $data );
		$status   = $context->map->status( $key, $legacy_id, $checksum );
		$mapped   = $context->map->newId( $key, $legacy_id );

		if ( 'updated' === $status && $context->map->isLinked( $key, $legacy_id ) ) {
			// A linked record belongs to whoever set it up here: report the legacy change, keep the record.
			$context->report->issue( $key, $legacy_id, __( 'Changed in the old system since it was linked; the linked record was not changed.', 'radius-hotel-booking-residencetata' ) );
			$context->report->count( $key, 'unchanged' );
			return $mapped;
		}
		if ( 'unchanged' === $status || ( 'updated' === $status && null === $update ) ) {
			$context->report->count( $key, 'unchanged' );
			return $mapped;
		}

		try {
			if ( 'created' === $status ) {
				$existing = (int) $find();
				// A negative id is a record this dry run plans to create: linking to it is a merge too.
				if ( 0 !== $existing ) {
					$context->report->issue( $key, $legacy_id, '' !== $linked ? $linked : __( 'An existing record was linked, not changed.', 'radius-hotel-booking-residencetata' ) );
					$context->map->record( $key, $legacy_id, $existing, ImportMap::LINKED . $checksum );
					$context->report->count( $key, 'linked' );
					return $existing;
				}
				if ( $context->dry_run ) {
					$planned = $context->map->planned();
					$context->map->record( $key, $legacy_id, $planned, $checksum );
					$context->report->count( $key, 'created' );
					return $planned;
				}
				$id = (int) $create();
			} else {
				if ( $context->dry_run ) {
					$context->report->count( $key, 'updated' );
					return $mapped;
				}
				$update( $mapped );
				$id = $mapped;
			}
		} catch ( DomainException $e ) {
			$context->report->count( $key, 'failed' );
			$context->report->issue( $key, $legacy_id, self::reason( $e ), 'error' );
			return 0;
		}
		if ( $id <= 0 ) {
			$context->report->count( $key, 'skipped' );
			return 0;
		}

		$context->map->record( $key, $legacy_id, $id, $checksum );
		$context->report->count( $key, $status );
		return $id;
	}

	/**
	 * A service error as one line (public: other importers report the same way).
	 *
	 * @param DomainException $e Error.
	 * @return string
	 */
	public static function reasonOf( DomainException $e ): string {
		return self::reason( $e );
	}

	/**
	 * Whether two names are the same person's, compared as the free plugin
	 * compares them at the desk (`BookingService::nameKey()`: case, accents,
	 * spaces). Without that helper (a free build before M18's review fixes)
	 * every name counts as the same, as before.
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 * @return bool
	 */
	public static function sameName( string $a, string $b ): bool {
		$key = array( '\\RadiusTheme\\RadiusHotelBooking\\Services\\Booking\\BookingService', 'nameKey' );
		if ( ! is_callable( $key ) ) {
			return true;
		}
		return call_user_func( $key, $a ) === call_user_func( $key, $b );
	}

	/**
	 * A service error as one line: the message and the field errors.
	 *
	 * @param DomainException $e Error.
	 * @return string
	 */
	protected static function reason( DomainException $e ): string {
		$fields = array();
		foreach ( $e->getFieldErrors() as $field => $message ) {
			$text = is_array( $message ) ? implode( ' ', $message ) : (string) $message;
			if ( $text !== $e->getMessage() ) {
				$fields[] = $field . ': ' . $text;
			}
		}
		return trim( $e->getMessage() . ( $fields ? ' (' . implode( '; ', $fields ) . ')' : '' ) );
	}

	/**
	 * Legacy text as plain text (WordPress stores titles and terms HTML-escaped).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function text( $value ): string {
		return trim( html_entity_decode( wp_specialchars_decode( (string) $value, ENT_QUOTES ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
