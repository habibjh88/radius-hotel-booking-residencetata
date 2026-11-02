<?php
/**
 * Pay runs from the legacy payroll, as read-only history (M15 T10).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeeDeductionRepository;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayItemTypes;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollRun;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollRunRepository;
use RadiusTheme\RadiusHotelBookingTata\Payroll\StatutoryTable;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_payroll_runs` + `hbfwc_payroll_items` (one line per employee) +
 * `hbfwc_payroll_itemmeta` (the line's breakdown, PHP-serialized) +
 * `hbfwc_payroll_statutory_items` → pay runs here, **as the legacy system
 * computed them, never recalculated**:
 *
 * - only `settled` and `voided` runs come over; a `draft` or `finalized`
 *   run is reported, to be run again here;
 * - a line keeps the legacy gross, net, hours, taxes; its breakdown is
 *   rebuilt in this plugin's shape (earnings, statutory lines, deductions,
 *   garnishments, custom items) so the payslip prints, with
 *   `imported: true`;
 * - its ledger gets what later runs need: paid / sick time off hours within
 *   the quota (`*_auto_deduct` hours over it as unpaid), unpaid hours, the
 *   retained vacation money (`staff_vb_{Y}` meta), each deduction by the
 *   imported deduction (matched on the legacy slug of its name) — so yearly
 *   quotas and the once-a-month rule carry on; garnishments as
 *   `garnishment_imported`, which counts against nothing (an imported
 *   garnishment's total is already what was still owed);
 * - its statutory rows come from the legacy statutory items of the run (a
 *   voided run's negated copies are left out; a voided run counts nowhere
 *   anyway), tax codes as they are, a deduction / garnishment slug →
 *   `DED_<id>` / `GAR_<id>`.
 *
 * Afterwards the legacy per-year balances `staff_pto_{Y}`, `staff_uto_{Y}`, `staff_sick_{Y}`
 * (hours) and `staff_vb_{Y}` (vacation money) are checked against the
 * imported history: they are derived here, so a difference means history is
 * missing and is reported, not stored.
 */
final class PayrollHistoryImporter extends AbstractImporter {

	/**
	 * Legacy per-year balance meta → this plugin's ledger code and unit.
	 */
	private const BALANCES = array(
		'staff_pto'  => array( 'paid_time_off', 'hours' ),
		'staff_sick' => array( 'sick_pay', 'hours' ),
		'staff_uto'  => array( 'unpaid_time_off', 'hours' ),
		'staff_vb'   => array( 'vacation_retained', 'amount' ),
	);

	/**
	 * Deductions by employee: legacy slug => `{ id, kind, authority, label }` (cache).
	 *
	 * @var array<int, array<string, array>>
	 */
	private array $deductions = array();

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'payroll_run';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Pay runs (history)', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'employee', 'pay_schedule', 'employee_deduction' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source = $context->source;
		if ( ! $source->has( 'hbfwc_payroll_runs' ) || ! $source->has( 'hbfwc_payroll_items' ) ) {
			return;
		}
		$runs = $source->rows( 'SELECT * FROM {hbfwc_payroll_runs} ORDER BY pay_date, id' );
		$context->report->found( $this->key(), count( $runs ) );
		$repo = new PayrollRunRepository();

		foreach ( $runs as $run ) {
			$legacy_id = (int) $run['id'];
			$status    = (string) $run['status'];
			if ( ! in_array( $status, array( PayrollRun::SETTLED, PayrollRun::VOIDED ), true ) ) {
				$context->report->count( $this->key(), 'skipped' );
				$context->report->issue(
					$this->key(),
					$legacy_id,
					/* translators: 1: status, 2: pay date. */
					sprintf( __( 'A %1$s run (pay day %2$s) is not history yet; run that period again here.', 'radius-hotel-booking-residencetata' ), $status, substr( (string) $run['pay_date'], 0, 10 ) )
				);
				continue;
			}
			$lines = $this->lines( $context, $run );
			if ( null === $lines ) {
				continue;
			}
			$fields = $this->runFields( $context, $run, $lines );
			$write  = function ( int $run_id ) use ( $repo, $run, $lines ) {
				$repo->clearLines( $run_id );
				foreach ( $lines as $line ) {
					$repo->saveLine( array( 'run_id' => $run_id ) + $line['fields'], $line['ledger'], substr( (string) $run['pay_date'], 0, 10 ), $line['statutory'] );
				}
			};
			$this->import(
				$context,
				$legacy_id,
				array(
					'run'   => $fields,
					'lines' => array_map( static fn( $l ) => array( $l['fields'], $l['ledger'], $l['statutory'] ), $lines ),
				),
				static fn() => 0,
				static function () use ( $repo, $fields, $write ) {
					$id = (int) $repo->create( $fields )->id;
					$write( $id );
					return $id;
				},
				static function ( int $id ) use ( $repo, $fields, $write ) {
					$repo->update( $id, $fields );
					$write( $id );
				}
			);
		}

		$this->checkBalances( $context );
	}

	/**
	 * The new run's columns.
	 *
	 * @param ImportContext $context The run.
	 * @param array         $run     Legacy run.
	 * @param array[]       $lines   Its lines.
	 * @return array
	 */
	private function runFields( ImportContext $context, array $run, array $lines ): array {
		$sum      = static fn( $key ) => number_format( array_sum( array_map( static fn( $l ) => (float) $l['fields'][ $key ], $lines ) ), 2, '.', '' );
		$voided   = PayrollRun::VOIDED === $run['status'];
		$schedule = $context->map->newId( 'pay_schedule', (int) $run['payday_id'] );
		return array(
			'pay_schedule_id'     => $schedule > 0 ? $schedule : 0,
			'schedule_name'       => mb_substr( self::text( $run['payday_name'] ), 0, 100 ),
			'pay_interval'        => (string) $run['payday_interval'],
			'period_index'        => 0,
			'period_start'        => substr( (string) $run['period_start'], 0, 10 ),
			'period_end'          => substr( (string) $run['period_end'], 0, 10 ),
			'pay_date'            => substr( (string) $run['pay_date'], 0, 10 ),
			'status'              => (string) $run['status'],
			'employees'           => count( $lines ),
			'gross_total'         => $sum( 'gross' ),
			'employee_tax_total'  => $sum( 'employee_tax' ),
			'employer_tax_total'  => $sum( 'employer_tax' ),
			'net_total'           => $sum( 'net' ),
			'employer_cost_total' => $sum( 'employer_cost' ),
			'settled_at'          => (string) $run['updated_at'],
			'voided_at'           => $voided ? (string) $run['updated_at'] : null,
			'void_reason'         => $voided ? __( 'Voided in the old system.', 'radius-hotel-booking-residencetata' ) : null,
		);
	}

	/**
	 * A legacy run's lines in this plugin's shape, or null when one cannot be imported (reported).
	 *
	 * @param ImportContext $context The run.
	 * @param array         $run     Legacy run.
	 * @return array[]|null `{ fields, ledger, statutory }`.
	 */
	private function lines( ImportContext $context, array $run ): ?array {
		$source = $context->source;
		$items  = $source->rows( 'SELECT * FROM {hbfwc_payroll_items} WHERE payroll_run_id = %d ORDER BY id', array( (int) $run['id'] ) );
		$meta   = array();
		if ( $source->has( 'hbfwc_payroll_itemmeta' ) && $items ) {
			$ids = implode( ',', array_map( 'intval', array_column( $items, 'id' ) ) );
			// The ids are integers from the legacy table itself.
			foreach ( $source->rows( "SELECT payroll_item_id, parent_id, meta_key, meta_value, meta_type FROM {hbfwc_payroll_itemmeta} WHERE payroll_item_id IN ( $ids ) ORDER BY id" ) as $m ) {
				if ( 0 === (int) $m['parent_id'] ) {
					$meta[ (int) $m['payroll_item_id'] ][ (string) $m['meta_key'] ] = array(
						'value' => maybe_unserialize( (string) $m['meta_value'] ),
						'type'  => (string) $m['meta_type'],
					);
				}
			}
		}
		$statutory = array();
		if ( $source->has( 'hbfwc_payroll_statutory_items' ) ) {
			foreach ( $source->rows( 'SELECT * FROM {hbfwc_payroll_statutory_items} WHERE payroll_run_id = %d ORDER BY id', array( (int) $run['id'] ) ) as $s ) {
				$statutory[ (int) $s['user_id'] ][] = $s;
			}
		}

		$lines = array();
		foreach ( $items as $item ) {
			// Negative: an employee this dry run plans to create.
			$employee_id = $context->map->newId( 'employee', (int) $item['user_id'] );
			if ( 0 === $employee_id ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue(
					$this->key(),
					(int) $run['id'],
					/* translators: %d: legacy user id. */
					sprintf( __( 'A line of this run belongs to staff member #%d, who was not imported; the run was left out.', 'radius-hotel-booking-residencetata' ), (int) $item['user_id'] ),
					'error'
				);
				return null;
			}
			$lines[] = $this->line( $employee_id, $run, $item, $meta[ (int) $item['id'] ] ?? array(), $statutory[ (int) $item['user_id'] ] ?? array() );
		}
		return $lines;
	}

	/**
	 * One line.
	 *
	 * @param int     $employee_id New employee id.
	 * @param array   $run         Legacy run.
	 * @param array   $item        Legacy item.
	 * @param array   $meta        Its meta: key => `{ value, type }`.
	 * @param array[] $stat_rows   Its legacy statutory items.
	 * @return array `{ fields, ledger, statutory }`.
	 */
	private function line( int $employee_id, array $run, array $item, array $meta, array $stat_rows ): array {
		$money  = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
		$hours  = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
		$value  = static fn( $key ) => $meta[ $key ]['value'] ?? null;
		$types  = PayItemTypes::all();
		$ledger = array();

		// Earnings: the additional pay entries, with hours when the type has hours.
		$earnings = array();
		foreach ( $types as $code => $type ) {
			if ( ! isset( $meta[ $code ] ) || 'vacation_pay' === $code ) {
				continue;
			}
			$with_hours = isset( $meta[ $code . '_value' ] );
			$earnings[] = array(
				'key'      => $code,
				'label'    => $type['label'],
				'quantity' => $with_hours ? $hours( $value( $code ) ) : null,
				'amount'   => $money( $with_hours ? $value( $code . '_value' ) : $value( $code ) ),
				'effect'   => 'deduct' === ( $meta[ $code ]['type'] ?? '' ) ? 'deduct' : (string) ( $type['effect'] ?? 'add' ),
			);
		}
		foreach ( array( 'paid_time_off', 'sick_pay' ) as $code ) {
			$over = (float) $value( $code . '_auto_deduct' );
			if ( isset( $meta[ $code ] ) ) {
				$within = max( 0, (float) $value( $code ) - $over );
				if ( $within > 0 ) {
					$ledger[] = array(
						'code'     => $code,
						'quantity' => $hours( $within ),
					);
				}
			}
			if ( $over > 0 ) {
				$ledger[]   = array(
					'code'     => 'unpaid_time_off',
					'quantity' => $hours( $over ),
				);
				$earnings[] = array(
					'key'      => $code . '_over',
					/* translators: %s: pay type (Paid time off, Sick pay). */
					'label'    => sprintf( __( '%s over the yearly limit', 'radius-hotel-booking-residencetata' ), $types[ $code ]['label'] ),
					'quantity' => $hours( $over ),
					'amount'   => $money( $value( $code . '_auto_deduct_value' ) ),
					'effect'   => 'deduct',
				);
			}
		}
		if ( isset( $meta['unpaid_time_off'] ) && (float) $value( 'unpaid_time_off' ) > 0 ) {
			$ledger[] = array(
				'code'     => 'unpaid_time_off',
				'quantity' => $hours( $value( 'unpaid_time_off' ) ),
			);
		}
		if ( isset( $meta['vacation_pay'] ) ) {
			$earnings[] = array(
				'key'      => 'vacation_pay',
				'label'    => $types['vacation_pay']['label'] ?? __( 'Vacation pay', 'radius-hotel-booking-residencetata' ),
				'quantity' => null,
				'amount'   => $money( $value( 'vacation_pay' ) ),
				'effect'   => 'add',
			);
		}
		$year = substr( (string) $run['pay_date'], 0, 4 );
		if ( isset( $meta[ 'staff_vb_' . $year ] ) && (float) $value( 'staff_vb_' . $year ) > 0 ) {
			$ledger[] = array(
				'code'   => 'vacation_retained',
				'amount' => $money( $value( 'staff_vb_' . $year ) ),
			);
		}

		// Deductions and garnishments, by the legacy slug of their name.
		$mine         = $this->deductionsOf( $employee_id );
		$deductions   = array();
		$garnishments = array();
		foreach ( $meta as $key => $m ) {
			if ( 'deduction_part' === $m['type'] ) {
				$found        = $mine[ $key ] ?? null;
				$employer     = $value( $key . '_contribution' );
				$deductions[] = array(
					'key'             => $found ? 'deduction_' . $found['id'] : $key,
					'label'           => $found ? $found['label'] : $key,
					'amount'          => $money( $m['value'] ),
					'employer_amount' => $money( $employer ),
				);
				if ( $found ) {
					$ledger[] = array(
						'code'     => 'deduction',
						'ref'      => $found['id'],
						'amount'   => $money( $m['value'] ),
						'employer' => $money( $employer ),
					);
				}
			} elseif ( 'garnishment_part' === $m['type'] ) {
				$found          = $mine[ $key ] ?? null;
				$garnishments[] = array(
					'key'    => $found ? 'garnishment_' . $found['id'] : $key,
					'label'  => $found ? $found['label'] : $key,
					'amount' => $money( $m['value'] ),
				);
				if ( $found ) {
					// Not `garnishment`: the imported garnishment's total is already what was still owed.
					$ledger[] = array(
						'code'   => 'garnishment_imported',
						'ref'    => $found['id'],
						'amount' => $money( $m['value'] ),
					);
				}
			}
		}

		// The statutory lines: from the legacy statutory items, else from the taxes meta.
		$catalogue = array();
		foreach ( StatutoryTable::lines() as $legacy_key => $def ) {
			$catalogue[ $def['code'] . '|' . $def['side'] ] = $def + array( 'key' => $legacy_key );
		}
		$stat_lines = array();
		$stat_rows  = PayrollRun::VOIDED === $run['status'] ? array_filter( $stat_rows, static fn( $s ) => (float) $s['amount'] > 0 ) : $stat_rows;
		$rows       = array();
		foreach ( $stat_rows as $s ) {
			$side = 'employer' === $s['party'] ? 'employer' : 'employee';
			$code = (string) $s['code'];
			if ( isset( $catalogue[ $code . '|' . $side ] ) ) {
				$def          = $catalogue[ $code . '|' . $side ];
				$stat_lines[] = array(
					'key'       => $def['key'],
					'label'     => $def['label'],
					'authority' => $def['authority'],
					'side'      => $side,
					'base'      => $money( $s['base_amount'] ),
					'rate'      => (string) $s['rate'],
					'amount'    => $money( $s['amount'] ),
				);
				$row_key      = $def['authority'] . '|' . $code;
				$rows[ $row_key ] = $rows[ $row_key ] ?? array(
					'authority' => $def['authority'],
					'code'      => $code,
					'label'     => $def['label'],
					'kind'      => 'statutory',
					'base'      => $money( $s['base_amount'] ),
					'employee'  => '0',
					'employer'  => '0',
				);
			} else {
				$found   = $mine[ $code ] ?? null;
				$kind    = 'garnishments' === $s['type'] ? 'garnishment' : 'deduction';
				$row_key = $found ? ( 'garnishment' === $kind ? 'GAR_' : 'DED_' ) . $found['id'] : 'LEGACY_' . $code;
				$rows[ $row_key ] = $rows[ $row_key ] ?? array(
					'authority' => (string) $s['authority'],
					'code'      => mb_substr( $row_key, 0, 60 ),
					'label'     => $found ? $found['label'] : (string) $s['name'],
					'kind'      => $kind,
					'base'      => $money( $s['base_amount'] ),
					'employee'  => '0',
					'employer'  => '0',
				);
			}
			$rows[ $row_key ][ $side ] = $money( (float) $rows[ $row_key ][ $side ] + (float) $s['amount'] );
		}
		if ( ! $stat_lines ) {
			foreach ( array(
				'paye' => 'employee',
				'payroll_tax' => 'employer',
			) as $meta_key => $side ) {
				foreach ( (array) $value( $meta_key ) as $legacy_key => $amount ) {
					$def = StatutoryTable::lines()[ $legacy_key ] ?? null;
					if ( $def && (float) $amount ) {
						$stat_lines[] = array(
							'key'       => $legacy_key,
							'label'     => $def['label'],
							'authority' => $def['authority'],
							'side'      => $side,
							'base'      => $money( $item['gross_pay'] ),
							'rate'      => '',
							'amount'    => $money( $amount ),
						);
					}
				}
			}
		}

		$custom = array();
		foreach ( (array) $value( 'custom_items' ) as $c ) {
			$custom[] = array(
				'type' => 'deduction' === ( $c['type'] ?? '' ) ? 'deduction' : 'addition',
				'name' => (string) ( $c['name'] ?? '' ),
				'net'  => $money( $c['amount'] ?? 0 ),
				'tax'  => $money( ! empty( $c['taxable'] ) ? (float) ( $c['amount'] ?? 0 ) * (float) ( $c['tax_rate'] ?? 0 ) / 100 : 0 ),
			);
		}

		$hourly       = 'hourly' === (string) $item['payment_type'];
		$gross        = $money( $item['gross_pay'] );
		$employee_tax = $money( $value( 'paye_total' ) );
		$employer_tax = $money( $value( 'payroll_tax_total' ) );
		$employer_ded = (float) $value( 'employer_contribution_total' );
		$breakdown    = array(
			'imported'     => true,
			'hours'        => $hours( $item['total_hours'] ),
			'base'         => $money( $hourly ? (float) $item['regular_hours'] * (float) $item['base_pay'] : $item['base_salary'] ),
			'earnings'     => $earnings,
			'vacation'     => null,
			'gross'        => $gross,
			'statutory'    => array(
				'lines'          => $stat_lines,
				'employee_total' => $employee_tax,
				'employer_total' => $employer_tax,
			),
			'deductions'   => $deductions,
			'garnishments' => $garnishments,
			'custom'       => $custom,
			'net'          => $money( $item['net_pay'] ),
			'memo'         => self::text( $item['notes'] ?? '' ),
		);
		$employee     = self::employee( $employee_id );

		return array(
			'fields'    => array(
				'employee_id'    => $employee_id,
				'employee_name'  => $employee['name'],
				'employee_no'    => $employee['number'],
				'inputs'         => wp_json_encode( array( 'imported' => true ) ),
				'settings'       => wp_json_encode( array( 'imported' => true ) ),
				'breakdown'      => wp_json_encode( $breakdown ),
				'gross'          => $gross,
				'employee_tax'   => $employee_tax,
				'employer_tax'   => $employer_tax,
				'net'            => $money( $item['net_pay'] ),
				'employer_cost'  => $money( (float) $gross + (float) $employer_tax + $employer_ded ),
				'payment_method' => mb_substr( self::text( $value( 'payment_method' ) ?? '' ), 0, 60 ),
				'payment_ref'    => mb_substr( self::text( $value( 'payment_ref' ) ?? '' ), 0, 100 ),
			),
			'ledger'    => $ledger,
			'statutory' => array_values( array_filter( $rows, static fn( $r ) => (float) $r['employee'] || (float) $r['employer'] ) ),
		);
	}

	/**
	 * An employee's imported deductions by the legacy slug of their name.
	 *
	 * @param int $employee_id Employee.
	 * @return array<string, array{id:int, label:string}>
	 */
	private function deductionsOf( int $employee_id ): array {
		if ( ! isset( $this->deductions[ $employee_id ] ) ) {
			$this->deductions[ $employee_id ] = array();
			foreach ( ( new EmployeeDeductionRepository() )->forEmployee( $employee_id ) as $row ) {
				$slug                                       = str_replace( '-', '_', sanitize_title( (string) $row->name ) );
				$this->deductions[ $employee_id ][ $slug ] = array(
					'id'    => (int) $row->id,
					'label' => (string) $row->name,
				);
			}
		}
		return $this->deductions[ $employee_id ];
	}

	/**
	 * Check the legacy per-year balances against the imported history (report only).
	 *
	 * @param ImportContext $context The run.
	 * @return void
	 */
	private function checkBalances( ImportContext $context ): void {
		// No `{4}` quantifier: the source reads `{name}` as a table.
		$rows = $context->source->rows( "SELECT user_id, meta_key, meta_value FROM {usermeta} WHERE meta_key REGEXP '^staff_(pto|uto|sick|vb)_[0-9][0-9][0-9][0-9]$' ORDER BY user_id, meta_key" );
		if ( ! $rows || $context->dry_run ) {
			if ( $rows ) {
				$context->report->issue( $this->key(), '', __( 'The yearly time-off and vacation balances are checked against the history on the real import, not in a dry run.', 'radius-hotel-booking-residencetata' ) );
			}
			return;
		}
		global $wpdb;
		$ledger = PayrollRunRepository::table( 'ledger' );
		$runs   = PayrollRunRepository::table();
		foreach ( $rows as $row ) {
			if ( ! preg_match( '/^(staff_[a-z]+)_(\d{4})$/', (string) $row['meta_key'], $m ) || ! isset( self::BALANCES[ $m[1] ] ) ) {
				continue;
			}
			$employee_id = $context->map->newId( 'employee', (int) $row['user_id'] );
			if ( $employee_id <= 0 ) {
				continue;
			}
			list( $code, $unit ) = self::BALANCES[ $m[1] ];
			$legacy_value        = maybe_unserialize( (string) $row['meta_value'] );
			$legacy              = is_array( $legacy_value ) ? array_sum( array_map( 'floatval', $legacy_value ) ) : (float) $legacy_value;
			$column              = 'hours' === $unit ? 'quantity' : 'amount';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two literals.
			$here = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( l.$column ), 0 ) FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.employee_id = %d AND l.code = %s AND l.year = %d AND r.status = 'settled'", $ledger, $runs, $employee_id, $code, (int) $m[2] ) );
			if ( abs( $legacy - $here ) >= 0.01 ) {
				$employee = self::employee( $employee_id );
				$context->report->issue(
					$this->key(),
					(int) $row['user_id'],
					sprintf(
						/* translators: 1: balance name (legacy key), 2: employee, 3: old value, 4: value from the imported runs. */
						__( '%1$s of %2$s: %3$s in the old system, %4$s from the imported pay runs. Balances are derived here; check the history.', 'radius-hotel-booking-residencetata' ),
						(string) $row['meta_key'],
						$employee['name'],
						number_format( $legacy, 2, '.', '' ),
						number_format( $here, 2, '.', '' )
					)
				);
			}
		}
	}

	/**
	 * The Pro employee's name and number.
	 *
	 * @param int $employee_id Id.
	 * @return array{name: string, number: string}
	 */
	private static function employee( int $employee_id ): array {
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$employee = class_exists( $repo ) ? ( new $repo() )->find( $employee_id ) : null;
		return array(
			'name'   => $employee && method_exists( $employee, 'fullName' ) ? (string) $employee->fullName() : '#' . $employee_id,
			'number' => $employee ? (string) $employee->employee_no : '',
		);
	}
}
