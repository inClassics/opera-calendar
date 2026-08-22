#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/Applications/MAMP/htdocs/section-schedule}"

if [ ! -d "$PROJECT_DIR" ]; then
  echo "Project directory not found: $PROJECT_DIR"
  exit 1
fi

PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Applying refactor to: $PROJECT_DIR"

cp "$PACKAGE_DIR/index.php" "$PROJECT_DIR/index.php"
cp "$PACKAGE_DIR/.gitignore" "$PROJECT_DIR/.gitignore"

mkdir -p "$PROJECT_DIR/classes"
mkdir -p "$PROJECT_DIR/includes"
mkdir -p "$PROJECT_DIR/views"
mkdir -p "$PROJECT_DIR/ajax"
mkdir -p "$PROJECT_DIR/assets/js"
mkdir -p "$PROJECT_DIR/assets/css"

cp "$PACKAGE_DIR/classes/PointCalculator.php" "$PROJECT_DIR/classes/PointCalculator.php"
cp "$PACKAGE_DIR/includes/schedule_helpers.php" "$PROJECT_DIR/includes/schedule_helpers.php"

cp "$PACKAGE_DIR/views/desktop-schedule.php" "$PROJECT_DIR/views/desktop-schedule.php"
cp "$PACKAGE_DIR/views/mobile-schedule.php" "$PROJECT_DIR/views/mobile-schedule.php"

cp "$PACKAGE_DIR/assets/js/core.js" "$PROJECT_DIR/assets/js/core.js"
cp "$PACKAGE_DIR/assets/js/app.js" "$PROJECT_DIR/assets/js/app.js"
cp "$PACKAGE_DIR/assets/js/availability.js" "$PROJECT_DIR/assets/js/availability.js"
cp "$PACKAGE_DIR/assets/js/split-events.js" "$PROJECT_DIR/assets/js/split-events.js"
cp "$PACKAGE_DIR/assets/js/activity-points.js" "$PROJECT_DIR/assets/js/activity-points.js"

cp "$PACKAGE_DIR/assets/css/desktop.css" "$PROJECT_DIR/assets/css/desktop.css"
cp "$PACKAGE_DIR/assets/css/activity-points.css" "$PROJECT_DIR/assets/css/activity-points.css"

for file in "$PACKAGE_DIR"/ajax/*.php; do
  cp "$file" "$PROJECT_DIR/ajax/$(basename "$file")"
done

rm -f "$PROJECT_DIR/views/desktop-schedule-v3.php"
rm -f "$PROJECT_DIR/assets/css/desktop-v3.css"
rm -f "$PROJECT_DIR/assets/js/mobile.js"
rm -f "$PROJECT_DIR/.DS_Store"

echo
echo "Refactor applied."
echo "Your config/ directory was not touched."
echo
echo "Run:"
echo "  find \"$PROJECT_DIR\" -name '*.php' -print0 | xargs -0 -n1 php -l"
echo
echo "Then hard-refresh the browser and test the schedule."
