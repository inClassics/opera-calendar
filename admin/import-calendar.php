<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/IcsImporter.php';
require_once __DIR__ . '/../classes/CalendarFeedSync.php';

require_admin();

$sourceKey =
    'lnob-lydian';

$sourceName =
    'LNOB Lydian Calendar';

$syncService =
    new CalendarFeedSync(
        $pdo,
        'Europe/Riga'
    );

$message = '';
$error = '';
$result = null;

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    if (
        !hash_equals(
            csrf_token(),
            $_POST['csrf_token'] ?? ''
        )
    ) {
        $error =
            'Your session expired. Please refresh and try again.';
    } else {
        $action =
            $_POST['action'] ?? '';

        try {
            if (
                $action === 'save_feed'
            ) {
                $feedUrl =
                    trim(
                        $_POST['feed_url']
                            ?? ''
                    );

                if ($feedUrl === '') {
                    throw new RuntimeException(
                        'Enter the calendar feed URL.'
                    );
                }

                $syncService->saveFeedUrl(
                    $sourceKey,
                    $sourceName,
                    $feedUrl
                );

                $message =
                    'Calendar feed URL saved.';
            } elseif (
                $action === 'sync_feed'
            ) {
                $result =
                    $syncService->sync(
                        $sourceKey,
                        $sourceName
                    );

                $message =
                    sprintf(
                        'Sync complete: %d events, %d new, %d changed, %d moved, %d unchanged, %d currently missing from source. No availability was deleted.',
                        $result['total'],
                        $result['inserted'],
                        $result['changed'],
                        $result['moved'],
                        $result['unchanged'],
                        $result['missing']
                    );
            } elseif (
                $action === 'upload_ics'
            ) {
                if (
                    empty($_FILES['ics_file'])
                    ||
                    $_FILES['ics_file']['error']
                    !== UPLOAD_ERR_OK
                ) {
                    throw new RuntimeException(
                        'Please choose an .ics file.'
                    );
                }

                $icsText =
                    file_get_contents(
                        $_FILES['ics_file']['tmp_name']
                    );

                if (
                    !$icsText
                    ||
                    !str_contains(
                        $icsText,
                        'BEGIN:VCALENDAR'
                    )
                ) {
                    throw new RuntimeException(
                        'This does not appear to be a valid calendar file.'
                    );
                }

                $importer =
                    new IcsImporter(
                        $pdo,
                        'Europe/Riga'
                    );

                $result =
                    $importer->import(
                        $icsText,
                        $sourceKey,
                        $sourceName,
                        null,
                        true
                    );

                $message =
                    sprintf(
                        'Import complete: %d events, %d new, %d changed, %d moved, %d unchanged, %d currently missing. No availability was deleted.',
                        $result['total'],
                        $result['inserted'],
                        $result['changed'],
                        $result['moved'],
                        $result['unchanged'],
                        $result['missing']
                    );
            }
        } catch (Throwable $e) {

            $error =
                $e->getMessage();
        }
    }
}

$source =
    $syncService->sourceByKey(
        $sourceKey
    );

$feedUrl =
    $source['feed_url']
    ?? '';

$recentRuns = [];

try {
    if ($source) {
        $stmt =
            $pdo->prepare("
                SELECT *
                FROM calendar_sync_runs
                WHERE source_id = ?
                ORDER BY id DESC
                LIMIT 10
            ");

        $stmt->execute([
            (int) $source['id']
        ]);

        $recentRuns =
            $stmt->fetchAll();
    }
} catch (Throwable) {
    /*
    | Migration may not have been run yet.
    */
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendar sync · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

    <header class="topbar">
        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <h1 class="admin-title">
            Calendar sync
        </h1>

        <div class="account">
            <a href="../index.php">Schedule</a>
            <a href="users.php">Users</a>
            <a href="backfill-split-links.php">Link existing splits</a>
            <a href="../logout.php">Logout</a>
        </div>
    </header>

    <main class="admin-page">

        <?php if ($error): ?>
            <div class="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="success">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <h2>Automatic calendar feed</h2>

            <p>
                Save the private webcal/https feed here.
                It is stored in your database, not in the public Git repository.
            </p>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>">

                <input
                    type="hidden"
                    name="action"
                    value="save_feed">

                <label>
                    Calendar feed URL

                    <input
                        type="url"
                        name="feed_url"
                        value="<?= e($feedUrl) ?>"
                        placeholder="webcal://… or https://…"
                        style="width:100%;max-width:850px"
                        required>
                </label>

                <p>
                    <button
                        class="button"
                        type="submit">
                        Save feed URL
                    </button>
                </p>
            </form>

            <?php if ($feedUrl): ?>

                <form method="post">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="sync_feed">

                    <button
                        class="button primary"
                        type="submit">
                        Sync calendar now
                    </button>
                </form>

                <?php if (!empty($source['last_synced_at'])): ?>
                    <p class="muted">
                        Last sync:
                        <?= e($source['last_synced_at']) ?>
                        ·
                        <?= e($source['last_sync_status']) ?>
                    </p>
                <?php endif; ?>

            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Manual ICS upload</h2>

            <p class="muted">
                This remains available as a fallback. A full upload uses the same safe sync rules.
            </p>

            <form
                method="post"
                enctype="multipart/form-data">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>">

                <input
                    type="hidden"
                    name="action"
                    value="upload_ics">

                <p>
                    <input
                        type="file"
                        name="ics_file"
                        accept=".ics,text/calendar"
                        required>
                </p>

                <button
                    class="button"
                    type="submit">
                    Import ICS file
                </button>
            </form>
        </section>

        <?php if ($recentRuns): ?>
            <section class="panel">
                <h2>Recent syncs</h2>

                <div style="overflow:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Started</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>New</th>
                                <th>Changed</th>
                                <th>Moved</th>
                                <th>Missing</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($recentRuns as $run): ?>
                                <tr>
                                    <td><?= e($run['started_at']) ?></td>
                                    <td><?= e($run['status']) ?></td>
                                    <td><?= (int) $run['total_events'] ?></td>
                                    <td><?= (int) $run['inserted_events'] ?></td>
                                    <td><?= (int) $run['changed_events'] ?></td>
                                    <td><?= (int) $run['moved_events'] ?></td>
                                    <td><?= (int) $run['missing_events'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

    </main>

</body>

</html>