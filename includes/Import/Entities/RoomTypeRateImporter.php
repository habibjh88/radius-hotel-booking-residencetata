<?php
/**
 * Room-type rates from the legacy product prices (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Repositories\RoomTypeRateRepository;
use RadiusTheme\RadiusHotelBooking\Services\Pricing\RoomTypeRateService;
use RadiusTheme\RadiusHotelBooking\Support\Money;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy prices per room type × rate plan → `room_type_rates` (M07 migration
 * notes), computed the way the legacy product did:
 *
 * - the **default rate** (option `hbfwc_default_rate_id`) costs the
 *   product's regular price, with its sale price when one is set;
 * - each `_hbfwc_rate_plans` entry: `fixed` = its value; `increase` /
 *   `decrease` = the regular price ± a percentage or an amount; then the
 *   optional sale (`fixed`, or a `decrease` of that price); `status no`
 *   → the rate is kept but switched off;
 * - `_min_stays` / `_min_stay` → the minimum units of multi-unit plans.
 *
 * Prices are rounded to the currency. A rate the room type already prices
 * (switched on, with a price) is linked, not changed. Rows are written
 * through `RoomTypeRateService::save()` with the room type's whole grid, so
 * its other rates and their order stay as they are.
 */
final class RoomTypeRateImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'room_type_rate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Room-type prices', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'room_type', 'rate_plan' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$source   = $context->source;
		$service  = new RoomTypeRateService();
		$repo     = new RoomTypeRateRepository();
		$default  = (int) $source->value( "SELECT option_value FROM {options} WHERE option_name = 'hbfwc_default_rate_id'" );
		$products = $source->rows(
			"SELECT p.ID FROM {posts} p
			JOIN {term_relationships} tr ON tr.object_id = p.ID
			JOIN {term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			JOIN {terms} t ON t.term_id = tt.term_id AND t.slug = 'accommodation'
			WHERE p.post_type = 'product' AND p.post_status <> 'trash' ORDER BY p.ID"
		);

		foreach ( $products as $product ) {
			$product_id = (int) $product['ID'];
			$type_id    = $context->map->newId( 'room_type', $product_id );
			$meta       = array_column(
				$source->rows( "SELECT meta_key, meta_value FROM {postmeta} WHERE post_id = %d AND meta_key IN ( '_regular_price', '_sale_price', '_hbfwc_rate_plans', '_min_stays', '_min_stay' )", array( $product_id ) ),
				'meta_value',
				'meta_key'
			);
			$rates = $this->rates( $context, $product_id, $meta, $default );
			$context->report->found( $this->key(), count( $rates ) );

			foreach ( $rates as $term_id => $rate ) {
				$legacy_id = $product_id . ':' . $term_id;
				$plan_id   = $context->map->newId( 'rate_plan', $term_id );
				if ( ! $type_id || ! $plan_id ) {
					$context->report->count( $this->key(), 'failed' );
					$context->report->issue( $this->key(), $legacy_id, __( 'Its room type or rate plan was not imported.', 'radius-hotel-booking-residencetata' ), 'error' );
					continue;
				}
				$data = $rate + array(
					'room_type_id' => $type_id,
					'rate_plan_id' => $plan_id,
				);

				$this->import(
					$context,
					$legacy_id,
					$data,
					function () use ( $repo, $type_id, $plan_id ) {
						if ( $type_id < 0 || $plan_id < 0 ) {
							return 0; // Planned in a dry run: nothing priced yet.
						}
						$row = $repo->forRoomType( $type_id )[ $plan_id ] ?? null;
						return $row && $row->enabled && (float) $row->price > 0 ? (int) $row->id : 0;
					},
					fn() => $this->save( $service, $repo, $data ),
					function () use ( $service, $repo, $data ) {
						$this->save( $service, $repo, $data );
					},
					__( 'This room type already has a price for this rate plan; it was linked, not changed.', 'radius-hotel-booking-residencetata' )
				);
			}
		}
	}

	/**
	 * Write one rate into the room type's grid; return the rate row id.
	 *
	 * @param RoomTypeRateService    $service Service.
	 * @param RoomTypeRateRepository $repo    Repository.
	 * @param array                  $data    Rate.
	 * @return int
	 */
	private function save( RoomTypeRateService $service, RoomTypeRateRepository $repo, array $data ): int {
		$rows = array();
		foreach ( $service->grid( $data['room_type_id'] ) as $row ) {
			$plan_id = (int) $row['rate_plan']['id'];
			$mine    = $plan_id === $data['rate_plan_id'];
			$rows[]  = array(
				'rate_plan_id' => $plan_id,
				'enabled'      => $mine ? $data['enabled'] : $row['enabled'],
				'price'        => $mine ? $data['price'] : $row['price'],
				'sale_price'   => $mine ? $data['sale_price'] : $row['sale_price'],
				'min_units'    => $mine ? $data['min_units'] : $row['min_units'],
				'max_units'    => $mine ? null : $row['max_units'],
			);
		}
		$service->save( $data['room_type_id'], $rows );
		$row = $repo->forRoomType( $data['room_type_id'] )[ $data['rate_plan_id'] ] ?? null;
		return $row ? (int) $row->id : 0;
	}

	/**
	 * The legacy product's rates, term id => `{ enabled, price, sale_price, min_units }`.
	 *
	 * @param ImportContext $context    The run.
	 * @param int           $product_id Product id.
	 * @param array         $meta       Product meta.
	 * @param int           $default    The default rate's term id.
	 * @return array<int, array>
	 */
	private function rates( ImportContext $context, int $product_id, array $meta, int $default ): array {
		$regular = (float) ( $meta['_regular_price'] ?? 0 );
		$min     = max( 1, (int) ( $meta['_min_stays'] ?? $meta['_min_stay'] ?? 1 ) );
		$out     = array();

		if ( $default ) {
			$sale            = isset( $meta['_sale_price'] ) && '' !== $meta['_sale_price'] ? (float) $meta['_sale_price'] : null;
			$out[ $default ] = $this->rate( $context, $product_id . ':' . $default, true, $regular, $sale, $min );
		}

		$custom = maybe_unserialize( (string) ( $meta['_hbfwc_rate_plans'] ?? '' ) );
		foreach ( is_array( $custom ) ? $custom : array() as $key => $rate ) {
			$term_id = absint( str_replace( 'rate_', '', (string) $key ) );
			if ( ! $term_id || ! is_array( $rate ) ) {
				continue;
			}
			$value  = (float) ( $rate['pricing_value'] ?? 0 );
			$amount = 'percent' === ( $rate['pricing_unit'] ?? '' ) ? $regular * $value / 100 : $value;
			switch ( $rate['pricing_method'] ?? 'fixed' ) {
				case 'increase':
					$price = $regular + $amount;
					break;
				case 'decrease':
					$price = $regular - $amount;
					break;
				default:
					$price = $value;
			}

			$sale = null;
			if ( 'none' !== ( $rate['pricing_sale_method'] ?? 'none' ) ) {
				$sale_value = (float) ( $rate['pricing_sale_value'] ?? 0 );
				if ( 'decrease' === $rate['pricing_sale_method'] ) {
					$sale = $price - ( 'percent' === ( $rate['pricing_sale_unit'] ?? '' ) ? $price * $sale_value / 100 : $sale_value );
				} else {
					$sale = $sale_value;
				}
			}
			$out[ $term_id ] = $this->rate( $context, $product_id . ':' . $term_id, 'no' !== ( $rate['status'] ?? 'yes' ), $price, $sale, $min );
		}
		return $out;
	}

	/**
	 * One rate's values, rounded, with the sale kept only when lower.
	 *
	 * @param ImportContext $context   The run.
	 * @param string        $legacy_id Legacy id, for the report.
	 * @param bool          $enabled   Switched on.
	 * @param float         $price     Price.
	 * @param float|null    $sale      Sale price.
	 * @param int           $min       Minimum units (multi-unit plans; the service ignores it for others).
	 * @return array
	 */
	private function rate( ImportContext $context, string $legacy_id, bool $enabled, float $price, ?float $sale, int $min ): array {
		$price = max( 0.0, (float) Money::round( $price ) );
		if ( null !== $sale ) {
			$sale = max( 0.0, (float) Money::round( $sale ) );
			if ( $sale >= $price ) {
				$context->report->issue( $this->key(), $legacy_id, __( 'The old sale price was not lower than the price; the sale was dropped.', 'radius-hotel-booking-residencetata' ) );
				$sale = null;
			}
		}
		return array(
			'enabled'    => $enabled,
			'price'      => $price,
			'sale_price' => $sale,
			'min_units'  => $min,
		);
	}
}
