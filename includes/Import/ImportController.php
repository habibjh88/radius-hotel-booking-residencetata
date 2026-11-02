<?php
/**
 * Import screen API (M18).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Import
 */

namespace RadiusTheme\RadiusHotelBookingTata\Import;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET tata/import`, `POST tata/import/dry-run`, `POST tata/import/run` —
 * administrators only (`manage_options`): the importer runs once, at
 * cutover, and writes into every part of the hotel's data, so it is not an
 * access key a role could be given. Registered only when Pro's
 * GuardsRequests exists (Plugin::boot()).
 */
class ImportController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'tata/import', array( self::class, 'status' ) );
		$router->post( 'tata/import/dry-run', array( self::class, 'dry_run' ) );
		$router->post( 'tata/import/run', array( self::class, 'run' ) );
	}

	/**
	 * GET tata/import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function status( WP_REST_Request $request ) {
		return $this->admin( $request, fn() => ApiResponse::success( ( new ImportService() )->status() )->send() );
	}

	/**
	 * POST tata/import/dry-run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function dry_run( WP_REST_Request $request ) {
		return $this->admin(
			$request,
			fn( $request ) => ApiResponse::success(
				( new ImportService() )->run( (array) $request->get_json_params(), true ),
				__( 'Dry run finished. Nothing was written.', 'radius-hotel-booking-residencetata' )
			)->send()
		);
	}

	/**
	 * POST tata/import/run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function run( WP_REST_Request $request ) {
		return $this->admin(
			$request,
			fn( $request ) => ApiResponse::success(
				( new ImportService() )->run( (array) $request->get_json_params(), false ),
				__( 'Import finished.', 'radius-hotel-booking-residencetata' )
			)->send()
		);
	}

	/**
	 * Behind the dashboard middleware, administrators only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param callable        $handler Handler.
	 * @return mixed
	 */
	private function admin( WP_REST_Request $request, callable $handler ) {
		return $this->guard(
			$request,
			null,
			static function ( $request ) use ( $handler ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw new DomainException( 'admin_only', __( 'Only an administrator can import data.', 'radius-hotel-booking-residencetata' ), 403 );
				}
				return $handler( $request );
			}
		);
	}
}
