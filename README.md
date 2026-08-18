# Section Schedule

Standalone PHP/MySQL application for morning/evening section availability.

## Existing local installation

You already have the database and users, so the easiest upgrade is:

1. Back up your current `section-schedule` folder.
2. Replace the PHP/CSS/JS files with this package.
3. **Keep your current DB credentials** by creating `config/config.php` from `config/config.example.php` and entering the same values you already use.
4. Do not re-run user-creation scripts.
5. In phpMyAdmin, check that `users`, `availability`, and `schedule_slots` exist. `schema.sql` uses `CREATE TABLE IF NOT EXISTS`, so it is safe for missing tables, but it does not alter an incompatible existing table.
6. Open `http://localhost:8080/section-schedule/`.

## Important paths

- `index.php` — schedule
- `admin/users.php` — add/edit users and priority
- `ajax/update-availability.php` — saves × / • / blank
- `ajax/update-activity.php` — admin-only activity editing
- `classes/Schedule.php` — schedule/data logic
- `classes/User.php` — user/data logic
- `assets/css/app.css` — layout
- `assets/js/app.js` — interactive behavior

## Security already included

- password hashing / verification
- prepared SQL statements
- server-side member/admin permission checks
- CSRF protection for AJAX and forms
- secure/HttpOnly/SameSite session cookies
- output escaping
- activity length validation
- session ID regeneration on login

When deploying publicly, also enable HTTPS, use a non-root MySQL account with only this database's permissions, turn off PHP error display, and use a strong production password for every account.
