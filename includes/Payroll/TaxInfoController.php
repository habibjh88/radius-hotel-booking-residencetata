<?php
/**
 * Payroll → Tax info API (read only).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingTata\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET tata/payroll/tax-info` (`page.payroll`): the figures the Ivorian
 * calculator uses today — Pro's Social contributions (CNPS) and Settings →
 * Côte d'Ivoire payroll (IS, CN, IGR) — for the read-only Tax info tab, as
 * the old dashboard's Payroll → Tax Info showed them. Anyone who may open
 * Payroll may read them; changing them stays in Settings
 * (`payroll.tax_settings`). Registered only when Pro's GuardsRequests exists
 * (IvorianPayroll::register()).
 */
class TaxInfoController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'tata/payroll/tax-info', array( self::class, 'show' ) );
	}

	/**
	 * GET tata/payroll/tax-info. Rates in percent, caps and brackets in
	 * monthly francs (null: no cap / no upper limit).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			static fn() => ApiResponse::success( self::data() )->send()
		);
	}

	/**
	 * The tab's data.
	 *
	 * @return array
	 */
	public static function data(): array {
		$taxes = StatutorySettings::taxes();
		$scale = static fn( $rows ) => array_map(
			static fn( $row ) => array(
				'up_to' => '' === (string) ( $row['upTo'] ?? '' ) ? null : (float) $row['upTo'],
				'rate'  => (float) ( $row['rate'] ?? 0 ),
			),
			array_values( (array) $rows )
		);

		return array(
			'contributions' => array_map(
				static fn( $row ) => array(
					'label'     => (string) ( $row['label'] ?? $row['code'] ?? '' ),
					'authority' => (string) ( $row['authority'] ?? '' ),
					'side'      => 'employer' === ( $row['side'] ?? '' ) ? 'employer' : 'employee',
					'rate'      => (float) ( $row['rate'] ?? 0 ),
					'cap'       => (float) ( $row['cap'] ?? 0 ) > 0 ? (float) $row['cap'] : null,
					// A fixed amount per month (e.g. CMU) instead of a rate; null for a rate.
					'amount'    => 'amount' === ( $row['type'] ?? '' ) ? (float) ( $row['amount'] ?? 0 ) : null,
				),
				StatutorySettings::contributionRows()
			),
			'taxable_ratio' => (float) $taxes['taxableRatio'],
			'is_rate'       => (float) $taxes['isRate'],
			'cn'            => array(
				'mode'     => 'progressive' === $taxes['cnMode'] ? 'progressive' : 'flat',
				'brackets' => $scale( $taxes['cnBrackets'] ),
			),
			'igr'           => array(
				'ratio'           => (float) $taxes['igrRatio'],
				'mode'            => 'progressive' === $taxes['igrMode'] ? 'progressive' : 'flat',
				'family_quotient' => ! empty( $taxes['igrFamilyQuotient'] ),
				'brackets'        => $scale( $taxes['igrBrackets'] ),
			),
		);
	}
}
