<?php
/**
 * Client add-on bootstrap.
 *
 * @package RadiusTheme\RadiusHotelBookingTata
 */

namespace RadiusTheme\RadiusHotelBookingTata;

use RadiusTheme\RadiusHotelBookingTata\Assets\Assets;
use RadiusTheme\RadiusHotelBookingTata\Databases\Installer;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportCommand;
use RadiusTheme\RadiusHotelBookingTata\Import\ImportController;
use RadiusTheme\RadiusHotelBookingTata\Legacy\OrderMetaDisplay;
use RadiusTheme\RadiusHotelBookingTata\Settings\LocalChecks;
use RadiusTheme\RadiusHotelBookingTata\Settings\LocalDefaults;
use RadiusTheme\RadiusHotelBookingTata\Settings\LocalLanguages;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the Residence TATA add-on once both the free plugin and Pro have
 * booted.
 *
 * Pro fires `rtbp_pro_loaded` at the end of its own boot, which runs on the
 * free plugin's `rtbp_loaded`, so hooking there guarantees every free and Pro
 * class, filter and container binding exists. Without either plugin the
 * add-on shows a notice and registers nothing else.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the dependency check and the boot hook.
	 */
	private function __construct() {
		add_action( 'rtbp_pro_loaded', array( $this, 'boot' ) );
		add_action( 'plugins_loaded', array( $this, 'check_dependencies' ), 100 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Show a notice when the free plugin or Pro never booted.
	 *
	 * @return void
	 */
	public function check_dependencies(): void {
		if ( did_action( 'rtbp_pro_loaded' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Radius Hotel Booking - Residence TATA needs Radius Hotel Booking and Radius Hotel Booking Pro to be installed and active.', 'radius-hotel-booking-residencetata' )
				);
			}
		);
	}

	/**
	 * Boot the add-on. Runs on Pro's `rtbp_pro_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::init();
		( new Assets() )->register();
		LocalChecks::register();
		// Côte d'Ivoire: phone country and numbering, Wave / Orange Money (kept out of the global free plugin).
		LocalDefaults::register();
		// The account menu offers English and French, like the old dashboard (free 1.0.21+, R1-T6).
		LocalLanguages::register();
		// The hotel and Food Menu dashboards link to each other (R1-T18).
		\RadiusTheme\RadiusHotelBookingTata\Settings\DashboardLinks::register();
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema' ) ) {
			// The schema may have been read before this add-on booted.
			\RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema::flush();
		}
		// Payroll is Pro's (feature `payroll`): this add-on plugs in the Ivorian taxes and contributions.
		\RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll::register();
		// The seniority bonus as a Pro bonus rule kind (R1-T17; Pro's filters, unused without them).
		\RadiusTheme\RadiusHotelBookingTata\Payroll\SeniorityBonus::register();
		add_filter( 'rtbp_activity_actions', array( $this, 'activity_actions' ) );
		// Past bookings stay readable on their WooCommerce orders once the legacy plugins are off (18.9).
		// On `init`: the add-on boots before WooCommerce (and the legacy plugin) may have loaded.
		add_action( 'init', array( OrderMetaDisplay::class, 'register' ), 5 );
		// The hotel's customers book signed in with their old accounts (free: Settings → Guest accounts).
		\RadiusTheme\RadiusHotelBookingTata\Legacy\CustomerProfile::register();
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Storage\\ProtectedFiles' ) ) {
			add_filter( 'rtbp_file_kinds', array( \RadiusTheme\RadiusHotelBookingTata\Import\Entities\LegacyLogExport::class, 'kind' ) );
		}

		// The import screen's API (M18): reuses Pro's request guard.
		if ( trait_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Access\\GuardsRequests' ) ) {
			add_action( 'rtbp_register_addon_routes', array( ImportController::class, 'routes' ) );
		}

		// The legacy import's entities (M18), each guarded on the free / Pro classes it writes through.
		add_filter( 'rtbp_tata_import_entities', array( $this, 'import_entities' ) );

		// The legacy import (M18): `wp radius-hotel-booking import:legacy`.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'radius-hotel-booking import:legacy', ImportCommand::class );
		}

		/**
		 * Fires once the Residence TATA add-on has booted.
		 *
		 * @param Plugin $plugin The add-on.
		 */
		do_action( 'rtbp_tata_loaded', $this );
	}

	/**
	 * `rtbp_tata_import_entities`: the entity importers whose services exist.
	 *
	 * @param array $importers Importers.
	 * @return array
	 */
	public function import_entities( $importers ): array {
		$importers = (array) $importers;
		$free      = '\\RadiusTheme\\RadiusHotelBooking\\Services\\Inventory\\';
		if ( class_exists( $free . 'RoomTypeService' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\RoomTypeImporter();
		}
		if ( class_exists( $free . 'FloorService' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\FloorImporter();
		}
		if ( class_exists( $free . 'RoomService' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Repositories\\RoomRepository' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\RoomImporter();
		}
		$pricing = '\\RadiusTheme\\RadiusHotelBooking\\Services\\Pricing\\';
		if ( class_exists( $pricing . 'RatePlanService' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\RatePlanImporter();
		}
		if ( class_exists( $pricing . 'RoomTypeRateService' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Repositories\\RoomTypeRateRepository' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\RoomTypeRateImporter();
		}
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\Availability\\CalendarService' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\RateCalendarImporter();
		}
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\Guests\\GuestService' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Repositories\\GuestRepository' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Support\\Phone' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\GuestImporter();
			if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\Notes\\NoteService' ) ) {
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\GuestNoteImporter();
			}
		}
		// Bookings open at cutover: the free import mode of BookingService, version 2 (`paid_at`):
		// free M18 with its review fixes. An older free build would ignore the import fields.
		$booking = '\\RadiusTheme\\RadiusHotelBooking\\Services\\Booking\\BookingService';
		if ( class_exists( $booking ) && defined( $booking . '::IMPORT_MODE' ) && (int) constant( $booking . '::IMPORT_MODE' ) >= 2
			&& class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\Availability\\StayWindow' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Repositories\\RoomRepository' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\BookingImporter();
		}

		// Staff (Pro): needs EmployeeService::attach(), added in Pro for M18 T4b.
		$staff = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\';
		if ( class_exists( $staff . 'EmployeeService' ) && method_exists( $staff . 'EmployeeService', 'attach' ) && class_exists( $staff . 'EmployeeRepository' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\EmployeeImporter();
			if ( class_exists( $staff . 'ContractService' ) ) {
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\EmployeeContractImporter();
			}
			$access = '\\RadiusTheme\\RadiusHotelBookingPro\\Access\\';
			if ( class_exists( $access . 'UserAccessService' ) && class_exists( $access . 'PasscodeService' ) && class_exists( $access . 'ProfileFields' ) && class_exists( $access . 'AccessSettings' ) && class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\SettingsService' ) ) {
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\AccessSettingsImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\StaffAccessImporter();
			}
			// Payroll (M15 T10): set-up, then history; every write goes through Pro's payroll classes.
			if ( \RadiusTheme\RadiusHotelBookingTata\Payroll\IvorianPayroll::active() && class_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Payroll\\PayrollRunService' ) ) {
				if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Services\\SettingsService' ) ) {
					$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\PayrollSettingsImporter();
				}
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\PayScheduleImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\WorkShiftImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\EmployeePayImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\EmployeeDeductionImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\TimeOffImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\PayrollHistoryImporter();
				$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\StatutoryPaymentImporter();
			}
		}
		// The old activity log, kept once as a CSV file (M14 / M18).
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Storage\\ProtectedFiles' ) ) {
			$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\LegacyLogExport();
		}
		// Pricing rules, blocks and iCal feeds: reported, not imported (writes nothing).
		$importers[] = new \RadiusTheme\RadiusHotelBookingTata\Import\Entities\ProDataReport();
		return $importers;
	}

	/**
	 * `rtbp_activity_actions`: the add-on's actions.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function activity_actions( $actions ): array {
		$actions                = (array) $actions;
		$actions['import.run'] = array(
			'kind'  => 'system',
			'label' => __( 'Ran the legacy data import', 'radius-hotel-booking-residencetata' ),
		);
		$actions['import.source'] = array(
			'kind'  => 'system',
			'label' => __( 'Changed the legacy import source', 'radius-hotel-booking-residencetata' ),
		);
		return $actions;
	}

	/**
	 * Load the add-on text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'radius-hotel-booking-residencetata', false, dirname( plugin_basename( RADIUS_HOTEL_BOOKING_TATA_FILE ) ) . '/languages' );
	}
}
