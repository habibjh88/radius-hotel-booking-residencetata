<?php
/**
 * Past bookings stay readable on their WooCommerce orders (M18, 18.9).
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Legacy
 */

namespace RadiusTheme\RadiusHotelBookingTata\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * After cutover the legacy hotel plugins are deactivated (never deleted) and
 * the shop keeps WooCommerce, so the past bookings stay in their orders. The
 * legacy plugins formatted the booking details of an order item; without
 * them WooCommerce would show the raw meta (`booking_date` as JSON with Unix
 * times, `rate_id: 95`). This keeps them readable — the stay, the rate plan's
 * name, the guests — and hides the internal room id. **Read-only**: it only
 * formats what WooCommerce displays; no data is changed.
 *
 * Active only with WooCommerce and **without** the legacy plugin (which does
 * the same, its own way).
 */
final class OrderMetaDisplay {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( 'WooCommerce' ) || class_exists( '\\HBFWC\\WC\\WC_Hooks' ) ) {
			return;
		}
		add_filter( 'woocommerce_order_item_display_meta_key', array( self::class, 'key' ), 20 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( self::class, 'value' ), 20, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( self::class, 'hidden' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( self::class, 'formatted' ), 20 );
	}

	/**
	 * A legacy meta key's label.
	 *
	 * @param string $key Display key.
	 * @return string
	 */
	public static function key( $key ): string {
		$labels = array(
			'booking_date'   => __( 'Stay', 'radius-hotel-booking-residencetata' ),
			'rate_id'        => __( 'Rate plan', 'radius-hotel-booking-residencetata' ),
			'booking_guests' => __( 'Guests', 'radius-hotel-booking-residencetata' ),
		);
		return $labels[ (string) $key ] ?? (string) $key;
	}

	/**
	 * A legacy meta value, readable.
	 *
	 * @param string $value Display value.
	 * @param object $meta  The meta (`key`, `value`).
	 * @return string
	 */
	public static function value( $value, $meta = null ): string {
		$key = is_object( $meta ) ? (string) ( $meta->key ?? '' ) : '';
		$raw = is_object( $meta ) ? ( $meta->value ?? $value ) : $value;
		switch ( $key ) {
			case 'booking_date':
				return self::stay( (string) $raw, (string) $value );
			case 'rate_id':
				return self::rate( (int) $raw, (string) $value );
			case 'booking_guests':
				return self::guests( (string) $raw, (string) $value );
		}
		return (string) $value;
	}

	/**
	 * `woocommerce_hidden_order_itemmeta`: the legacy internal room id.
	 *
	 * @param array $hidden Hidden keys.
	 * @return array
	 */
	public static function hidden( $hidden ): array {
		return array_merge( (array) $hidden, array( '__room_number_id' ) );
	}

	/**
	 * `woocommerce_order_item_get_formatted_meta_data`: the internal room id
	 * is hidden on the customer side too (e-mails, the account page).
	 *
	 * @param array $formatted Formatted meta, id => object.
	 * @return array
	 */
	public static function formatted( $formatted ): array {
		return array_filter( (array) $formatted, static fn( $meta ) => ! is_object( $meta ) || '__room_number_id' !== ( $meta->key ?? '' ) );
	}

	/**
	 * `{"check_in":"1772308800","check_out":"1772366400"}` → "28/02/2026 20:00 → 01/03/2026 12:00".
	 * The legacy plugin stored the site's local time as Unix seconds, so it is
	 * shown as stored (gmdate), whatever the site time zone is today.
	 *
	 * @param string $raw      Raw value.
	 * @param string $fallback Value shown when it cannot be read.
	 * @return string
	 */
	private static function stay( string $raw, string $fallback ): string {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['check_in'] ) || ! is_numeric( $data['check_in'] ) ) {
			return $fallback;
		}
		$format = trim( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );
		$in     = gmdate( $format, (int) $data['check_in'] );
		$out    = ! empty( $data['check_out'] ) && is_numeric( $data['check_out'] ) ? gmdate( $format, (int) $data['check_out'] ) : '';
		return '' !== $out ? $in . ' → ' . $out : $in;
	}

	/**
	 * A legacy rate plan id → its name (read from the terms table: the
	 * `rate_plan` taxonomy is gone with the legacy plugin).
	 *
	 * @param int    $term_id  Term id.
	 * @param string $fallback Value shown when unknown.
	 * @return string
	 */
	private static function rate( int $term_id, string $fallback ): string {
		static $names = array();
		if ( ! array_key_exists( $term_id, $names ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomy is no longer registered; cached for the request above.
			$names[ $term_id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'rate_plan' WHERE t.term_id = %d", $term_id ) );
		}
		return '' !== $names[ $term_id ] ? $names[ $term_id ] : $fallback;
	}

	/**
	 * `{"adults":"2","children":"1"}` → "2 adults, 1 child".
	 *
	 * @param string $raw      Raw value.
	 * @param string $fallback Value shown when it cannot be read.
	 * @return string
	 */
	private static function guests( string $raw, string $fallback ): string {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return $fallback;
		}
		$adults   = (int) ( $data['adults'] ?? 0 );
		$children = (int) ( $data['children'] ?? 0 );
		/* translators: %d: number of adults. */
		$parts = array( sprintf( _n( '%d adult', '%d adults', $adults, 'radius-hotel-booking-residencetata' ), $adults ) );
		if ( $children ) {
			/* translators: %d: number of children. */
			$parts[] = sprintf( _n( '%d child', '%d children', $children, 'radius-hotel-booking-residencetata' ), $children );
		}
		return implode( ', ', $parts );
	}
}
