# Safe calendar synchronization update

This package is designed to sit on top of the existing split-event management build.

## What it changes

- Imported calendar events keep their identity by `source_uid`.
- Existing `availability` and `split_availability` are never deleted by a calendar sync.
- Split events can link to `calendar_events.id`.
- If a linked source event changes time/date/period, the split event follows it while its availability stays attached.
- If a source event disappears, it is marked `missing` rather than deleted.
- Missing events are shown with a warning symbol.
- Manual split events remain manual.
- Editing the text of a linked split event creates a local `activity_override`, so future syncs do not overwrite that custom text.
- A one-time backfill tool links existing split rows only when there is one exact matching imported calendar event.

## Installation order

1. Back up the database.
2. Run `sql/calendar-sync-migration.sql` once in phpMyAdmin.
3. Replace/add the files in this package.
4. Open `admin/backfill-split-links.php` once and run the linker.
5. Open `admin/import-calendar.php`.
6. Paste the private webcal/https URL and save it.
7. Click **Sync calendar now**.
8. Verify the schedule and sync report before automating it.

## Important

Do NOT hard-code the private feed URL into the public Git repository. The updated admin page stores it in the database.

Automatic cron can be added after the manual "Sync calendar now" flow has been tested successfully.
