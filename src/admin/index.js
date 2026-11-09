/**
 * Residence TATA add-on admin entry.
 *
 * Loads after the free admin app and after Pro's bundle (see
 * includes/Assets/Assets.php), so `window.rtbp` and Pro's filters exist. Like
 * Pro, the add-on has no screens outside the free app: everything is added
 * through the `rtbp.*` filters (ADR-015).
 */
import { lazy } from 'react';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { Banknote, DatabaseZap, UtensilsCrossed } from 'lucide-react';

/*
 * M18: the legacy data import, administrators only (the server checks too).
 */
if ( window.rtbp_tata?.can_import ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-residencetata/import',
		( routes ) => [
			...routes,
			{
				path: '/tata/import',
				group: 'system',
				label: __(
					'Data import',
					'radius-hotel-booking-residencetata'
				),
				description: __(
					'Bring the data across from the old hotel plugins',
					'radius-hotel-booking-residencetata'
				),
				icon: DatabaseZap,
				element: lazy( () => import( './import/ImportScreen' ) ),
			},
		]
	);
}

/*
 * M15: Côte d'Ivoire payroll — the DGI tax figures the Ivorian calculator
 * uses (saved with `payroll.tax_settings`); its CNPS contributions are Pro's
 * Settings → Payroll → Social contributions. Payroll itself is Pro's.
 */
if ( window.rtbp_tata?.ivorian_payroll ) {
	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-residencetata/payroll',
		( sections ) => [
			...sections,
			{
				key: 'tata_payroll',
				label: __(
					'Côte d’Ivoire payroll',
					'radius-hotel-booking-residencetata'
				),
				description: __(
					'IS, CN and IGR rates and scales',
					'radius-hotel-booking-residencetata'
				),
				icon: Banknote,
				accessKey: 'payroll.tax_settings',
				Component: lazy( () =>
					import( './payroll/IvorianPayrollSection' )
				),
			},
		]
	);
}

/*
 * Developer check that the client layer sits on top of free and Pro, only
 * where the free plugin shows its own UI kit (WP_DEBUG, administrators).
 */
if ( window.radius_hotel_booking_param?.dev_ui ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-residencetata/runtime-check',
		( routes ) => [
			...routes,
			{
				path: '/tata/runtime',
				hidden: true,
				label: 'Residence TATA runtime check',
				description:
					'The client add-on, loaded after free and Pro (WP_DEBUG only)',
				element: lazy( () => import( './dev/RuntimeCheck' ) ),
			},
		]
	);
}

/*
 * R1-T18: the Food Menu front-end dashboard, one click from the hotel's menu
 * ("Food Order and Inventory", as on the old dashboard). The PHP side sends
 * the URL only when Food Menu Pro is active and this user may open it; the
 * Food Menu dashboard links back (DashboardLinks).
 */
const foodMenuUrl = window.rtbp_tata?.food_menu_url || '';
if ( foodMenuUrl ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-residencetata/food-menu',
		( routes ) => [
			...routes,
			{
				path: '/food-menu',
				navOnly: true,
				href: foodMenuUrl,
				group: 'apps',
				label: __(
					'Food Order and Inventory',
					'radius-hotel-booking-residencetata'
				),
				icon: UtensilsCrossed,
			},
		]
	);
}
