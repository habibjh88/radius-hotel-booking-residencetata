#!/bin/bash
#
# Build a distributable plugin zip into dist/.
#
#   bun run package
#
# Installs production dependencies, builds the webpack bundles, and archives
# only the files the plugin needs at runtime — never node_modules, tests or
# build configs.

set -e

cd "$(dirname "$0")"
cd ..

SLUG="radius-hotel-booking-residencetata"

BLUE_BOLD='\033[1;34m'
GREEN_BOLD='\033[1;32m'
COLOR_RESET='\033[0m'

status () { echo -e "\n${BLUE_BOLD}$1${COLOR_RESET}\n"; }
success () { echo -e "\n${GREEN_BOLD}$1${COLOR_RESET}\n"; }

status "Installing dependencies…"
bun install --frozen-lockfile
composer install --no-dev --optimize-autoloader

status "Building assets…"
bun run build

# Source maps are dev-only.
find build -name "*.map" -type f -delete

status "Creating archive…"
# Stage and zip inside dist/ (git-ignored) so the plugin root stays clean.
DIST="dist"
mkdir -p "$DIST"
rm -rf "$DIST/$SLUG" "$DIST/$SLUG.zip"
STAGE="$DIST/$SLUG"
mkdir "$STAGE"

cp "$SLUG.php" index.php readme.txt composer.json "$STAGE/"
cp -r includes languages build vendor "$STAGE/"

# No hidden files (.DS_Store, .gitkeep …).
find "$STAGE" -name '.*' -not -name '.' -exec rm -rf {} + 2>/dev/null || true

# Languages: the template (.pot) and the bundled translations (.po/.mo/.l10n.php, fr_FR, R1);
# editor backups are left out. Loco can still override them (ADR-019, amended).
find "$STAGE/languages" -type f \( -name '*.po~' -o -name '*-backup-*' \) -delete 2>/dev/null || true

# Zip from inside dist/ so the archive root is "$SLUG/", as WordPress expects.
( cd "$DIST" && zip -rq "$SLUG.zip" "$SLUG/" )
rm -rf "$STAGE"

success "Built $DIST/$SLUG.zip"
