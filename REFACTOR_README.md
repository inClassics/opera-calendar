# Opera Calendar / Section Schedule refactor

This replacement package was built against the current repository state:

- repository: `inClassics/opera-calendar`
- baseline commit: `1a7f2f8cdda0bace9ac855826c570add28927b08`
- commit message: `before rebuild`

## Why this refactor

The current build works, but responsibilities have accumulated in the wrong places:

- `index.php` contains page loading, view helpers and the entire points calculator.
- `app.js` contains edit mode, availability and the activity editor.
- `split-events.js` duplicates availability rendering/saving for split rows.
- `mobile.js` only duplicates the mobile options bridge.
- every AJAX endpoint repeats the same bootstrap/auth/CSRF setup.
- `desktop-v3.css` contains multiple copies of the point badge rules.
- the page references `$csrf` without assigning it in the current `index.php`,
  causing the current "Invalid security token" error.
- `schema.sql` in the repository is older than the live database and should not
  be treated as a current migration.

## New responsibilities

### PHP

- `index.php`
  - page orchestration only
  - loads data
  - invokes the points calculator
  - generates the CSRF token
  - includes desktop/mobile views

- `classes/PointCalculator.php`
  - one source of truth for rehearsal/performance totals
  - physical morning/evening period is independent of point category
  - split activities use split availability
  - unsplit activities use slot availability

- `includes/schedule_helpers.php`
  - formatting and view helpers shared by desktop/mobile

- `views/desktop-schedule.php`
  - desktop presentation only

- `views/mobile-schedule.php`
  - mobile presentation only

- `ajax/_bootstrap.php`
  - shared authentication, CSRF and validation helpers

### JavaScript

- `core.js`
  - shared edit-state
  - one POST/JSON/CSRF implementation
  - shared availability rendering
  - shared floating-menu positioning

- `app.js`
  - edit-mode toggle
  - rich activity editor only

- `availability.js`
  - normal and split availability in one implementation
  - normal and split uncertainty in one implementation
  - desktop and mobile option menus in one implementation

- `split-events.js`
  - split/add/edit/delete/merge activity management only

- `activity-points.js`
  - integer point values
  - rehearsal/performance type
  - debounced AJAX save
  - no page reload for point increments

## Files to remove after copying this package

Delete these old files because their responsibility was merged elsewhere:

- `views/desktop-schedule-v3.php`
- `assets/css/desktop-v3.css`
- `assets/js/mobile.js`

Do not keep the old `-v3` desktop view/CSS beside the renamed files.

## Files intentionally left unchanged

Keep the current repository versions of:

- `classes/Schedule.php`
- `classes/User.php`
- `classes/IcsImporter.php`
- `classes/CalendarFeedSync.php`
- `includes/auth.php`
- `includes/functions.php`
- admin pages
- login/logout
- `assets/css/app.css`
- `assets/css/mobile.css`
- `assets/css/split-events.css`
- `assets/css/activity-points.css`

The refactor uses their existing public methods/data structure rather than
rewriting the working calendar synchronization layer.

## Installation

1. Commit/back up your current project.
2. Copy the contents of this package over the project root.
3. Delete:
   - `views/desktop-schedule-v3.php`
   - `assets/js/mobile.js`
4. Do **not** delete your local `config/` folder.
5. Hard-refresh the browser.
6. Test:
   - edit mode starts OFF
   - normal availability
   - uncertainty
   - split availability
   - rich text activity editing
   - split/add/delete/merge
   - point increment and R/P save
   - next week totals
   - next month totals
7. Only after this passes, commit the refactor.

## Database

This refactor does not require a database migration.

Your live database already contains the fields used here:
`point_value`, `point_type`, split-event links/overrides, birthdays/name days,
and the existing calendar synchronization columns.

Do not replace the live database with the old repository `schema.sql`.
