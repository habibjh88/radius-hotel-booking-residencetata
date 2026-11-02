<?php
/**
 * Floors from the legacy rooms' floor values (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\Inventory\FloorService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * The distinct `floor` values of `hbfwc_restata_rooms` → floors (M06
 * migration notes). The legacy system stores a floor as a number: `0`
 * becomes *Ground floor*, `n` becomes *Floor n*, any other text is kept as
 * it is (names can be edited afterwards). A floor that already exists with
 * that name is adopted. Floors are created in number order, so the new
 * floor list sorts the same way.
 */
final class FloorImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'floor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Floors', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array();
	}

	/**
	 * The new floor name of a legacy floor value.
	 *
	 * @param string $value Legacy value.
	 * @return string
	 */
	public static function name( string $value ): string {
		if ( '0' === $value ) {
			return __( 'Ground floor', 'radius-hotel-booking-residencetata' );
		}
		if ( ctype_digit( $value ) ) {
			/* translators: %d: floor number. */
			return sprintf( __( 'Floor %d', 'radius-hotel-booking-residencetata' ), (int) $value );
		}
		return $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$service = new FloorService();
		$values  = array_map(
			static fn( $row ) => trim( (string) $row['floor'] ),
			$context->source->rows( "SELECT DISTINCT floor FROM {hbfwc_restata_rooms} WHERE TRIM( floor ) <> ''" )
		);
		$values = array_values( array_unique( $values ) );
		usort( $values, static fn( $a, $b ) => strnatcmp( $a, $b ) );
		$context->report->found( $this->key(), count( $values ) );

		foreach ( $values as $value ) {
			$data = array( 'name' => self::name( $value ) );
			$this->import(
				$context,
				$value,
				$data,
				function () use ( $service, $data ) {
					foreach ( $service->all() as $floor ) {
						if ( mb_strtolower( $floor['name'] ) === mb_strtolower( $data['name'] ) ) {
							return (int) $floor['id'];
						}
					}
					return 0;
				},
				fn() => (int) $service->create( $data )->id,
				null,
				sprintf(
					/* translators: %s: floor name. */
					__( 'A floor called %s already exists; it was linked.', 'radius-hotel-booking-residencetata' ),
					$data['name']
				)
			);
		}
	}
}
