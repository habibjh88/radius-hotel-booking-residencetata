<?php
/**
 * Rooms from the legacy room list (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Models\Room;
use RadiusTheme\RadiusHotelBooking\Repositories\RoomRepository;
use RadiusTheme\RadiusHotelBooking\Services\Inventory\RoomService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_restata_rooms` → rooms (M06 migration notes): `room_id` (the
 * product) → the imported room type, `floor` → the imported floor, `number`,
 * `status` (`closed` → out of service, `maintenance` kept, anything else
 * available). The PRO add-on's `_hbfwc_room_numbers` product meta, the
 * second legacy room list, is compared and every difference reported
 * (importer rule 6).
 *
 * A room whose number already exists is adopted when it belongs to the same
 * room type (reported); under another type, or removed, it is an error —
 * numbers are unique across the property. A room the legacy system moved to
 * another type is reported, not moved: moving a room is a staff decision
 * with its own checks (`RoomService::move()`).
 */
final class RoomImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'room';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Rooms', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'room_type', 'floor' );
	}

	/**
	 * The new room state of a legacy status.
	 *
	 * @param string $status Legacy status.
	 * @return string|null Null when the status is unknown.
	 */
	public static function state( string $status ): ?string {
		$map = array(
			'available'      => Room::AVAILABLE,
			'closed'         => Room::OUT_OF_SERVICE,
			'out_of_service' => Room::OUT_OF_SERVICE,
			'maintenance'    => Room::MAINTENANCE,
		);
		return $map[ strtolower( trim( $status ) ) ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 */
	public function run( ImportContext $context ): void {
		$service = new RoomService();
		$repo    = new RoomRepository();
		$rows    = $context->source->rows( 'SELECT id, room_id, number, floor, status FROM {hbfwc_restata_rooms} ORDER BY id' );
		$context->report->found( $this->key(), count( $rows ) );
		$seen = array();

		foreach ( $rows as $row ) {
			$legacy_id = (int) $row['id'];
			$number    = trim( (string) $row['number'] );
			$floor     = trim( (string) $row['floor'] );
			$type_id   = $context->map->newId( 'room_type', (int) $row['room_id'] );
			$floor_id  = '' !== $floor ? $context->map->newId( 'floor', $floor ) : 0;
			$state     = self::state( (string) $row['status'] );

			$problem = '';
			if ( '' === $number ) {
				$problem = __( 'The room has no number.', 'radius-hotel-booking-residencetata' );
			} elseif ( isset( $seen[ mb_strtolower( $number ) ] ) ) {
				/* translators: %s: room number. */
				$problem = sprintf( __( 'Room number %s appears twice in the legacy list.', 'radius-hotel-booking-residencetata' ), $number );
			} elseif ( '' === $floor ) {
				$problem = __( 'The room has no floor.', 'radius-hotel-booking-residencetata' );
			} elseif ( ! $type_id || ! $floor_id ) {
				$problem = __( 'Its room type or floor was not imported.', 'radius-hotel-booking-residencetata' );
			}
			$seen[ mb_strtolower( $number ) ] = true;
			if ( '' !== $problem ) {
				$context->report->count( $this->key(), 'failed' );
				$context->report->issue( $this->key(), $legacy_id, $problem, 'error' );
				continue;
			}
			if ( null === $state ) {
				$context->report->issue(
					$this->key(),
					$legacy_id,
					sprintf(
						/* translators: 1: room number, 2: legacy status. */
						__( 'Room %1$s has the unknown status "%2$s"; imported as available.', 'radius-hotel-booking-residencetata' ),
						$number,
						(string) $row['status']
					)
				);
				$state = Room::AVAILABLE;
			}

			$data = array(
				'number'       => $number,
				'room_type_id' => $type_id,
				'floor_id'     => $floor_id,
				'state'        => $state,
				// The legacy ids, so a change of type or floor there changes the checksum.
				'legacy_type'  => (int) $row['room_id'],
				'legacy_floor' => $floor,
			);

			$this->import(
				$context,
				$legacy_id,
				$data,
				function () use ( $repo, $data ) {
					$existing = $repo->findByNumberAny( $data['number'] );
					if ( ! $existing ) {
						return 0;
					}
					// A room type a dry run would create has a negative placeholder id: no existing room is in it.
					$same_type = (int) $existing->room_type_id === $data['room_type_id'];
					if ( ! empty( $existing->deleted_at ) || ! $same_type ) {
						$message = sprintf(
							/* translators: 1: room number, 2: room type name. */
							__( 'Room number %1$s is already used by a room of %2$s (or a removed room).', 'radius-hotel-booking-residencetata' ),
							$data['number'],
							(string) $existing->room_type_name
						);
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- report text, shown escaped.
						throw new DomainException( 'room_number_taken', $message, 409 );
					}
					return (int) $existing->id;
				},
				fn() => (int) $service->create(
					array(
						'room_type_id' => $data['room_type_id'],
						'floor_id'     => $data['floor_id'],
						'number'       => $data['number'],
						'state'        => $data['state'],
						'state_note'   => Room::AVAILABLE === $data['state'] ? '' : __( 'Imported from the old system', 'radius-hotel-booking-residencetata' ),
					)
				)->id,
				function ( int $room_id ) use ( $service, $data, $context, $legacy_id ) {
					$room = $service->get( $room_id );
					if ( (int) $room->room_type_id !== $data['room_type_id'] ) {
						$context->report->issue(
							$this->key(),
							$legacy_id,
							sprintf(
								/* translators: %s: room number. */
								__( 'Room %s changed room type in the old system; move it by hand if that is right.', 'radius-hotel-booking-residencetata' ),
								$data['number']
							)
						);
					}
					$service->update(
						$room_id,
						array(
							'number'   => $data['number'],
							'floor_id' => $data['floor_id'],
						)
					);
					if ( (string) $room->state !== $data['state'] ) {
						$service->setState(
							$room_id,
							array(
								'state' => $data['state'],
								'note'  => __( 'Changed in the old system', 'radius-hotel-booking-residencetata' ),
							)
						);
					}
				},
				sprintf(
					/* translators: %s: room number. */
					__( 'Room %s already exists in this room type; it was linked, not changed.', 'radius-hotel-booking-residencetata' ),
					$number
				)
			);
		}

		$this->compareProList( $context, $rows );
	}

	/**
	 * Compare with the PRO add-on's `_hbfwc_room_numbers` (rule 6).
	 *
	 * @param ImportContext $context The run.
	 * @param array         $rows    The legacy rooms.
	 * @return void
	 */
	private function compareProList( ImportContext $context, array $rows ): void {
		$pro = $context->source->rows( "SELECT post_id, meta_value FROM {postmeta} WHERE meta_key = '_hbfwc_room_numbers'" );
		foreach ( $pro as $meta ) {
			$value   = maybe_unserialize( $meta['meta_value'] );
			$numbers = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
			$numbers = array_values( array_filter( array_map( static fn( $n ) => trim( (string) ( is_array( $n ) ? ( $n['number'] ?? '' ) : $n ) ), (array) $numbers ) ) );
			$ours    = array_map( static fn( $row ) => trim( (string) $row['number'] ), array_filter( $rows, static fn( $row ) => (int) $row['room_id'] === (int) $meta['post_id'] ) );
			$only_pro  = array_diff( $numbers, $ours );
			$only_list = array_diff( $ours, $numbers );
			if ( $only_pro || $only_list ) {
				$context->report->issue(
					$this->key(),
					'product ' . (int) $meta['post_id'],
					sprintf(
						/* translators: 1: numbers only in the PRO list, 2: numbers only in the room list. */
						__( 'The two legacy room lists differ. Only in the PRO list: %1$s. Only in the room list: %2$s.', 'radius-hotel-booking-residencetata' ),
						$only_pro ? implode( ', ', $only_pro ) : '—',
						$only_list ? implode( ', ', $only_list ) : '—'
					)
				);
			}
		}
	}
}
