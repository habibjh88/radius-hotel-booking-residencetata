/**
 * Settings → Côte d'Ivoire payroll: the DGI tax figures the Ivorian
 * calculator uses — the taxable share of pay, the salary tax (IS), the
 * national contribution (CN) scale and the general income tax (IGR) share,
 * scale and family quotient. They start from the old system's values and
 * can be changed when the law does; each pay run line keeps the figures it
 * used. The CNPS contributions are Pro's Settings → Payroll → Social
 * contributions. The scale tables are Pro's (`window.rtbpPro.ui.BracketsTable`).
 */
import { __ } from '@wordpress/i18n';
import {
	Field,
	NumberInput,
	SegmentedControl,
	SettingsSection,
	ToggleRow,
} from '@rtbp/ui';

/**
 * How a scale applies.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    flat|progressive.
 * @param {Function} props.onChange Called with the mode.
 * @return {JSX.Element} Control.
 */
function ModeField( { value, onChange } ) {
	return (
		<Field
			label={ __(
				'How the scale applies',
				'radius-hotel-booking-residencetata'
			) }
			description={
				'progressive' === value
					? __(
							'Each slice of the base is taxed at its bracket’s rate.',
							'radius-hotel-booking-residencetata'
					  )
					: __(
							'The rate of the bracket the base falls in applies to all of it (as the old system did).',
							'radius-hotel-booking-residencetata'
					  )
			}
		>
			<div>
				<SegmentedControl
					label={ __(
						'How the scale applies',
						'radius-hotel-booking-residencetata'
					) }
					value={ value || 'flat' }
					onChange={ onChange }
					options={ [
						{
							value: 'flat',
							label: __(
								'One rate on the whole base',
								'radius-hotel-booking-residencetata'
							),
						},
						{
							value: 'progressive',
							label: __(
								'Each slice at its rate',
								'radius-hotel-booking-residencetata'
							),
						},
					] }
				/>
			</div>
		</Field>
	);
}

/**
 * A scale's brackets (Pro's table; a note when Pro is too old to share it).
 *
 * @param {Object}   props          Props.
 * @param {Object[]} props.value    Brackets.
 * @param {Function} props.onChange Called with the brackets.
 * @param {string}   props.error    Server message.
 * @return {JSX.Element} Table.
 */
function Scale( { value, onChange, error } ) {
	const BracketsTable = window.rtbpPro?.ui?.BracketsTable;
	if ( ! BracketsTable ) {
		return (
			<p className="m-0 text-sm text-muted-foreground">
				{ __(
					'Update Radius Hotel Booking Pro to edit this scale.',
					'radius-hotel-booking-residencetata'
				) }
			</p>
		);
	}
	return (
		<BracketsTable value={ value } onChange={ onChange } error={ error } />
	);
}

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
	const percent = ( key, label, help ) => (
		<Field label={ label } description={ help } error={ errors[ key ] }>
			<NumberInput
				value={ value[ key ] }
				onChange={ setField( key ) }
				unit="%"
				limits={ schema[ key ] }
			/>
		</Field>
	);
	const contributionsUrl = '#/settings?section=payroll';

	return (
		<>
			<SettingsSection
				title={ __(
					'Taxable pay',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'IS, CN and IGR are worked out on this share of monthly pay, after the employee’s social contributions. The CNPS contributions (retirement, family benefits, work accident) are set in Settings → Payroll → Social contributions.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<div className="grid gap-4 sm:grid-cols-2">
					{ percent(
						'taxableRatio',
						__(
							'Taxable share of pay',
							'radius-hotel-booking-residencetata'
						),
						__(
							'80 %: pay less the employee’s contributions, times 80 %.',
							'radius-hotel-booking-residencetata'
						)
					) }
					{ percent(
						'isRate',
						__(
							'Salary tax (IS)',
							'radius-hotel-booking-residencetata'
						),
						__(
							'Of the taxable pay. Paid to the DGI.',
							'radius-hotel-booking-residencetata'
						)
					) }
				</div>
				<p className="m-0 text-sm">
					<a
						href={ contributionsUrl }
						className="font-semibold text-primary"
					>
						{ __(
							'Edit the CNPS contributions →',
							'radius-hotel-booking-residencetata'
						) }
					</a>
				</p>
			</SettingsSection>

			<SettingsSection
				title={ __(
					'National contribution (CN)',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'By monthly brackets of the taxable pay.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<ModeField
					value={ value.cnMode }
					onChange={ setField( 'cnMode' ) }
				/>
				<Scale
					value={ value.cnBrackets }
					onChange={ setField( 'cnBrackets' ) }
					error={ errors.cnBrackets }
				/>
			</SettingsSection>

			<SettingsSection
				title={ __(
					'General income tax (IGR)',
					'radius-hotel-booking-residencetata'
				) }
				description={ __(
					'On a share of what is left of the taxable pay after IS and CN, by monthly brackets.',
					'radius-hotel-booking-residencetata'
				) }
			>
				<div className="grid gap-4 sm:grid-cols-2">
					{ percent(
						'igrRatio',
						__( 'IGR base', 'radius-hotel-booking-residencetata' ),
						__(
							'85 %: taxable pay less IS and CN, times 85 %.',
							'radius-hotel-booking-residencetata'
						)
					) }
					<ModeField
						value={ value.igrMode }
						onChange={ setField( 'igrMode' ) }
					/>
				</div>
				<ToggleRow
					label={ __(
						'Family quotient',
						'radius-hotel-booking-residencetata'
					) }
					description={ __(
						'Divide the base by the employee’s family parts before the scale, then multiply back. The old system did not.',
						'radius-hotel-booking-residencetata'
					) }
					checked={ Boolean( value.igrFamilyQuotient ) }
					onChange={ setField( 'igrFamilyQuotient' ) }
					error={ errors.igrFamilyQuotient }
				/>
				<Scale
					value={ value.igrBrackets }
					onChange={ setField( 'igrBrackets' ) }
					error={ errors.igrBrackets }
				/>
			</SettingsSection>
		</>
	);
}
