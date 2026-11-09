<?php
/**
 * The legacy payroll tax settings → Côte d'Ivoire payroll (R1).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\SettingsService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Payroll\StatutorySettings;

defined( 'ABSPATH' ) || exit;

/**
 * One record (`site`): the old system's payroll rates → Pro's Settings →
 * Payroll → Social contributions, as the CNPS rows (retirement, family
 * benefits, work accident; StatutorySettings). The old system fixed every
 * rate in its code except the work accident / disability rate
 * `hbfwc_payroll_injury_rate` (percent), held here to the 2–5 % the law
 * allows (a value outside is reported and brought within; none saved: 2 %).
 * The DGI tax figures start from the same old values as Settings → Côte
 * d'Ivoire payroll defaults. A contributions list already filled here is
 * linked, not overwritten — unless it is still exactly what the add-on's
 * one-time fill wrote on activation (before this import), which is replaced.
 */
final class PayrollSettingsImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'payroll_settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Payroll tax settings', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$context->report->found( $this->key(), 1 );
		$legacy = $context->source->value( 'SELECT option_value FROM {options} WHERE option_name = %s', array( 'hbfwc_payroll_injury_rate' ) );
		$rate   = null === $legacy || '' === trim( (string) $legacy ) ? (float) StatutorySettings::DEFAULT_INJURY_RATE : (float) $legacy;
		$kept   = min( 5.0, max( 2.0, $rate ) );
		if ( $kept !== $rate ) {
			$context->report->issue(
				$this->key(),
				'site',
				sprintf(
					/* translators: 1: the old rate, 2: the rate imported. */
					__( 'The old work accident rate %1$s %% is outside 2 to 5 %%; %2$s %% was used. Check it under Settings → Payroll → Social contributions.', 'radius-hotel-booking-residencetata' ),
					$rate,
					$kept
				)
			);
		}
		$rows  = StatutorySettings::contributions( $kept );
		$write = static function () use ( $rows ) {
			( new SettingsService() )->updateSection( 'payroll', array( 'contributions' => $rows ) );
		};

		$this->import(
			$context,
			'site',
			array( 'contributions' => $rows ),
			// Contributions set up here already (someone did, or a run before): keep them.
			static fn() => StatutorySettings::hasSavedContributions() && ! StatutorySettings::isUntouchedSeed() ? 1 : 0,
			static function () use ( $write ) {
				$write();
				return 1;
			},
			// A run before: fill only a list never saved (an empty one may be the hotel's choice) or never edited.
			static function () use ( $write ) {
				if ( null === StatutorySettings::savedContributions() || StatutorySettings::isUntouchedSeed() ) {
					$write();
				}
			},
			__( 'Social contributions are already set up here; they were kept, not replaced.', 'radius-hotel-booking-residencetata' )
		);
	}
}
