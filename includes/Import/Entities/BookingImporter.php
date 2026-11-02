<?php
/**
 * Bookings still open at cutover, from the legacy booking table (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Models\RatePlan;
use RadiusTheme\RadiusHotelBooking\Models\Room;
use RadiusTheme\RadiusHotelBooking\Repositories\BookingRepository;
use RadiusTheme\RadiusHotelBooking\Repositories\GuestRepository;
use RadiusTheme\RadiusHotelBooking\Repositories\RatePlanRepository;
use RadiusTheme\RadiusHotelBooking\Repositories\RoomRepository;
use RadiusTheme\RadiusHotelBooking\Services\Availability\StayWindow;
use RadiusTheme\RadiusHotelBooking\Services\Booking\BookingService;
use RadiusTheme\RadiusHotelBooking\Settings\PaymentSettings;
use RadiusTheme\RadiusHotelBooking\Support\Money;
use RadiusTheme\RadiusHotelBooking\Support\Phone;
use RadiusTheme\RadiusHotelBookingTata\Import\EntityImporter;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportMap;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * `hbfwc_restata_booking` lines still **open at cutover** — `pending`,
 * `confirmed` or `checked_in`, ending after it (M02 / M03 / M05 migration
 * notes) — → bookings, through `BookingService::create( …, 'import' )` (the
 * locked write path, importer rule 5), one per legacy order and status:
 *
 * - room: `room_number_id` → the imported room; rate: the order item's
 *   `rate_id` → the imported plan; the window: the legacy check-in date and
 *   time, and the number of nights / days whose window ends at the legacy
 *   check-out (none → an error naming both times);
 * - price: the order item's `_line_total` (split when one item holds several
 *   rooms), frozen as is (`price_override`); guests from `booking_guests`;
 * - guest: the imported customer, else the order's billing details — a guest
 *   on file with that phone or real e-mail **and the same name**; a match
 *   under another name is used (one phone, one guest) but reported, with the
 *   billing name in the booking's note;
 * - status: as in the old system — except a `checked_in` stay that has not
 *   started yet (→ confirmed, reported); a `checked_in` line whose stay has
 *   already ended is **not open** (never checked out there): skipped, reported;
 * - payment: a `completed` order is paid (one ledger row dated when the old
 *   desk took the money, method from the order: `cod` → cash; a method not
 *   set up here is reported), anything else unpaid; no deadline, no e-mails;
 * - a room that is not available now (maintenance, closed) is reported.
 *
 * The map is **per legacy line** (`booking` + the line id; status, price and
 * payment in the checksum), so a later status change is reported rather than
 * imported again, and a line cancelled in the old system after its import is
 * reported too. Changes are never applied: staff apply them by hand.
 *
 * A conflict (the room taken at that time, here or by another imported
 * booking) is an error, never accepted. **The dry run writes everything for
 * real inside one transaction it then rolls back** (a savepoint per
 * booking), so it reports exactly what the import will do, conflicts
 * included — once the rooms and rate plans exist: a booking whose room or
 * plan the same dry run only plans is `skipped` (not checkable yet).
 */
final class BookingImporter implements EntityImporter {

	public const OPEN = array( 'pending', 'confirmed', 'checked_in' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'booking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Bookings open at cutover', 'radius-hotel-booking-residencetata' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependsOn(): array {
		return array( 'room', 'rate_plan', 'room_type_rate', 'guest' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ImportContext $context The run.
	 * @throws RuntimeException Anything but the dry run's own rollback signal.
	 */
	public function run( ImportContext $context ): void {
		$groups = $this->groups( $context );
		$context->report->found( $this->key(), count( $groups ) );
		$this->closedSinceImport( $context );
		if ( ! $groups ) {
			return;
		}
		if ( ! $context->dry_run ) {
			foreach ( $groups as $legacy_id => $group ) {
				$this->one( $context, $legacy_id, $group );
			}
			return;
		}

		// Dry run: the real writes, in one transaction rolled back at the end.
		try {
			Transaction::run(
				function () use ( $context, $groups ) {
					global $wpdb;
					foreach ( $groups as $legacy_id => $group ) {
						$wpdb->query( 'SAVEPOINT rtbp_tata_import' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$ok = $this->one( $context, $legacy_id, $group );
						if ( ! $ok ) {
							$wpdb->query( 'ROLLBACK TO SAVEPOINT rtbp_tata_import' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						}
					}
					throw new RuntimeException( 'rtbp_tata_dry_run' );
				}
			);
		} catch ( RuntimeException $e ) {
			if ( 'rtbp_tata_dry_run' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	/**
	 * Import (or, in a dry run, write-and-forget) one legacy order + status:
	 * its lines not imported yet; the imported ones are only compared.
	 *
	 * @param ImportContext $context   The run.
	 * @param string        $legacy_id `order:status` (the report's id).
	 * @param array         $group     `{ order, status, paid, paid_at, …, lines: [ … ] }`.
	 * @return bool Whether it was written (or would be), or had nothing to write.
	 */
	private function one( ImportContext $context, string $legacy_id, array $group ): bool {
		$key     = $this->key();
		$pending = array();
		$changed = false;
		foreach ( $group['lines'] as $line ) {
			$checksum = self::lineChecksum( $group, $line );
			$status   = $context->map->status( $key, $line['legacy_line'], $checksum );
			if ( 'created' === $status ) {
				$pending[] = $line + array( 'checksum' => $checksum );
			} elseif ( 'updated' === $status ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			$context->report->issue( $key, $legacy_id, __( 'Changed in the old system since it was imported (status, room, dates, price or payment); apply the change to the booking by hand.', 'radius-hotel-booking-residencetata' ) );
		}
		if ( ! $pending ) {
			$context->report->count( $key, 'unchanged' );
			return true;
		}
		if ( count( $pending ) < count( $group['lines'] ) ) {
			$context->report->issue( $key, $legacy_id, __( 'A room was added to this order in the old system after it was imported; it is imported as a separate booking.', 'radius-hotel-booking-residencetata' ) );
		}
		$group['lines'] = $pending;

		try {
			list( $input, $warnings ) = $this->input( $context, $group );
			$booking                  = ( new BookingService() )->create( $input, array( 'audience' => 'staff' ), 'import' );
		} catch ( DomainException $e ) {
			if ( 'planned_parent' === $e->getErrorCode() ) {
				// A dry run on a site without the rooms or rates yet: nothing to check against.
				$context->report->count( $key, 'skipped' );
				$context->report->issue( $key, $legacy_id, $e->getMessage() );
				return false;
			}
			$context->report->count( $key, 'failed' );
			$context->report->issue( $key, $legacy_id, self::reason( $e, $group ), 'error' );
			return false;
		}
		foreach ( array_merge( $warnings, self::roomWarnings( $input ) ) as $warning ) {
			$context->report->issue( $key, $legacy_id, $warning );
		}
		$new_id = $context->dry_run ? $context->map->planned() : (int) $booking->id;
		foreach ( $pending as $line ) {
			$context->map->record( $key, $line['legacy_line'], $new_id, $line['checksum'] );
		}
		$context->report->count( $key, 'created' );
		return true;
	}

	/**
	 * What a line's map checksum covers: the line, its status and the order's payment.
	 *
	 * @param array $group Group.
	 * @param array $line  Line.
	 * @return string
	 */
	private static function lineChecksum( array $group, array $line ): string {
		return ImportMap::checksum(
			array(
				'line'    => $line,
				'status'  => $group['status'],
				'paid'    => $group['paid'],
				'paid_at' => $group['paid_at'],
			)
		);
	}

	/**
	 * Report imported lines that are no longer open in the old system for
	 * another reason than a stay that ended: cancelled, removed, or checked
	 * out before their end. The booking here still holds the room.
	 *
	 * @param ImportContext $context The run.
	 * @return void
	 */
	private function closedSinceImport( ImportContext $context ): void {
		$stored = $context->map->stored( $this->key() );
		$ids    = array_values( array_filter( array_map( 'intval', array_keys( $stored ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$bookings = new BookingRepository();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = array_column(
				$context->source->rows( 'SELECT id, wc_order_id, status, checkout FROM {hbfwc_restata_booking} WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ' )', $chunk ),
				null,
				'id'
			);
			foreach ( $chunk as $id ) {
				$row = $rows[ $id ] ?? null;
				if ( $row && ( in_array( (string) $row['status'], self::OPEN, true ) || ( 'checked_out' === (string) $row['status'] && (string) $row['checkout'] <= $context->cutover ) ) ) {
					continue;
				}
				$booking = $bookings->find( (int) $stored[ (string) $id ] );
				$ref     = $booking ? (string) $booking->reference : '#' . $stored[ (string) $id ];
				$message = $row
					/* translators: 1: legacy status, 2: order number, 3: booking reference. */
					? sprintf( __( 'Now "%1$s" in the old system (order #%2$d) since it was imported; apply it to booking %3$s by hand.', 'radius-hotel-booking-residencetata' ), (string) $row['status'], (int) $row['wc_order_id'], $ref )
					/* translators: %s: booking reference. */
					: sprintf( __( 'Removed from the old system since it was imported; check booking %s by hand.', 'radius-hotel-booking-residencetata' ), $ref );
				$context->report->issue( $this->key(), $row ? $row['wc_order_id'] . ':' . $row['status'] : $id, $message );
			}
		}
	}

	/**
	 * The open legacy lines, grouped by order and status, with their order data.
	 *
	 * @param ImportContext $context The run.
	 * @return array<string, array>
	 */
	private function groups( ImportContext $context ): array {
		$source  = $context->source;
		$cutover = $context->cutover;
		$rows    = $source->rows(
			"SELECT b.id, b.room_id, b.room_number_id, b.wc_order_id, b.wc_order_item_id, b.checkin, b.checkout, b.status
			FROM {hbfwc_restata_booking} b WHERE b.status IN ( 'pending', 'confirmed', 'checked_in' ) AND b.checkout > %s ORDER BY b.checkin, b.id",
			array( $cutover )
		);

		$groups = array();
		$orders = array();
		foreach ( $rows as $row ) {
			$status = (string) $row['status'];
			if ( 'checked_in' === $status && (string) $row['checkin'] > $cutover ) {
				$status = 'confirmed';
				$context->report->issue( $this->key(), $row['wc_order_id'] . ':' . $row['status'], __( 'Marked checked in although the stay has not started; imported as confirmed.', 'radius-hotel-booking-residencetata' ) );
			}
			$order = (int) $row['wc_order_id'];
			$id    = $order . ':' . $status;
			if ( ! isset( $groups[ $id ] ) ) {
				$orders[ $order ] = $orders[ $order ] ?? $this->order( $context, $order );
				$groups[ $id ]    = array(
					'order'  => $order,
					'status' => $status,
					'lines'  => array(),
				) + $orders[ $order ];
			}
			$groups[ $id ]['lines'][] = $this->line( $context, $row );
		}

		// Checked in there but ended before the cutover: never checked out in the old system.
		$stale = (int) $source->value( "SELECT COUNT(*) FROM {hbfwc_restata_booking} WHERE status = 'checked_in' AND checkout <= %s AND checkout >= DATE_SUB( %s, INTERVAL 30 DAY )", array( $cutover, $cutover ) );
		if ( $stale ) {
			$context->report->issue(
				$this->key(),
				'',
				sprintf(
					/* translators: %d: number of stays. */
					_n( '%d stay that ended in the last 30 days is still "checked in" in the old system; it is not open and was not imported.', '%d stays that ended in the last 30 days are still "checked in" in the old system; they are not open and were not imported.', $stale, 'radius-hotel-booking-residencetata' ),
					$stale
				)
			);
		}
		return $groups;
	}

	/**
	 * One legacy line's request data.
	 *
	 * @param ImportContext $context The run.
	 * @param array         $row     Legacy booking row.
	 * @return array
	 */
	private function line( ImportContext $context, array $row ): array {
		$meta     = array_column( $context->source->rows( 'SELECT meta_key, meta_value FROM {woocommerce_order_itemmeta} WHERE order_item_id = %d', array( (int) $row['wc_order_item_id'] ) ), 'meta_value', 'meta_key' );
		$per_item = max( 1, (int) $context->source->value( 'SELECT COUNT(*) FROM {hbfwc_restata_booking} WHERE wc_order_item_id = %d', array( (int) $row['wc_order_item_id'] ) ) );
		$guests   = json_decode( (string) ( $meta['booking_guests'] ?? '' ), true );
		return array(
			'legacy_line' => (int) $row['id'],
			'room'        => (int) $row['room_number_id'],
			'rate'        => (int) ( $meta['rate_id'] ?? 0 ),
			'checkin'     => substr( (string) $row['checkin'], 0, 19 ),
			'checkout'    => substr( (string) $row['checkout'], 0, 19 ),
			'price'       => (float) Money::round( (float) ( $meta['_line_total'] ?? 0 ) / $per_item ),
			'adults'      => max( 1, (int) ( $guests['adults'] ?? 1 ) ),
			'children'    => max( 0, (int) ( $guests['children'] ?? 0 ) ),
		);
	}

	/**
	 * The order's guest, payment and date.
	 *
	 * @param ImportContext $context The run.
	 * @param int           $order   Order id.
	 * @return array
	 */
	private function order( ImportContext $context, int $order ): array {
		$source = $context->source;
		$row    = $source->has( 'wc_orders' ) ? $source->rows( 'SELECT status, payment_method, total_amount, customer_id, date_created_gmt FROM {wc_orders} WHERE id = %d', array( $order ) ) : array();
		$row    = $row[0] ?? array();
		$bill   = $source->has( 'wc_order_addresses' ) ? $source->rows( "SELECT first_name, last_name, phone, email FROM {wc_order_addresses} WHERE order_id = %d AND address_type = 'billing'", array( $order ) ) : array();
		$bill   = $bill[0] ?? array();
		// The identity document typed at checkout, kept on the order (post meta, also with HPOS).
		$doc = array();
		if ( $source->has( 'postmeta' ) ) {
			foreach ( $source->rows( "SELECT meta_key, meta_value FROM {postmeta} WHERE post_id = %d AND meta_key IN ( 'id_type', 'id_number' )", array( $order ) ) as $meta ) {
				$doc[ $meta['meta_key'] ] = trim( (string) $meta['meta_value'] );
			}
		}
		$paid   = 'wc-completed' === ( $row['status'] ?? '' );
		// When the old desk took the money (site time): reports count it on that day, not on the import's.
		$paid_at = '';
		if ( $paid && $source->has( 'wc_order_operational_data' ) ) {
			$dates = $source->rows( 'SELECT date_paid_gmt, date_completed_gmt FROM {wc_order_operational_data} WHERE order_id = %d', array( $order ) );
			$gmt   = (string) ( $dates[0]['date_paid_gmt'] ?? '' );
			$gmt   = '' !== $gmt && 0 !== strpos( $gmt, '0000' ) ? $gmt : (string) ( $dates[0]['date_completed_gmt'] ?? '' );
			if ( '' !== $gmt && 0 !== strpos( $gmt, '0000' ) ) {
				$paid_at = get_date_from_gmt( $gmt, 'Y-m-d H:i' );
			}
		}
		return array(
			'paid'     => $paid,
			'paid_at'  => $paid_at,
			'method'   => (string) ( $row['payment_method'] ?? '' ),
			'customer' => (int) ( $row['customer_id'] ?? 0 ),
			'created'  => substr( (string) ( $row['date_created_gmt'] ?? '' ), 0, 10 ),
			'billing'  => array(
				'first_name' => trim( (string) ( $bill['first_name'] ?? '' ) ),
				'last_name'  => trim( (string) ( $bill['last_name'] ?? '' ) ),
				'phone'      => trim( (string) ( $bill['phone'] ?? '' ) ),
				'email'      => trim( (string) ( $bill['email'] ?? '' ) ),
			),
			'document' => array(
				'type'   => strtolower( $doc['id_type'] ?? '' ),
				'number' => $doc['id_number'] ?? '',
			),
		);
	}

	/**
	 * The order's identity document as guest fields, the type mapped like the
	 * customers' (GuestImporter::ID_TYPES); a type it does not know drops the
	 * document, with a warning.
	 *
	 * @param array    $doc      `{ type, number }` from the order.
	 * @param string[] $warnings Warnings, appended to.
	 * @return array `{ id_type, id_number }`, or empty.
	 */
	private static function document( array $doc, array &$warnings ): array {
		$type   = (string) ( $doc['type'] ?? '' );
		$number = (string) ( $doc['number'] ?? '' );
		if ( '' === $number ) {
			return array();
		}
		if ( ! isset( GuestImporter::ID_TYPES[ $type ] ) ) {
			/* translators: %s: document type as stored. */
			$warnings[] = sprintf( __( 'The identity document type "%s" is unknown; the guest is created without the document.', 'radius-hotel-booking-residencetata' ), $type );
			return array();
		}
		return array(
			'id_type'   => GuestImporter::ID_TYPES[ $type ],
			'id_number' => $number,
		);
	}

	/**
	 * The `BookingService::create()` input of a group, and the warnings it raises.
	 *
	 * @param ImportContext $context The run.
	 * @param array         $group   Group.
	 * @return array{0: array, 1: string[]}
	 * @throws DomainException 422 when a room, plan or window cannot be resolved; `planned_parent`
	 *                         when the room or plan is one this dry run only plans.
	 */
	private function input( ImportContext $context, array $group ): array {
		$plans    = new RatePlanRepository();
		$lines    = array();
		$total    = 0.0;
		$warnings = array();
		foreach ( $group['lines'] as $index => $line ) {
			$room_id = $context->map->newId( 'room', $line['room'] );
			$plan_id = $context->map->newId( 'rate_plan', $line['rate'] );
			if ( $room_id < 0 || $plan_id < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- report text.
				throw new DomainException( 'planned_parent', __( 'Not checked: its room or rate plan would be created by this dry run. Import the rooms and rates first, then dry-run the bookings.', 'radius-hotel-booking-residencetata' ), 409 );
			}
			if ( 0 === $room_id || 0 === $plan_id ) {
				$message = 0 === $room_id ? __( 'Its room was not imported.', 'radius-hotel-booking-residencetata' ) : __( 'Its rate plan was not imported.', 'radius-hotel-booking-residencetata' );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- report text.
				throw DomainException::invalid( array( "lines.$index" => $message ), $message );
			}
			$plan   = $plans->find( $plan_id );
			$window = $plan instanceof RatePlan ? self::fit( $plan, $line['checkin'], $line['checkout'] ) : null;
			if ( ! $window ) {
				$message = sprintf(
					/* translators: 1: legacy check-in, 2: legacy check-out, 3: rate plan name. */
					__( 'The stay %1$s → %2$s does not fit the window of %3$s.', 'radius-hotel-booking-residencetata' ),
					$line['checkin'],
					$line['checkout'],
					$plan instanceof RatePlan ? (string) $plan->name : '#' . $plan_id
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- report text.
				throw DomainException::invalid( array( "lines.$index" => $message ), $message );
			}
			$lines[] = $window + array(
				'room_id'        => $room_id,
				'rate_plan_id'   => $plan_id,
				'adults'         => $line['adults'],
				'children'       => $line['children'],
				'price_override' => $line['price'],
			);
			$total  += $line['price'];
		}

		list( $method, $known ) = self::method( $group['method'] );
		if ( $group['paid'] && ! $known ) {
			$warnings[] = sprintf(
				/* translators: 1: legacy payment method, 2: method used here. */
				__( 'Paid by "%1$s" in the old system, a method not set up here; recorded as "%2$s". Correct the payment if needed.', 'radius-hotel-booking-residencetata' ),
				'' !== $group['method'] ? $group['method'] : '?',
				$method
			);
		}
		$input = array(
			'lines'             => $lines,
			'status'            => $group['status'],
			'checked_in_at'     => 'checked_in' === $group['status'] ? $group['lines'][0]['checkin'] : '',
			'paid_amount'       => $group['paid'] ? $total : 0,
			'paid_at'           => $group['paid'] ? $group['paid_at'] : '',
			'payment_method'    => $method,
			'payment_reference' => sprintf( /* translators: %d: order number. */ __( 'Order #%d (old system)', 'radius-hotel-booking-residencetata' ), $group['order'] ),
			'note'              => sprintf(
				/* translators: 1: order number, 2: date. */
				__( 'Imported from the old system: order #%1$d of %2$s.', 'radius-hotel-booking-residencetata' ),
				$group['order'],
				$group['created']
			),
		);
		$guest_id = $group['customer'] ? $context->map->newId( 'guest', $group['customer'] ) : 0;
		if ( $guest_id > 0 ) {
			$input['guest_id'] = $guest_id;
			return array( $input, $warnings );
		}
		// No account, or a guest this dry run only plans: the order's billing details. A guest
		// already on file with that phone or real e-mail and the same name is that person.
		$billing = $group['billing'];
		if ( null !== GuestImporter::placeholderDigits( $billing['email'] ) ) {
			$billing['email'] = '';
		}
		$e164  = '' !== $billing['phone'] ? Phone::toE164( $billing['phone'] ) : null;
		$found = ( new GuestRepository() )->duplicates( $e164, '' !== $billing['phone'] ? Phone::tail( $billing['phone'] ) : '', is_email( $billing['email'] ) ? strtolower( $billing['email'] ) : null );
		if ( ! $found ) {
			$input['guest'] = $billing + self::document( $group['document'] ?? array(), $warnings );
			return array( $input, $warnings );
		}
		$billed = trim( $billing['first_name'] . ' ' . $billing['last_name'] );
		$pick   = null;
		foreach ( $found as $row ) {
			if ( AbstractImporter::sameName( $billed, $row['first_name'] . ' ' . $row['last_name'] ) ) {
				$pick = $row;
				break;
			}
		}
		if ( ! $pick ) {
			// One phone, one guest here: the booking goes under the guest on file, and says who was billed.
			$pick        = reset( $found );
			$on_file     = trim( $pick['first_name'] . ' ' . $pick['last_name'] );
			$warnings[]  = sprintf(
				/* translators: 1: billed name, 2: guest on file, 3: guest reference. */
				__( 'Billed to %1$s, whose phone or e-mail is on file for %2$s (%3$s); booked under %2$s. Check the guest.', 'radius-hotel-booking-residencetata' ),
				$billed,
				$on_file,
				$pick['reference']
			);
			$input['note'] .= ' ' . sprintf(
				/* translators: 1: billed name, 2: phone. */
				__( 'Billed to %1$s (%2$s).', 'radius-hotel-booking-residencetata' ),
				$billed,
				$billing['phone']
			);
		}
		$input['guest_id'] = (int) $pick['id'];
		return array( $input, $warnings );
	}

	/**
	 * Warnings for rooms that are not available now (the import does not refuse them).
	 *
	 * @param array $input The create input.
	 * @return string[]
	 */
	private static function roomWarnings( array $input ): array {
		$warnings = array();
		$rooms    = new RoomRepository();
		foreach ( array_unique( array_column( $input['lines'], 'room_id' ) ) as $room_id ) {
			$room = $rooms->find( (int) $room_id );
			if ( $room && Room::AVAILABLE !== (string) $room->state ) {
				$warnings[] = sprintf(
					/* translators: 1: room number, 2: room state. */
					__( 'Room %1$s is "%2$s" now; the booking was imported on it. Move the guest or reopen the room.', 'radius-hotel-booking-residencetata' ),
					(string) $room->number,
					(string) $room->state
				);
			}
		}
		return $warnings;
	}

	/**
	 * The request window that reproduces the legacy stay: arrival = the
	 * check-in date, the check-in time for a flexible plan, and the number of
	 * units whose window ends at the legacy check-out. Null when none does.
	 *
	 * @param RatePlan $plan     Plan.
	 * @param string   $checkin  Legacy check-in (site time).
	 * @param string   $checkout Legacy check-out (site time).
	 * @return array|null `{ arrival, units, checkin_time? }`.
	 */
	public static function fit( RatePlan $plan, string $checkin, string $checkout ): ?array {
		$arrival  = substr( $checkin, 0, 10 );
		$time     = substr( $checkin, 11, 5 );
		$flexible = RatePlan::FLEXIBLE === (string) $plan->type;
		if ( ! $flexible && substr( (string) $plan->start_time, 0, 5 ) !== $time ) {
			return null;
		}
		$max = (int) $plan->multi_unit ? 60 : 1;
		for ( $units = 1; $units <= $max; $units++ ) {
			try {
				$window = StayWindow::for( $plan, $arrival, $units, $flexible ? $time : null );
			} catch ( \Throwable $e ) {
				return null;
			}
			$end = $window->toRow()['end_at'];
			if ( $end === $checkout ) {
				return array_filter(
					array(
						'arrival'      => $arrival,
						'units'        => $units,
						'checkin_time' => $flexible ? $time : null,
					),
					static fn( $value ) => null !== $value
				);
			}
			if ( $end > $checkout ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * The new payment method of a legacy order's method (`cod` = paid at the hotel → cash).
	 *
	 * @param string $legacy Legacy method.
	 * @return array{0: string, 1: bool} The method, and whether the legacy one maps to an enabled method.
	 */
	private static function method( string $legacy ): array {
		$keys = array_column( PaymentSettings::enabled_methods(), 'key' );
		$map  = array(
			'cod'          => 'cash',
			'wave'         => 'wave',
			'orange_money' => 'orange_money',
		);
		$key  = $map[ $legacy ] ?? '';
		if ( '' !== $key && in_array( $key, $keys, true ) ) {
			return array( $key, true );
		}
		return array( in_array( 'cash', $keys, true ) ? 'cash' : (string) ( $keys[0] ?? '' ), false );
	}

	/**
	 * A refusal as one line of the report.
	 *
	 * @param DomainException $e     Error.
	 * @param array           $group Group.
	 * @return string
	 */
	private static function reason( DomainException $e, array $group ): string {
		$first = $group['lines'][0];
		$what  = sprintf( '%s → %s', $first['checkin'], $first['checkout'] );
		if ( 'room_unavailable' === $e->getErrorCode() ) {
			/* translators: 1: the stay, 2: the reason. */
			return sprintf( __( 'The room is not free for %1$s: %2$s', 'radius-hotel-booking-residencetata' ), $what, $e->getMessage() );
		}
		return sprintf( '%s (%s)', AbstractImporter::reasonOf( $e ), $what );
	}
}
