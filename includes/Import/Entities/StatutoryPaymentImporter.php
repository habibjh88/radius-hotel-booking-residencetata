<?php
/**
 * Statutory payments from the legacy payroll (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeeDeductionRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\StatutoryService;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_statutory_payment_transactions` (each payment made) with
 * its `hbfwc_payroll_statutory_payments` row (authority, code, party, month)
 * → statutory payments (M15 T6), as history: written as they were, without
 * the "no more than is due" check a new payment gets.
 *
 * What is due is not imported: here it is the sum of the imported pay runs'
 * statutory rows (the legacy monthly totals were rebuilt from the same
 * items). A legacy payment is per side (employee / employer); here a
 * contribution holds both sides, so the two add up under one code. A
 * deduction or garnishment code is the legacy slug of its name: it maps to
 * `DED_<id>` / `GAR_<id>` when exactly one imported deduction has that
 * name, otherwise the payment is reported to enter by hand. A transaction
 * whose payment row is gone (the legacy rebuild drops them) is reported.
 */
final class StatutoryPaymentImporter extends AbstractImporter {

	private const TAX_CODES = array( 'CNPS_PENSION', 'CNPS_FAMILY_ALLOWANCE', 'CNPS_WORK_INJURY', 'IS', 'CN', 'IGR' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'statutory_payment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Statutory payments', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'payroll_run', 'employee_deduction' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source = $context->source;
		if ( ! $source->has( 'hbfwc_payroll_statutory_payment_transactions' ) || ! $source->has( 'hbfwc_payroll_statutory_payments' ) ) {
			return;
		}
		$rows = $source->rows(
			'SELECT t.id, t.amount, t.payment_date, t.reference, p.id AS payment_id, p.authority, p.type, p.code, p.period_year, p.period_month
			FROM {hbfwc_payroll_statutory_payment_transactions} t
			LEFT JOIN {hbfwc_payroll_statutory_payments} p ON p.id = t.statutory_payment_id
			ORDER BY t.id'
		);
		$context->report->found( $this->key(), count( $rows ) );
		// A dry run has created no deduction yet: match on the legacy ones it plans to import.
		$slugs = $context->dry_run ? self::legacySlugs( $context ) : self::deductionSlugs();
		global $wpdb;

		foreach ( $rows as $row ) {
			$legacy_id = (int) $row['id'];
			if ( null === $row['payment_id'] ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, __( 'The old system lost which contribution this payment was for; enter it by hand.', 'radius-hotel-booking-residencetata' ), 'error' );
				continue;
			}
			$code = (string) $row['code'];
			if ( ! in_array( $code, self::TAX_CODES, true ) ) {
				$prefix  = 'garnishments' === $row['type'] ? 'GAR_' : 'DED_';
				$matches = $slugs[ $code ] ?? array();
				if ( 1 !== count( $matches ) ) {
					$context->report->count( $this->key(), 'failed' );
					$context->report->issue(
						$this->key(),
						$legacy_id,
						/* translators: 1: name, 2: amount, 3: date. */
						sprintf( __( 'A payment for %1$s (%2$s on %3$s) matches no single imported deduction; enter it by hand.', 'radius-hotel-booking-residencetata' ), $code, number_format( (float) $row['amount'], 2, '.', '' ), substr( (string) $row['payment_date'], 0, 10 ) ),
						'error'
					);
					continue;
				}
				$code = $prefix . $matches[0];
			}
			$data = array(
				'year'      => (int) $row['period_year'],
				'month'     => (int) $row['period_month'],
				'authority' => self::text( $row['authority'] ),
				'code'      => $code,
				'amount'    => number_format( (float) $row['amount'], 2, '.', '' ),
				'paid_on'   => substr( (string) $row['payment_date'], 0, 10 ),
				'method'    => null,
				'reference' => mb_substr( self::text( $row['reference'] ?? '' ), 0, 150 ),
			);
			$write = static function ( ?int $id = null ) use ( $wpdb, $data ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- history rows, written as they were.
				if ( $id ) {
					$wpdb->update( StatutoryService::table(), $data, array( 'id' => $id ) );
					return $id;
				}
				$wpdb->insert(
					StatutoryService::table(),
					$data + array(
						'created_by' => get_current_user_id() ? get_current_user_id() : null,
						'created_at' => current_time( 'mysql' ),
						'updated_at' => current_time( 'mysql' ),
					)
				);
				// phpcs:enable
				return (int) $wpdb->insert_id;
			};
			$this->import(
				$context,
				$legacy_id,
				$data,
				static fn() => 0,
				static fn() => $write(),
				static fn( int $id ) => $write( $id )
			);
		}
	}

	/**
	 * Imported deductions by the legacy slug of their name: slug => ids.
	 *
	 * @return array<string, int[]>
	 */
	private static function deductionSlugs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a few rows, once per import.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i', EmployeeDeductionRepository::table() ), ARRAY_A );
		$slugs = array();
		foreach ( $rows as $row ) {
			$slugs[ str_replace( '-', '_', sanitize_title( (string) $row['name'] ) ) ][] = (int) $row['id'];
		}
		return $slugs;
	}

	/**
	 * The legacy deductions by slug (a dry run): slug => placeholder ids, one per entry.
	 *
	 * @param ImportContext $context The run.
	 * @return array<string, int[]>
	 */
	private static function legacySlugs( ImportContext $context ): array {
		$slugs = array();
		foreach ( $context->source->rows( "SELECT meta_value FROM {usermeta} WHERE meta_key = 'staff_deductions'" ) as $meta ) {
			foreach ( (array) maybe_unserialize( (string) $meta['meta_value'] ) as $entry ) {
				$name = is_array( $entry ) ? (string) ( $entry['fields']['name'] ?? '' ) : '';
				if ( '' !== $name ) {
					$slugs[ str_replace( '-', '_', sanitize_title( self::text( $name ) ) ) ][] = 0;
				}
			}
		}
		return $slugs;
	}
}
