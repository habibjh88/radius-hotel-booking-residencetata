/**
 * Settings → Côte d'Ivoire payroll (M15 T2): the hotel's work accident /
 * disability insurance rate (15.15), used by the Ivorian calculator this
 * add-on plugs into Pro's payroll. The other statutory rates are the law's,
 * built in, and listed read-only below it (as the legacy Tax Info tab); the
 * vacation quota is Pro's Settings → Payroll.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Field, Input, SettingsSection } from '@rtbp/ui';

const percent = ( value ) =>
	sprintf(
		/* translators: %s: a rate, e.g. "7.70". */
		__( '%s %%', 'radius-hotel-booking-residencetata' ),
		new Intl.NumberFormat( undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} ).format( value )
	);

/**
 * A built-in rate: one figure, or the range of its scale.
 *
 * @param {Object} row `{ rate, min, max }` in percent.
 * @return {string} Text.
 */
const rateText = ( row ) =>
	null !== row.rate
		? percent( row.rate )
		: sprintf(
				/* translators: 1: lowest rate, 2: highest rate, e.g. "0.00 %" and "10.00 %". */
				__( '%1$s to %2$s', 'radius-hotel-booking-residencetata' ),
				percent( row.min ),
				percent( row.max )
		  );

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @param {Object}   props.schema   Key => schema.
 * @return {JSX.Element} Tab.
 */
export default function IvorianPayrollSection( {
	value,
	setField,
	errors,
	schema,
} ) {
	const rate = schema.workInjuryRate || {};
	const rates = window.rtbp_tata?.statutory_rates || [];
	return (
		<>
			<SettingsSection
				title={ __(
					'Statutory contributions',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'CNPS and DGI rates and caps follow the law and are built in. Only the work accident rate is your own: CNPS sets it for your business.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<Field
					label={ __(
						'Work accident / disability insurance rate',
						'radius-hotel-booking-residencetata'
					) }
					description={ __(
						'Paid by the hotel on each salary, up to 70,000 CFA a month. Between 2 and 5 %.',
						'radius-hotel-booking-residencetata'
					) }
					error={ errors.workInjuryRate }
				>
					<div className="flex items-center gap-2">
						<Input
							type="number"
							inputMode="decimal"
							step="0.01"
							min={ rate.min }
							max={ rate.max }
							value={ value.workInjuryRate ?? '' }
							onChange={ ( event ) => {
								const raw = event.target.value;
								setField( 'workInjuryRate' )(
									raw === '' || Number.isNaN( Number( raw ) )
										? raw
										: Number( raw )
								);
							} }
							className="w-28"
						/>
						<span className="text-sm text-muted-foreground">%</span>
					</div>
				</Field>
			</SettingsSection>

			{ rates.length ? (
				<SettingsSection
					title={ __(
						'Statutory rates (built in)',
						'radius-hotel-booking-residencetata'
					) }
					description={ __(
						'Set by law, the same for every hotel. Shown for reference; they cannot be changed here.',
						'radius-hotel-booking-residencetata'
					) }
				>
					<dl className="divide-y divide-border rounded-md border border-border">
						{ rates.map( ( row ) => (
							<div
								key={ row.label }
								className="flex items-center justify-between gap-4 px-3 py-2 text-sm"
							>
								<dt className="min-w-0">
									{ row.label }
									<span className="block text-xs text-muted-foreground">
										{ 'employer' === row.side
											? __(
													'Paid by the hotel',
													'radius-hotel-booking-residencetata'
											  )
											: __(
													'Taken from the salary',
													'radius-hotel-booking-residencetata'
											  ) }
									</span>
								</dt>
								<dd className="shrink-0 font-medium tabular-nums">
									{ rateText( row ) }
								</dd>
							</div>
						) ) }
					</dl>
				</SettingsSection>
			) : null }
		</>
	);
}
