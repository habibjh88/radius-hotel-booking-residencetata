=== Radius Hotel Booking - Residence TATA ===
Contributors: radiustheme
Tags: hotel booking
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Residence TATA's own features for Radius Hotel Booking. Requires Radius Hotel Booking and Radius Hotel Booking Pro.

== Description ==

Features built for Residence TATA only, on top of Radius Hotel Booking and
Radius Hotel Booking Pro: the Côte d'Ivoire payroll taxes and contributions
(CNPS, IS, CN, IGR) for Pro's payroll, the migration from the previous hotel
system, and the hotel's local settings (time zone, phone, payment methods).

== Installation ==

1. Install and activate Radius Hotel Booking and Radius Hotel Booking Pro.
2. Copy this plugin into `wp-content/plugins/`, run `composer install` and `bun install && bun run build`.
3. Activate Radius Hotel Booking - Residence TATA.

== Changelog ==

= 1.0.0 ( UNRELEASE ) =
First version of the Residence TATA add-on, loading on top of Radius Hotel Booking Pro.
Administrators are warned when the site time zone is not Africa/Abidjan.
New command wp radius-hotel-booking import:legacy reports the legacy data; --dry-run writes nothing.
New Data import screen for administrators: dry run first, then import the legacy data.
The import brings room types, floors and rooms across; matching existing records are linked.
The import brings rate plans (merged by window), prices per room type and dated prices across.
The import reports old pricing rules, blocked dates and calendar sync to set up by hand.
The import brings customers across as guests, merging duplicates, with their notes and bans.
The import brings staff files and contracts across; staff without a phone are flagged.
The import carries staff permissions over and stores the old plain-text PINs hashed.
The import brings bookings still open at cutover across, with their prices and payments.
Fixed: an import cutover given with a time (2026-04-12 12:00) matched nothing.
Fixed: imported payments showed in today's sales instead of the day they were paid.
Fixed: a booking changed or cancelled in the old system after import was not reported.
Fixed: a booking billed to another name could be filed silently under someone else's guest.
Fixed: a ban was lost when a banned customer merged into the same guest.
The import reports merged guests with different names, and bookings on rooms now closed.
An e-mail shared by several different people in the old system is no longer kept.
A dry run skips bookings whose rooms are not imported yet, instead of failing them.
Fixed: two imports started at once could create records twice.
Administrators are warned when the server lacks the PHP bcmath extension that payroll needs.
New Settings → Payroll: work accident rate (2–5 %) and the yearly vacation quota.
New Payroll menu and permissions for running, settling and voiding payroll and approving time off.
New pay schedules: weekly, every two weeks, twice a month or monthly, with the next pay period.
New work shifts with hours for each day of the week, overnight shifts included.
New Pay tab on employee files: pay schedule, work shift, salary or hourly rate.
Employees' additional pay: overtime, time off, sick and vacation pay, bonus, transport, gratification.
Employees' recurring deductions and garnishments, with what remains to recover.
New pay runs: run payroll, edit lines, finalise, settle, void, revert, with the full calculation.
New statutory contributions per month and authority (CNPS, DGI), with payments recorded and voided.
New payslips for finalised pay runs, to print or as PDF, with year-to-date totals.
New Payroll and Statutory contributions reports, by month and employee, with spreadsheet export.
Past bookings stay readable on WooCommerce orders after the old plugins are switched off.
The import keeps the old activity log once as a CSV file for the cutover backup.
Time off requests with approval, overlap checks and a yearly vacation quota per employee.
New Payroll → Time off tab: approval queue, history, balances and a request form.
New My payroll page for staff: download own payslips, request and cancel time off.
Legacy import brings payroll over: schedules, shifts, pay, deductions, time off, past pay runs.
Pay runs recalculate on finalising, so monthly deductions and quotas are never taken twice.
A pay run is refused when its days overlap another run; staff already paid are left out.
Staff who leave during a pay period still get their last pay in that run.
One-off pay items now count in the hotel's cost, reports and DGI remittance.
Payroll, time-off and import date and time fields now use modern pickers.
Phone fields start on Côte d'Ivoire; old 8-digit Ivorian mobile numbers are still converted.
Wave and Orange Money are offered as payment methods on a fresh install.
Payroll moved to Radius Hotel Booking Pro; this add-on keeps the Côte d'Ivoire tax calculator.
New Settings → Côte d'Ivoire payroll tab for the work accident rate.
The import dry run no longer runs out of memory on shops with thousands of customers.
The dashboard shows Orange Money as OM and Cash at the desk as Cash.
Seniority bonus is now 1 % per year since hiring (max 25 %), rising each work anniversary.
The seniority bonus rule is set up automatically for every employee.
Staff photos from the old dashboard are imported and linked to each staff file.
Fixed: importing after activation now brings the old work accident rate into Social contributions.

The hotel's existing customers sign in and book with their saved ID and phone.
Old customers are found by phone in every form, including old 8-digit numbers.
The import skips old customer accounts that never booked and hold no details.
Imported guests are linked to their old accounts, which get the Hotel Guest role.
Guests created from old orders now keep the identity document given at checkout.
After an import, each guest's stay count and last stay are recounted from their bookings.
The Language menu offers English and French, keeping each person's choice from the old dashboard.
Complete French translation, included in the add-on.
The hotel and Food Menu dashboards now link to each other from their menus.
Côte d'Ivoire payroll settings now list the built-in CNPS and DGI rates, read-only.
Legacy import now carries over the work accident insurance rate from the old Tax Info.
Fixed: add-on not loading when WordPress loads it after Radius Hotel Booking Pro.
Data import screen now uses the full page width.
Wave and Orange Money payments now require their transaction ID.
Staff must open their cash register before taking a payment (Pro cash register).
Settings → Staff starts with the hotel's departments and job titles.
Staff departments and job titles are cleaned of old spellings, on import and once on update.
Côte d'Ivoire payroll: CNPS rates now in Settings → Payroll → Social contributions, editable.
Côte d'Ivoire payroll: IS, CN and IGR rates and scales are now editable settings.
Import fills Social contributions with the old CNPS rates and work accident rate.
Settings refuse a work accident rate outside 2–5 % and an empty CN or IGR scale.
