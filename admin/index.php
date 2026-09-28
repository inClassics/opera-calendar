<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Administration · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Administration</h1>
        <div class="account"><a href="../index.php">Schedule</a><a href="../logout.php">Logout</a></div>
    </header>
    <main class="admin-page">
        <section class="panel">
            <h2>Administration</h2>
            <p class="muted">Manage repertoire, members and calendar data.</p>
            <div class="admin-grid">
                <a href="pieces.php"><strong>Pieces</strong><span>Reusable repertoire and default bass requirements</span></a>
                <a href="users.php"><strong>Users</strong><span>Members, roles, positions and point multipliers</span></a>
                <a href="import-calendar.php"><strong>Calendar</strong><span>Calendar feed, sync and manual ICS import</span></a>
                <a href="activity-log.php"><strong>Activity log</strong><span>Recent changes made in the system</span></a>
            </div>
        </section>
    </main>
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(240px, 1fr));
            gap: 14px;
            margin-top: 18px
        }

        .admin-grid a {
            display: grid;
            gap: 8px;
            min-height: 120px;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--soft);
            color: var(--text)
        }

        .admin-grid a:hover {
            border-color: var(--accent);
            text-decoration: none;
            background: #fff
        }

        .admin-grid strong {
            font-size: 20px;
            color: var(--accent)
        }

        .admin-grid span {
            color: var(--muted);
            line-height: 1.4
        }

        @media(max-width:700px) {
            .admin-grid {
                grid-template-columns: 1fr
            }
        }
    </style>
</body>

</html>