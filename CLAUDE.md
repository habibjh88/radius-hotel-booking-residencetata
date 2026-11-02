# CLAUDE.md — Radius Hotel Booking, Residence TATA add-on

Client add-on for Residence TATA, on top of the free plugin **Radius Hotel Booking**
(`../radius-hotel-booking`) and **Radius Hotel Booking Pro** (`../radius-hotel-booking-pro`).

**The project is managed from the free repo.** Specs, roadmap and tracker, ADRs, conventions and
the `/next-module`, `/next-task` and `/module-status` commands all live there:

- `../radius-hotel-booking/docs/README.md`: start here
- `../radius-hotel-booking/docs/project/decisions.md`: ADR-014 (three plugins), ADR-015
  (extension points), ADR-016 (wordpress.org rules)
- `../radius-hotel-booking/docs/modules/Mxx-*.md`: tasks tagged `[Client]` are built here
  (M15 payroll and HR, parts of M18 migration and go-live)

Run the commands from the free repo. They switch into this repo for `[Client]` tasks.

## Rules

- **Only what is specific to Residence TATA goes here.** Anything another hotel could use belongs
  in free or Pro, added there first as a seam.
- **Reuse free and Pro, never copy them.** PHP: extend the free `Abstracts`, use its `Container`,
  `Schema`, router, `ApiResponse` and middleware; use Pro's features through
  `rtbp_pro_feature_active()`. JS: import `@rtbp/ui` / `@rtbp/lib` / `@tanstack/react-query`
  (webpack externals to `window.rtbp`) and extend the app with the `rtbp.*` filters.
- **Guard every free and Pro symbol** (`class_exists()` / `function_exists()` / `defined()`). A
  missing symbol must degrade gracefully, never fatal.
- The add-on boots on Pro's `rtbp_pro_loaded`, so free and Pro are fully booted first. Without
  either, it shows a notice and does nothing else.
- Namespace `RadiusTheme\RadiusHotelBookingTata`, prefix `rtbp_tata_` (options, meta, hooks),
  table names `rtbp_table_prefix()` + `tata_…` through the free `rtbp_migration_classes` filter.
- Text domain `radius-hotel-booking-residencetata`. Changelog in this repo's `readme.txt`
  (follow the global changelog rule).

## Commands

```bash
composer install          # autoloader (+ phpcs dev deps)
bun install && bun run build
../radius-hotel-booking/vendor/bin/phpcs --standard=phpcs.xml radius-hotel-booking-residencetata.php includes
```

Local test site: `food-menu.local` has the three plugins symlinked. With `WP_DEBUG` on, an
administrator can open `#/tata/runtime` in the Radius Hotel Booking admin to check the layers.

## Layout

```
radius-hotel-booking-residencetata.php   bootstrap: constants, autoload, Plugin::instance()
includes/Plugin.php                      boots on Pro's `rtbp_pro_loaded`; dependency notice
includes/Assets/Assets.php               enqueues build/admin.js after the free and Pro bundles
src/admin/                               JS entry (rtbp.* filters)
```
