<?php
/**
 * The legacy payroll tax settings → Côte d'Ivoire payroll (R1).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\SettingsService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll;

defined( 'ABSPATH' ) || exit;

/**
 * One record (`site`): the legacy *Tax Info* work accident / disability
 * insurance rate `hbfwc_payroll_injury_rate` (percent) → Settings →
 * Côte d'Ivoire payroll (`tata_payroll.workInjuryRate`), held to the 2–5 %
 * the law allows (a value outside is reported and brought within). The
 * other legacy rates were fixed in code, as they are here (StatutoryTable).
 * Through `SettingsService` (validated, logged). A rate already saved here is
 * linked, not overwritten.
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
		$legacy = $context->source->value( 'SELECT option_value FROM {options} WHERE option_name = %s', array( 'hbfwc_payroll_injury_rate' ) );
		if ( null === $legacy || '' === trim( (string) $legacy ) ) {
			return;
		}
		$context->report->found( $this->key(), 1 );

		$rate = (float) $legacy;
		$kept = min( 5.0, max( 2.0, $rate ) );
		if ( $kept !== $rate ) {
			$context->report->issue(
				$this->key(),
				'site',
				sprintf(
					/* translators: 1: the old rate, 2: the rate imported. */
					__( 'The old work accident rate %1$s %% is outside 2 to 5 %%; %2$s %% was used. Check it under Settings → Côte d\'Ivoire payroll.', 'radius-hotel-booking-residencetata' ),
					$rate,
					$kept
				)
			);
		}

		$write = static function () use ( $kept ) {
			( new SettingsService() )->updateSection( IvorianPayroll::SECTION, array( 'workInjuryRate' => $kept ) );
		};

		$this->import(
			$context,
			'site',
			array( 'workInjuryRate' => $kept ),
			// A rate saved here already (someone set it): keep it.
			static fn() => array_key_exists( 'workInjuryRate', (array) get_option( 'rtbp_' . IvorianPayroll::SECTION . '_settings', array() ) ) ? 1 : 0,
			static function () use ( $write ) {
				$write();
				return 1;
			},
			static function () use ( $write ) {
				$write();
			},
			__( 'A work accident rate is already set here; it was kept, not replaced.', 'radius-hotel-booking-residencetata' )
		);
	}
}
