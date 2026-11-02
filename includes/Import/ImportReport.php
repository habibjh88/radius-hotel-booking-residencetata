<?php
/**
 * What an import run found and did (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Counts per entity (`found`, `created`, `linked` — an existing record
 * adopted —, `updated`, `unchanged`, `skipped`, `failed`) and a discrepancy list (importer rule 1: a dry run reports counts
 * plus every difference a person must look at). Never holds secrets: an
 * importer that reads PINs reports *that* it hashed one, not the value.
 */
final class ImportReport {

	public const OUTCOMES = array( 'created', 'linked', 'updated', 'unchanged', 'skipped', 'failed' );

	/**
	 * Counts per entity.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $counts = array();

	/**
	 * Discrepancies.
	 *
	 * @var array<int, array{entity:string, legacy_id:string, level:string, message:string}>
	 */
	private array $issues = array();

	/**
	 * What the legacy system holds, area by area.
	 *
	 * @var array<string, array{label:string, count:int}>
	 */
	private array $source = array();

	/**
	 * Count records found in the source for an entity.
	 *
	 * @param string $entity Entity key.
	 * @param int    $count  How many.
	 * @return void
	 */
	public function found( string $entity, int $count ): void {
		$this->row( $entity );
		$this->counts[ $entity ]['found'] += $count;
	}

	/**
	 * Count one outcome.
	 *
	 * @param string $entity  Entity key.
	 * @param string $outcome One of OUTCOMES.
	 * @return void
	 */
	public function count( string $entity, string $outcome ): void {
		$this->row( $entity );
		if ( isset( $this->counts[ $entity ][ $outcome ] ) ) {
			++$this->counts[ $entity ][ $outcome ];
		}
	}

	/**
	 * Add a discrepancy.
	 *
	 * @param string     $entity    Entity key.
	 * @param string|int $legacy_id Legacy id ('' for the whole entity).
	 * @param string     $message   What a person should look at.
	 * @param string     $level     `warning` (review) or `error` (not imported).
	 * @return void
	 */
	public function issue( string $entity, $legacy_id, string $message, string $level = 'warning' ): void {
		$this->issues[] = array(
			'entity'    => $entity,
			'legacy_id' => (string) $legacy_id,
			'level'     => 'error' === $level ? 'error' : 'warning',
			'message'   => $message,
		);
	}

	/**
	 * Record what the source holds for an area.
	 *
	 * @param string $key   Area key.
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @return void
	 */
	public function source( string $key, string $label, int $count ): void {
		$this->source[ $key ] = array(
			'label' => $label,
			'count' => $count,
		);
	}

	/**
	 * Whether any error was reported.
	 *
	 * @return bool
	 */
	public function hasErrors(): bool {
		foreach ( $this->issues as $issue ) {
			if ( 'error' === $issue['level'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The report as data.
	 *
	 * @return array{source: array, counts: array, issues: array}
	 */
	public function toArray(): array {
		return array(
			'source' => $this->source,
			'counts' => $this->counts,
			'issues' => $this->issues,
		);
	}

	/**
	 * Make sure an entity has a counts row.
	 *
	 * @param string $entity Entity key.
	 * @return void
	 */
	private function row( string $entity ): void {
		if ( ! isset( $this->counts[ $entity ] ) ) {
			$this->counts[ $entity ] = array_fill_keys( array_merge( array( 'found' ), self::OUTCOMES ), 0 );
		}
	}
}
