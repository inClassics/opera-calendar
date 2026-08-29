<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

require_admin();

$result = null;
$error = '';

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
            'Your session expired. Refresh the page and try again.';
    } else {
        try {
            $schedule =
                new Schedule($pdo);

            $result =
                $schedule
                ->backfillSplitCalendarLinks();
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link existing split events · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

    <header class="topbar">
        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <h1 class="admin-title">
            Link existing split events
        </h1>

        <div class="account">
            <a href="../index.php">Schedule</a>
            <a href="import-calendar.php">Calendar sync</a>
            <a href="../logout.php">Logout</a>
        </div>
    </header>

    <main class="admin-page">

        <?php if ($error): ?>
            <div class="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <h2>One-time safe migration</h2>

            <p>
                This compares existing split-event text with imported events
                on the same date and period. It links only exact, unambiguous matches.
                It does not delete or change any availability.
            </p>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>">

                <button
                    class="button primary"
                    type="submit">
                    Link existing split events
                </button>
            </form>

            <?php if ($result): ?>
                <div class="success" style="margin-top:16px">
                    Linked:
                    <?= (int) $result['linked'] ?>
                    · Ambiguous:
                    <?= (int) $result['ambiguous'] ?>
                    · Unmatched:
                    <?= (int) $result['unmatched'] ?>
                </div>
            <?php endif; ?>
        </section>

    </main>
</body>

</html>