<?php
/**
 * Rate plans from the legacy rate_plan terms (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import\Entities
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import\Entities;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Models\RatePlan;
use RadiusTheme\RadiusHotelBooking\Services\Availability\StayWindow;
use RadiusTheme\RadiusHotelBooking\Services\Pricing\RatePlanService;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportContext;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy `rate_plan` terms + term meta (`schedule_type fixed|flex`,
 * `start_time`, `end_time`, `features`) → the rate plan library (M07
 * migration notes, importer rule 4), **deduplicated on the window**: two
 * legacy plans with the same type and times, or a plan matching one already
 * in the library, become one plan (linked). More than one library plan with
 * that window is ambiguous: an error, for a person to decide.
 *
 * Decided from the window, never from the name (booking-engine §2):
 * `multi_unit` is on for a window that crosses midnight or lasts 24 h and
 * more. The legacy flexible plan stores no length or check-in range: it gets
 * 24 h, check-in any time, and a warning to review.
 */
final class RatePlanImporter extends AbstractImporter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'rate_plan';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Rate plans', 'radius-hotel-booking-residencetata' );
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
		$service = new RatePlanService();
		$terms   = $context->source->rows(
			"SELECT t.term_id, t.name, t.slug FROM {terms} t
			JOIN {term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'rate_plan'
			ORDER BY t.term_id"
		);
		$context->report->found( $this->key(), count( $terms ) );
		// Windows planned in this run (window key => plan id), so two legacy plans with one window merge, dry run too.
		$planned = array();

		foreach ( $terms as $term ) {
			$id   = (int) $term['term_id'];
			$meta = array_column( $context->source->rows( 'SELECT meta_key, meta_value FROM {termmeta} WHERE term_id = %d', array( $id ) ), 'meta_value', 'meta_key' );
			$data = $this->data( $context, $term, $meta );
			if ( null === $data ) {
				continue;
			}
			$window = self::window( $data );

			$new_id = $this->import(
				$context,
				$id,
				$data,
				function () use ( $service, $data, $window, &$planned ) {
					if ( isset( $planned[ $window ] ) ) {
						return $planned[ $window ];
					}
					$matches = array_values(
						array_filter(
							$service->all(),
							static fn( $plan ) => self::window( (array) ( method_exists( $plan, 'toArray' ) ? $plan->toArray() : $plan ) ) === $window
						)
					);
					if ( count( $matches ) > 1 ) {
						$message = sprintf(
							/* translators: %s: comma-separated rate plan names. */
							__( 'Several rate plans already have this window (%s); link it by hand.', 'radius-hotel-booking-residencetata' ),
							implode( ', ', array_map( static fn( $plan ) => (string) $plan->name, $matches ) )
						);
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- report text, shown escaped.
						throw new DomainException( 'rate_plan_ambiguous', $message, 409 );
					}
					return $matches ? (int) $matches[0]->id : 0;
				},
				fn() => (int) $service->create( $data )->id,
				function ( int $plan_id ) use ( $service, $data ) {
					$service->update(
						$plan_id,
						array(
							'name'     => $data['name'],
							'features' => $data['features'],
						)
					);
				},
				sprintf(
					/* translators: %s: rate plan name. */
					__( 'A rate plan with the same window already exists; %s was merged into it.', 'radius-hotel-booking-residencetata' ),
					$data['name']
				)
			);
			if ( $new_id && ! isset( $planned[ $window ] ) ) {
				$planned[ $window ] = $new_id;
			}
		}
	}

	/**
	 * The rate plan fields of a legacy term, or null (reported) when it cannot be read.
	 *
	 * @param ImportContext $context The run.
	 * @param array         $term    Term row.
	 * @param array         $meta    Term meta.
	 * @return array|null
	 */
	private function data( ImportContext $context, array $term, array $meta ): ?array {
		$id       = (int) $term['term_id'];
		$features = array_values( array_filter( array_map( static fn( $f ) => self::text( $f ), explode( ',', (string) ( $meta['features'] ?? '' ) ) ) ) );
		$base     = array(
			'name'      => self::text( $term['name'] ),
			'features'  => $features,
			'is_active' => true,
		);

		if ( 'flex' === ( $meta['schedule_type'] ?? '' ) ) {
			$context->report->issue( $this->key(), $id, __( 'The old flexible plan has no stay length or check-in hours: it was set to 24 hours, check-in any time. Check it under Rate plans.', 'radius-hotel-booking-residencetata' ) );
			return $base + array(
				'type'             => RatePlan::FLEXIBLE,
				'duration_minutes' => 1440,
				'checkin_from'     => '00:00',
				'checkin_until'    => '23:30',
				'multi_unit'       => true,
			);
		}

		$start = substr( trim( (string) ( $meta['start_time'] ?? '' ) ), 0, 5 );
		$end   = substr( trim( (string) ( $meta['end_time'] ?? '' ) ), 0, 5 );
		if ( null === StayWindow::minutesOf( $start ) || null === StayWindow::minutesOf( $end ) ) {
			$context->report->count( $this->key(), 'failed' );
			$context->report->issue( $this->key(), $id, sprintf( /* translators: %s: plan name. */ __( '%s has no valid check-in and check-out times.', 'radius-hotel-booking-residencetata' ), $base['name'] ), 'error' );
			return null;
		}
		$minutes = StayWindow::fixedMinutes( $start, $end );
		return $base + array(
			'type'       => RatePlan::FIXED,
			'start_time' => $start,
			'end_time'   => $end,
			// Crosses midnight (end not after start) or lasts a full day: sold per night or day.
			'multi_unit' => StayWindow::minutesOf( $end ) <= StayWindow::minutesOf( $start ) || $minutes >= 1440,
		);
	}

	/**
	 * A plan's window as a comparable key.
	 *
	 * @param array $plan Plan fields (import data or a library plan).
	 * @return string
	 */
	private static function window( array $plan ): string {
		if ( RatePlan::FLEXIBLE === ( $plan['type'] ?? '' ) ) {
			return 'flexible|' . (int) ( $plan['duration_minutes'] ?? 0 );
		}
		return 'fixed|' . substr( (string) ( $plan['start_time'] ?? '' ), 0, 5 ) . '|' . substr( (string) ( $plan['end_time'] ?? '' ), 0, 5 );
	}
}
