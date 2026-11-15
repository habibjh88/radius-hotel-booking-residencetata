/**
 * Payroll → Tax info (read only), as the old dashboard's Payroll → Tax Info:
 * the social contributions (CNPS) and the DGI taxes (IS, CN, IGR) the pay
 * runs use today. Nothing is edited here: the figures are changed in
 * Settings → Payroll (contributions) and Settings → Côte d'Ivoire payroll
 * (taxes). Added to Pro's Payroll screen through `rtbp.payroll.tabs`.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Button, DataTable, Money, Panel, Skeleton } from '@rtbp/ui';
import { api } from '@rtbp/lib';

/**
 * "6.3 %".
 *
 * @param {number} value Percent.
 * @return {string} Label.
 */
const percent = ( value ) =>
	sprintf(
		/* translators: %s: a percentage number, e.g. 6.3. */
		__( '%s %%', 'radius-hotel-booking-residencetata' ),
		String( Number( value || 0 ) )
	);

/**
 * How a scale applies, in words.
 *
 * @param {string} mode flat|progressive.
 * @return {string} Label.
 */
const modeLabel = ( mode ) =>
	'progressive' === mode
		? __(
				'Each slice of the base at its rate',
				'radius-hotel-booking-residencetata'
		  )
		: __(
				'The rate of the bracket the base falls in, on the whole base',
				'radius-hotel-booking-residencetata'
		  );

/**
 * A label and its value, on one row.
 *
 * @param {Object}      props          Props.
 * @param {string}      props.label    Label.
 * @param {JSX.Element} props.children Value.
 * @param {string}      props.hint     Line under the label.
 * @return {JSX.Element} Row.
 */
function Figure( { label, children, hint } ) {
	return (
		<div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border py-3 last:border-b-0">
			<div>
				<p className="m-0 text-sm font-medium text-heading">
					{ label }
				</p>
				{ hint ? (
					<p className="m-0 text-xs text-muted-foreground">
						{ hint }
					</p>
				) : null }
			</div>
			<span className="text-sm font-semibold text-heading">
				{ children }
			</span>
		</div>
	);
}

/**
 * A scale's brackets: monthly taxable pay from – to, and the rate.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.rows    `{ up_to|null, rate }`.
 * @param {string}   props.caption Table caption.
 * @return {JSX.Element} Table.
 */
function Scale( { rows, caption } ) {
	const brackets = rows.map( ( row, index ) => ( {
		...row,
		from: index ? Number( rows[ index - 1 ].up_to || 0 ) : 0,
		index,
	} ) );
	return (
		<DataTable
			columns={ [
				{
					id: 'range',
					header: __(
						'Monthly taxable pay',
						'radius-hotel-booking-residencetata'
					),
					mobile: 'title',
					cell: ( row ) =>
						null === row.up_to ? (
							<span>
								{ __(
									'Above',
									'radius-hotel-booking-residencetata'
								) }{ ' ' }
								<Money value={ row.from } />
							</span>
						) : (
							<span>
								<Money value={ row.from } />
								{ ' – ' }
								<Money value={ row.up_to } />
							</span>
						),
				},
				{
					id: 'rate',
					header: __( 'Rate', 'radius-hotel-booking-residencetata' ),
					cell: ( row ) => percent( row.rate ),
				},
			] }
			rows={ brackets }
			rowKey={ ( row ) => String( row.index ) }
			total={ brackets.length }
			caption={ caption }
			empty={
				<p className="m-0 py-6 text-center text-sm text-muted-foreground">
					{ __(
						'No brackets set.',
						'radius-hotel-booking-residencetata'
					) }
				</p>
			}
		/>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function TaxInfoTab() {
	const info = useQuery( {
		queryKey: [ 'tata', 'payroll', 'tax-info' ],
		queryFn: () =>
			api.get( 'tata/payroll/tax-info' ).then( ( r ) => r.data ),
	} );

	if ( info.isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	if ( info.isError ) {
		return (
			<Panel>
				<p className="m-0 mb-3 text-sm text-muted-foreground">
					{ info.error?.message ||
						__(
							'The tax information could not be loaded.',
							'radius-hotel-booking-residencetata'
						) }
				</p>
				<Button
					type="button"
					variant="outline"
					onClick={ () => info.refetch() }
				>
					{ __( 'Try again', 'radius-hotel-booking-residencetata' ) }
				</Button>
			</Panel>
		);
	}

	const data = info.data;
	const side = ( row ) =>
		'employer' === row.side
			? __( 'Hotel', 'radius-hotel-booking-residencetata' )
			: __( 'Employee', 'radius-hotel-booking-residencetata' );

	return (
		<div className="space-y-5">
			<p className="m-0 text-sm text-muted-foreground">
				{ __(
					'The rates every pay run uses today. Read only: change them in Settings → Payroll (social contributions) and Settings → Côte d’Ivoire payroll (taxes). A past payslip keeps the figures it was worked out with.',
					'radius-hotel-booking-residencetata'
				) }
			</p>

			<Panel
				title={ __(
					'Social contributions (CNPS)',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'A percentage of pay, up to a monthly ceiling. The employee’s share is taken from pay; the hotel’s is added to its cost.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<DataTable
					columns={ [
						{
							id: 'label',
							header: __(
								'Contribution',
								'radius-hotel-booking-residencetata'
							),
							mobile: 'title',
							cell: ( row ) => (
								<span className="font-semibold text-heading">
									{ row.label }
								</span>
							),
						},
						{
							id: 'side',
							header: __(
								'Paid by',
								'radius-hotel-booking-residencetata'
							),
							cell: side,
						},
						{
							id: 'authority',
							header: __(
								'Paid to',
								'radius-hotel-booking-residencetata'
							),
							cell: ( row ) => row.authority || '—',
						},
						{
							id: 'rate',
							header: __(
								'Rate',
								'radius-hotel-booking-residencetata'
							),
							cell: ( row ) => percent( row.rate ),
						},
						{
							id: 'cap',
							header: __(
								'Monthly ceiling',
								'radius-hotel-booking-residencetata'
							),
							cell: ( row ) =>
								null === row.cap ? (
									__(
										'No ceiling',
										'radius-hotel-booking-residencetata'
									)
								) : (
									<Money value={ row.cap } />
								),
						},
					] }
					rows={ data.contributions }
					rowKey={ ( row ) => `${ row.label }|${ row.side }` }
					total={ data.contributions.length }
					caption={ __(
						'Social contributions',
						'radius-hotel-booking-residencetata'
					) }
					empty={
						<p className="m-0 py-6 text-center text-sm text-muted-foreground">
							{ __(
								'No social contributions set.',
								'radius-hotel-booking-residencetata'
							) }
						</p>
					}
				/>
			</Panel>

			<Panel
				title={ __(
					'Salary tax (IS)',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'Paid to the DGI by the employee.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<Figure
					label={ __(
						'Taxable share of pay',
						'radius-hotel-booking-residencetata'
					) }
					hint={ __(
						'Pay less the employee’s contributions, times this share. IS, CN and IGR are worked out on it.',
						'radius-hotel-booking-residencetata'
					) }
				>
					{ percent( data.taxable_ratio ) }
				</Figure>
				<Figure
					label={ __(
						'Salary tax rate',
						'radius-hotel-booking-residencetata'
					) }
					hint={ __(
						'Of the taxable pay.',
						'radius-hotel-booking-residencetata'
					) }
				>
					{ percent( data.is_rate ) }
				</Figure>
			</Panel>

			<Panel
				title={ __(
					'National contribution (CN)',
					'radius-hotel-booking-residencetata'
				) }
				description={ modeLabel( data.cn.mode ) }
			>
				<Scale
					rows={ data.cn.brackets }
					caption={ __(
						'National contribution scale',
						'radius-hotel-booking-residencetata'
					) }
				/>
			</Panel>

			<Panel
				title={ __(
					'General income tax (IGR)',
					'radius-hotel-booking-residencetata'
				) }
				description={ modeLabel( data.igr.mode ) }
			>
				<Figure
					label={ __(
						'IGR base',
						'radius-hotel-booking-residencetata'
					) }
					hint={ __(
						'Taxable pay less IS and CN, times this share.',
						'radius-hotel-booking-residencetata'
					) }
				>
					{ percent( data.igr.ratio ) }
				</Figure>
				<Figure
					label={ __(
						'Family quotient',
						'radius-hotel-booking-residencetata'
					) }
					hint={ __(
						'The base divided by the employee’s family parts before the scale.',
						'radius-hotel-booking-residencetata'
					) }
				>
					{ data.igr.family_quotient
						? __( 'On', 'radius-hotel-booking-residencetata' )
						: __( 'Off', 'radius-hotel-booking-residencetata' ) }
				</Figure>
				<div className="pt-3">
					<Scale
						rows={ data.igr.brackets }
						caption={ __(
							'General income tax scale',
							'radius-hotel-booking-residencetata'
						) }
					/>
				</div>
			</Panel>
		</div>
	);
}
