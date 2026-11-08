/**
 * Data import (`#/tata/import`, M18 T2b, 18.8): bring the client's data
 * across from the legacy hotel plugins. Always a dry run first — it reads the
 * legacy tables, counts what would be imported and lists the discrepancies,
 * writing nothing; the import is offered only after a dry run with the same
 * settings (the server refuses it otherwise). Administrators only.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	AlertTriangle,
	CheckCircle2,
	DatabaseZap,
	XCircle,
} from 'lucide-react';
import {
	Badge,
	Button,
	ConfirmDialog,
	EmptyState,
	Field,
	Input,
	Panel,
	Skeleton,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { DateField } from '../pickers';
import { useImportStatus, useRunImport } from './api';

const OUTCOMES = [
	'created',
	'linked',
	'updated',
	'unchanged',
	'skipped',
	'failed',
];

/**
 * Outcome column headings.
 *
 * @return {Object} Key → label.
 */
const outcomeLabels = () => ( {
	found: __( 'Found', 'radius-hotel-booking-residencetata' ),
	created: __( 'New', 'radius-hotel-booking-residencetata' ),
	// An existing record adopted (same name or number), not changed.
	linked: __( 'Linked', 'radius-hotel-booking-residencetata' ),
	updated: __( 'Updated', 'radius-hotel-booking-residencetata' ),
	unchanged: __( 'Unchanged', 'radius-hotel-booking-residencetata' ),
	skipped: __( 'Skipped', 'radius-hotel-booking-residencetata' ),
	failed: __( 'Failed', 'radius-hotel-booking-residencetata' ),
} );

/**
 * The run parameters as the server compares them.
 *
 * @param {Object} values Form values.
 * @return {Object} `{ source_prefix, as_of, only }`.
 */
const paramsOf = ( values ) => ( {
	source_prefix: values.source_prefix.trim(),
	as_of: values.as_of,
	only: [],
} );

/**
 * One run's report.
 *
 * @param {Object} props        Props.
 * @param {Object} props.last   The stored run.
 * @param {Object} props.labels Entity key → label (the registered importers).
 * @return {JSX.Element} Report.
 */
function Report( { last, labels: names = {} } ) {
	const labels = outcomeLabels();
	const { source, counts, issues } = last.report;
	const errors = issues.filter( ( issue ) => issue.level === 'error' );
	const entities = Object.entries( counts );

	return (
		<Panel
			title={
				last.dry_run
					? __( 'Last dry run', 'radius-hotel-booking-residencetata' )
					: __( 'Last import', 'radius-hotel-booking-residencetata' )
			}
			description={ sprintf(
				/* translators: 1: date and time, 2: table prefix, 3: cutover date or "now". */
				__(
					'%1$s · tables %2$s* · cutover %3$s',
					'radius-hotel-booking-residencetata'
				),
				format.formatDateTime( new Date( last.finished_at * 1000 ) ),
				last.params.source_prefix,
				last.params.as_of ||
					__( 'now', 'radius-hotel-booking-residencetata' )
			) }
			actions={
				last.dry_run ? (
					<Badge variant="secondary">
						{ __(
							'Nothing written',
							'radius-hotel-booking-residencetata'
						) }
					</Badge>
				) : null
			}
			bodyClassName="space-y-6"
		>
			<section>
				<h3 className="m-0 mb-2 text-sm font-semibold text-heading">
					{ __(
						'In the legacy system',
						'radius-hotel-booking-residencetata'
					) }
				</h3>
				<dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-2">
					{ Object.entries( source ).map( ( [ key, row ] ) => (
						<div
							key={ key }
							className="flex items-baseline justify-between gap-3 border-b border-border py-1.5 text-sm"
						>
							<dt className="text-muted-foreground">
								{ row.label }
							</dt>
							<dd className="m-0 font-semibold tabular-nums text-heading">
								{ row.count.toLocaleString() }
							</dd>
						</div>
					) ) }
				</dl>
			</section>

			<section>
				<h3 className="m-0 mb-2 text-sm font-semibold text-heading">
					{ last.dry_run
						? __(
								'Would import',
								'radius-hotel-booking-residencetata'
						  )
						: __(
								'Imported',
								'radius-hotel-booking-residencetata'
						  ) }
				</h3>
				{ entities.length ? (
					<div className="overflow-x-auto rounded-lg border border-border">
						<table className="w-full min-w-[32rem] text-sm">
							<thead className="bg-muted text-left text-xs text-muted-foreground">
								<tr>
									<th className="px-3 py-2 font-medium">
										{ __(
											'Data',
											'radius-hotel-booking-residencetata'
										) }
									</th>
									{ [ 'found', ...OUTCOMES ].map( ( key ) => (
										<th
											key={ key }
											className="px-3 py-2 text-right font-medium"
										>
											{ labels[ key ] }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ entities.map( ( [ entity, row ] ) => (
									<tr
										key={ entity }
										className="border-t border-border"
									>
										<td className="px-3 py-2 font-medium text-heading">
											{ names[ entity ] || entity }
										</td>
										{ [ 'found', ...OUTCOMES ].map(
											( key ) => (
												<td
													key={ key }
													className={ `px-3 py-2 text-right tabular-nums ${
														key === 'failed' &&
														row[ key ]
															? 'font-semibold text-destructive'
															: ''
													}` }
												>
													{ row[
														key
													].toLocaleString() }
												</td>
											)
										) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : (
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'No data importers are installed yet, so the run only summarises the legacy data.',
							'radius-hotel-booking-residencetata'
						) }
					</p>
				) }
			</section>

			<section>
				<h3 className="m-0 mb-2 flex items-center gap-2 text-sm font-semibold text-heading">
					{ __( 'To look at', 'radius-hotel-booking-residencetata' ) }
					{ issues.length ? (
						<span className="text-xs font-normal text-muted-foreground">
							{ sprintf(
								/* translators: 1: number of errors, 2: number of warnings. */
								__(
									'%1$d errors, %2$d warnings',
									'radius-hotel-booking-residencetata'
								),
								errors.length,
								issues.length - errors.length
							) }
						</span>
					) : null }
				</h3>
				{ issues.length ? (
					<ul className="m-0 max-h-[26rem] list-none space-y-1.5 overflow-y-auto p-0">
						{ issues.map( ( issue, index ) => (
							<li
								key={ index }
								className="flex items-start gap-2 rounded-lg border border-border px-3 py-2 text-sm"
							>
								{ issue.level === 'error' ? (
									<XCircle
										className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
										aria-hidden="true"
									/>
								) : (
									<AlertTriangle
										className="mt-0.5 h-4 w-4 shrink-0 text-warning"
										aria-hidden="true"
									/>
								) }
								<span className="min-w-0">
									<span className="font-medium text-heading">
										{ names[ issue.entity ] ||
											issue.entity }
										{ issue.legacy_id
											? ` #${ issue.legacy_id }`
											: '' }
									</span>{ ' ' }
									<span className="text-muted-foreground">
										{ issue.message }
									</span>
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="m-0 flex items-center gap-2 text-sm text-muted-foreground">
						<CheckCircle2
							className="h-4 w-4 text-success"
							aria-hidden="true"
						/>
						{ __(
							'Nothing to look at.',
							'radius-hotel-booking-residencetata'
						) }
					</p>
				) }
			</section>
		</Panel>
	);
}

/**
 * The screen.
 *
 * @return {JSX.Element} Screen.
 */
export default function ImportScreen() {
	const { data: status, isPending, error, refetch } = useImportStatus();
	const dryRun = useRunImport( true );
	const realRun = useRunImport( false );
	const [ values, setValues ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ confirming, setConfirming ] = useState( false );

	if ( isPending ) {
		return (
			<div className="space-y-4">
				<Skeleton className="h-48 w-full rounded-xl" />
				<Skeleton className="h-72 w-full rounded-xl" />
			</div>
		);
	}
	if ( error || ! status ) {
		return (
			<Panel>
				<EmptyState
					icon={ DatabaseZap }
					title={ __(
						'The import could not be loaded',
						'radius-hotel-booking-residencetata'
					) }
					description={ error?.message }
					action={
						<Button variant="outline" onClick={ () => refetch() }>
							{ __(
								'Try again',
								'radius-hotel-booking-residencetata'
							) }
						</Button>
					}
					className="border-0"
				/>
			</Panel>
		);
	}

	const form = values || {
		source_prefix:
			status.last?.params?.source_prefix || status.source_prefix,
		as_of: status.last?.params?.as_of || '',
	};
	const set = ( key ) => ( event ) => {
		const value = event.target.value;
		setValues( { ...form, [ key ]: value } );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};
	const params = paramsOf( form );
	const last = status.last;
	const found = status.tables.filter( ( table ) => table.exists ).length;
	const missing = status.tables.filter( ( table ) => ! table.exists );
	const prefixChanged = params.source_prefix !== status.source_prefix;
	// The import is offered after a dry run with exactly these settings.
	const ready =
		last?.dry_run &&
		! last.incomplete &&
		JSON.stringify( last.params ) === JSON.stringify( params ) &&
		Date.now() / 1000 - last.finished_at < 86400;
	const busy = dryRun.isPending || realRun.isPending;

	const run = async ( mutation ) => {
		try {
			const { message } = await mutation.mutateAsync( params );
			setValues( null );
			toast.success( message );
		} catch ( err ) {
			const mapped = Object.fromEntries(
				Object.entries( err?.errors || {} ).map( ( [ key, value ] ) => [
					key,
					value?.first_message,
				] )
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<div className="space-y-4">
			<Panel
				title={ __(
					'Legacy data',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'Bring rooms, rates, guests, staff and future bookings across from the old hotel plugins. The old data is only read, never changed.',
					'radius-hotel-booking-residencetata'
				) }
				bodyClassName="space-y-4"
			>
				<div className="grid gap-4 sm:grid-cols-2">
					<Field
						label={ __(
							'Legacy tables start with',
							'radius-hotel-booking-residencetata'
						) }
						description={ sprintf(
							/* translators: %s: this site's table prefix. */
							__(
								"This site's own prefix is %s. Change it only for a copy of the old database.",
								'radius-hotel-booking-residencetata'
							),
							status.site_prefix
						) }
						error={ errors.source_prefix }
					>
						<Input
							value={ form.source_prefix }
							onChange={ set( 'source_prefix' ) }
							autoComplete="off"
							spellCheck={ false }
							className="font-mono"
						/>
					</Field>
					<Field
						label={ __(
							'Cutover date',
							'radius-hotel-booking-residencetata'
						) }
						description={ __(
							'Leave empty for the real cutover: bookings ending after now are imported. Set a date only for a test run.',
							'radius-hotel-booking-residencetata'
						) }
						error={ errors.as_of }
					>
						<DateField
							value={ form.as_of }
							onChange={ ( day ) =>
								set( 'as_of' )( { target: { value: day } } )
							}
							clearable
						/>
					</Field>
				</div>

				<p
					className={ `m-0 flex items-start gap-2 text-sm ${
						missing.length
							? 'text-destructive'
							: 'text-muted-foreground'
					}` }
				>
					{ missing.length ? (
						<XCircle
							className="mt-0.5 h-4 w-4 shrink-0"
							aria-hidden="true"
						/>
					) : (
						<CheckCircle2
							className="mt-0.5 h-4 w-4 shrink-0 text-success"
							aria-hidden="true"
						/>
					) }
					<span>
						{ prefixChanged
							? __(
									'Run a dry run to check the tables with the new prefix.',
									'radius-hotel-booking-residencetata'
							  )
							: sprintf(
									/* translators: 1: tables found, 2: tables needed. */
									_n(
										'%1$d of %2$d legacy table found.',
										'%1$d of %2$d legacy tables found.',
										status.tables.length,
										'radius-hotel-booking-residencetata'
									),
									found,
									status.tables.length
							  ) }
						{ ! prefixChanged && missing.length
							? ` ${ sprintf(
									/* translators: %s: comma-separated table names. */
									__(
										'Missing: %s',
										'radius-hotel-booking-residencetata'
									),
									missing
										.map( ( table ) => table.name )
										.join( ', ' )
							  ) }`
							: '' }
					</span>
				</p>

				<div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
					<Button
						variant={ ready ? 'outline' : 'default' }
						disabled={ busy }
						onClick={ () => run( dryRun ) }
					>
						{ dryRun.isPending
							? __(
									'Running the dry run…',
									'radius-hotel-booking-residencetata'
							  )
							: __(
									'Run a dry run',
									'radius-hotel-booking-residencetata'
							  ) }
					</Button>
					<Button
						disabled={ ! ready || busy }
						onClick={ () => setConfirming( true ) }
						title={
							ready
								? undefined
								: __(
										'Run a dry run with these settings first.',
										'radius-hotel-booking-residencetata'
								  )
						}
					>
						{ realRun.isPending
							? __(
									'Importing…',
									'radius-hotel-booking-residencetata'
							  )
							: __(
									'Import',
									'radius-hotel-booking-residencetata'
							  ) }
					</Button>
				</div>
				{ ! ready ? (
					<p className="m-0 text-right text-xs text-muted-foreground">
						{ last?.dry_run &&
						last.incomplete &&
						JSON.stringify( last.params ) ===
							JSON.stringify( params )
							? __(
									'Some legacy tables are missing: fix the prefix and run the dry run again.',
									'radius-hotel-booking-residencetata'
							  )
							: __(
									'The import is available after a dry run with these settings.',
									'radius-hotel-booking-residencetata'
							  ) }
					</p>
				) : null }
			</Panel>

			{ last ? (
				<Report
					last={ last }
					labels={ Object.fromEntries(
						( status.entities || [] ).map( ( entity ) => [
							entity.key,
							entity.label,
						] )
					) }
				/>
			) : null }

			<Suspense fallback={ null }>
				{ confirming ? (
					<ConfirmDialog
						open
						onOpenChange={ ( next ) =>
							! next && setConfirming( false )
						}
						destructive
						title={ __(
							'Import the legacy data now?',
							'radius-hotel-booking-residencetata'
						) }
						description={
							last?.has_errors
								? __(
										'The dry run reported errors: those records will not be imported. Everything else is written into the new system. Running it again later updates what changed and creates no duplicates.',
										'radius-hotel-booking-residencetata'
								  )
								: __(
										'Everything the dry run counted is written into the new system. Running it again later updates what changed and creates no duplicates.',
										'radius-hotel-booking-residencetata'
								  )
						}
						confirmLabel={ __(
							'Import',
							'radius-hotel-booking-residencetata'
						) }
						onConfirm={ () =>
							run( realRun ).finally( () =>
								setConfirming( false )
							)
						}
					/>
				) : null }
			</Suspense>
		</div>
	);
}
