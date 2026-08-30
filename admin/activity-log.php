<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

require_admin();

$logger =
    new ActivityLogger(
        $pdo
    );

$entries =
    $logger->recent(
        300
    );

function activityValue(
    ?string $value
): string {
    if (
        $value === null
        ||
        $value === ''
    ) {
        return '—';
    }

    $decoded =
        json_decode(
            $value,
            true
        );

    if (
        !is_array(
            $decoded
        )
    ) {
        return $value;
    }

    $parts = [];

    foreach (
        $decoded
        as $key => $item
    ) {
        if (
            is_bool(
                $item
            )
        ) {
            $item =
                $item
                ? 'Yes'
                : 'No';
        }

        if (
            $item === ''
        ) {
            $item =
                'blank';
        }

        $parts[] =
            $key
            .
            ': '
            .
            $item;
    }

    return
        implode(
            ', ',
            $parts
        );
}

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Activity log · <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/app.css">

</head>

<body>

    <header class="topbar">

        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <h1 class="admin-title">
            Activity log
        </h1>

        <div class="account">

            <a href="../index.php">
                Schedule
            </a>

            <a href="import-calendar.php">
                Import calendar
            </a>

            <a href="users.php">
                Users
            </a>

            <a href="../logout.php">
                Logout
            </a>

        </div>

    </header>

    <main class="admin-page">

        <section class="panel">

            <h2>
                Recent changes
            </h2>

            <p class="muted">
                Showing the most recent
                <?= count($entries) ?>
                logged changes.
            </p>

            <div style="overflow:auto">

                <table
                    style="
                    width:100%;
                    border-collapse:collapse;
                    min-width:950px;
                ">

                    <thead>

                        <tr>

                            <th>
                                Time
                            </th>

                            <th>
                                Changed by
                            </th>

                            <th>
                                Member
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Period
                            </th>

                            <th>
                                Change
                            </th>

                            <th>
                                Before
                            </th>

                            <th>
                                After
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach (
                            $entries
                            as $entry
                        ): ?>

                            <tr>

                                <td>
                                    <?= e(
                                        $entry['created_at']
                                    ) ?>
                                </td>

                                <td>

                                    <?= e(
                                        $entry['actor_name']
                                            ?? 'System'
                                    ) ?>

                                    <?php if (
                                        (
                                            $entry['actor_role']
                                            ?? ''
                                        )
                                        === 'admin'
                                    ): ?>

                                        <strong>
                                            (Admin)
                                        </strong>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= e(
                                        $entry['affected_name']
                                            ?? '—'
                                    ) ?>

                                </td>

                                <td>

                                    <?= e(
                                        $entry['schedule_date']
                                            ?? '—'
                                    ) ?>

                                </td>

                                <td>

                                    <?= e(
                                        $entry['period']
                                            ?? '—'
                                    ) ?>

                                </td>

                                <td>

                                    <?= e(
                                        $entry['description']
                                    ) ?>

                                </td>

                                <td>

                                    <?= e(
                                        activityValue(
                                            $entry['old_value']
                                        )
                                    ) ?>

                                </td>

                                <td>

                                    <?= e(
                                        activityValue(
                                            $entry['new_value']
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if (
                            !$entries
                        ): ?>

                            <tr>

                                <td
                                    colspan="8"
                                    class="muted">
                                    No activity has been logged yet.
                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</body>

</html>