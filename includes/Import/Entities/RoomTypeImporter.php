<?php
/**
 * Room types from the legacy accommodation products (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Services\Inventory\RoomTypeService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy `accommodation` products → room types (M06 migration notes): title,
 * content and excerpt, `_no_of_beds` → bed info, `_room_size` ("30 sqm") →
 * m², `_max_adults` / `_max_children`, the `amenities` terms, `menu_order`;
 * published → active, any other status but trash → inactive. Photos
 * (`_thumbnail_id`, `_product_image_gallery`) are kept only when they are
 * images in this site's media library — true on production, where both
 * systems share the site; otherwise each type gets a warning.
 *
 * A room type that already exists with the same name or slug is adopted
 * (mapped, not overwritten) and reported.
 */
final class RoomTypeImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'room_type';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Room types', 'radius-hotel-booking-residencetata' );
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
		global $wpdb;
		$source  = $context->source;
		$service = new RoomTypeService();
		$rows    = $source->rows(
			"SELECT p.ID, p.post_title, p.post_name, p.post_content, p.post_excerpt, p.post_status, p.menu_order
			FROM {posts} p
			JOIN {term_relationships} tr ON tr.object_id = p.ID
			JOIN {term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			JOIN {terms} t ON t.term_id = tt.term_id AND t.slug = 'accommodation'
			WHERE p.post_type = 'product' AND p.post_status <> 'trash'
			ORDER BY p.menu_order, p.ID"
		);
		$context->report->found( $this->key(), count( $rows ) );
		$same_site = $source->prefix() === $wpdb->prefix;

		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$meta = $this->meta( $context, $id );
			$data = $this->data( $context, $row, $meta, $same_site );

			$this->import(
				$context,
				$id,
				$data,
				fn() => $this->existing( $service, $data ),
				fn() => (int) $service->create( $data )->id,
				function ( int $new_id ) use ( $service, $data ) {
					$service->update( $new_id, $data );
				},
				sprintf(
					/* translators: %s: room type name. */
					__( 'A room type called %s already exists; it was linked, not changed.', 'radius-hotel-booking-residencetata' ),
					$data['name']
				)
			);
		}
	}

	/**
	 * The product's meta we use.
	 *
	 * @param ImportContext $context The run.
	 * @param int           $id      Product id.
	 * @return array<string, string>
	 */
	private function meta( ImportContext $context, int $id ): array {
		$keys = array( '_no_of_beds', '_room_size', '_max_adults', '_max_children', '_thumbnail_id', '_product_image_gallery' );
		$rows = $context->source->rows(
			'SELECT meta_key, meta_value FROM {postmeta} WHERE post_id = %d AND meta_key IN ( ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' )',
			array_merge( array( $id ), $keys )
		);
		return array_column( $rows, 'meta_value', 'meta_key' );
	}

	/**
	 * The room type fields from the legacy product.
	 *
	 * @param ImportContext $context   The run.
	 * @param array         $row       Product row.
	 * @param array         $meta      Product meta.
	 * @param bool          $same_site Whether the legacy media is this site's.
	 * @return array
	 */
	private function data( ImportContext $context, array $row, array $meta, bool $same_site ): array {
		$id   = (int) $row['ID'];
		$data = array(
			'name'              => self::text( $row['post_title'] ),
			'description'       => (string) $row['post_content'],
			'short_description' => self::text( wp_strip_all_tags( (string) $row['post_excerpt'] ) ),
			'bed_info'          => self::text( $meta['_no_of_beds'] ?? '' ),
			'amenities'         => $this->amenities( $context, $id ),
			'sort_order'        => max( 0, (int) $row['menu_order'] ),
			'is_active'         => 'publish' === $row['post_status'],
		);

		$size = trim( (string) ( $meta['_room_size'] ?? '' ) );
		if ( '' !== $size ) {
			if ( preg_match( '/(\d+(?:[.,]\d+)?)/', $size, $m ) ) {
				$data['size_m2'] = (float) str_replace( ',', '.', $m[1] );
			} else {
				$context->report->issue( $this->key(), $id, sprintf( /* translators: %s: legacy room size text. */ __( 'The room size "%s" is not a number; left empty.', 'radius-hotel-booking-residencetata' ), $size ) );
			}
		}
		foreach ( array(
			'max_adults'   => '_max_adults',
			'max_children' => '_max_children',
		) as $field => $key ) {
			if ( isset( $meta[ $key ] ) && '' !== $meta[ $key ] ) {
				$data[ $field ] = (int) $meta[ $key ];
			}
		}

		$photos = array_values( array_unique( array_filter( array_map( 'absint', array_merge( array( $meta['_thumbnail_id'] ?? 0 ), explode( ',', (string) ( $meta['_product_image_gallery'] ?? '' ) ) ) ) ) ) );
		if ( $photos ) {
			$usable = $same_site ? array_values( array_filter( $photos, 'wp_attachment_is_image' ) ) : array();
			if ( $usable ) {
				$data['gallery']           = $usable;
				$data['featured_image_id'] = $usable[0];
			}
			if ( count( $usable ) < count( $photos ) ) {
				$context->report->issue(
					$this->key(),
					$id,
					sprintf(
						/* translators: %d: number of photos. */
						_n( '%d photo is not in this site\'s media library; add it by hand.', '%d photos are not in this site\'s media library; add them by hand.', count( $photos ) - count( $usable ), 'radius-hotel-booking-residencetata' ),
						count( $photos ) - count( $usable )
					)
				);
			}
		}
		return $data;
	}

	/**
	 * The product's amenity names.
	 *
	 * @param ImportContext $context The run.
	 * @param int           $id      Product id.
	 * @return string[]
	 */
	private function amenities( ImportContext $context, int $id ): array {
		$rows = $context->source->rows(
			"SELECT t.name FROM {term_relationships} tr
			JOIN {term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'amenities'
			JOIN {terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id = %d ORDER BY t.name",
			array( $id )
		);
		return array_values( array_unique( array_filter( array_map( static fn( $row ) => self::text( $row['name'] ), $rows ) ) ) );
	}

	/**
	 * An existing room type with the same name or slug, or 0.
	 *
	 * @param RoomTypeService $service Service.
	 * @param array           $data    Fields.
	 * @return int
	 */
	private function existing( RoomTypeService $service, array $data ): int {
		$name = mb_strtolower( $data['name'] );
		$slug = sanitize_title( $data['name'] );
		foreach ( $service->all() as $type ) {
			$type = (object) ( is_object( $type ) && method_exists( $type, 'toArray' ) ? $type->toArray() : $type );
			if ( mb_strtolower( (string) $type->name ) === $name || (string) $type->slug === $slug ) {
				return (int) $type->id;
			}
		}
		return 0;
	}
}
