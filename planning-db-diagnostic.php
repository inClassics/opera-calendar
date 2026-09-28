<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_admin();

header('Content-Type: text/html; charset=utf-8');

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND CONSTRAINT_NAME = ?
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->execute([$table, $constraint]);
    return (int) $stmt->fetchColumn() > 0;
}

function row_count_safe(PDO $pdo, string $table): ?int
{
    if (!table_exists($pdo, $table)) {
        return null;
    }

    // Table names below are hard-coded by this diagnostic, never user supplied.
    return (int) $pdo->query("SELECT COUNT(*) FROM `" . $table . "`")->fetchColumn();
}

function test_query(PDO $pdo, string $label, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        $stmt->fetch(PDO::FETCH_ASSOC);
        return ['label' => $label, 'ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

$tables = [
    'pieces',
    'calendar_events',
    'schedule_slots',
    'schedule_split_events',
    'availability',
    'split_availability',
    'users',
    'activity_assignments',
    'assignment_history',
    'assignment_swaps',
];

$pieceLinkColumns = [
    'calendar_events' => ['piece_id', 'required_basses_override'],
    'schedule_slots' => ['piece_id', 'required_basses_override'],
    'schedule_split_events' => ['piece_id', 'required_basses_override'],
];

$assignmentColumns = [
    'activity_assignments' => [
        'id',
        'source_type',
        'source_id',
        'user_id',
        'assigned_by',
        'preference_at_assignment',
        'uncertain_at_assignment',
        'assigned_at',
        'updated_at'
    ],
    'assignment_history' => [
        'id',
        'source_type',
        'source_id',
        'user_id',
        'action',
        'preference_snapshot',
        'uncertain_snapshot',
        'schedule_date',
        'period',
        'is_friday_evening',
        'is_saturday',
        'is_sunday',
        'changed_by',
        'created_at'
    ],
    'assignment_swaps' => [
        'id',
        'source_type',
        'source_id',
        'from_user_id',
        'to_user_id',
        'counts_as_favour',
        'note',
        'created_by',
        'created_at'
    ],
];

$tests = [];

$tests[] = test_query(
    $pdo,
    'Planning activity query – calendar_events + Pieces',
    "SELECT ce.id, ce.schedule_date, ce.period, ce.summary,
            ce.piece_id, ce.required_basses_override,
            p.title AS piece_title, p.default_basses
     FROM calendar_events ce
     LEFT JOIN pieces p ON p.id = ce.piece_id
     LIMIT 1"
);

$tests[] = test_query(
    $pdo,
    'Planning activity query – schedule_slots + Pieces',
    "SELECT ss.id, ss.schedule_date, ss.period, ss.activity,
            ss.piece_id, ss.required_basses_override,
            p.title AS piece_title, p.default_basses
     FROM schedule_slots ss
     LEFT JOIN pieces p ON p.id = ss.piece_id
     LIMIT 1"
);

$tests[] = test_query(
    $pdo,
    'Planning activity query – split events + Pieces',
    "SELECT se.id, se.schedule_date, se.period,
            se.piece_id, se.required_basses_override,
            p.title AS piece_title, p.default_basses
     FROM schedule_split_events se
     LEFT JOIN pieces p ON p.id = se.piece_id
     LIMIT 1"
);

$tests[] = test_query(
    $pdo,
    'Statistics assignment query',
    "SELECT aa.id, aa.source_type, aa.source_id, aa.user_id,
            aa.preference_at_assignment
     FROM activity_assignments aa
     LIMIT 1"
);

$tests[] = test_query(
    $pdo,
    'Statistics history query',
    "SELECT ah.id, ah.schedule_date, ah.period,
            ah.is_friday_evening, ah.is_saturday, ah.is_sunday
     FROM assignment_history ah
     LIMIT 1"
);

$tests[] = test_query(
    $pdo,
    'Favour ledger query',
    "SELECT s.id, s.from_user_id, s.to_user_id, s.counts_as_favour
     FROM assignment_swaps s
     LIMIT 1"
);

$databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

$allGood = true;
foreach ($tables as $table) {
    if (!table_exists($pdo, $table)) {
        $allGood = false;
    }
}
foreach ($pieceLinkColumns as $table => $columns) {
    foreach ($columns as $column) {
        if (!column_exists($pdo, $table, $column)) {
            $allGood = false;
        }
    }
}
foreach ($assignmentColumns as $table => $columns) {
    foreach ($columns as $column) {
        if (!column_exists($pdo, $table, $column)) {
            $allGood = false;
        }
    }
}
foreach ($tests as $test) {
    if (!$test['ok']) {
        $allGood = false;
    }
}

function status_badge(bool $ok): string
{
    return $ok
        ? '<span class="ok">OK</span>'
        : '<span class="bad">MISSING / ERROR</span>';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Planning database diagnostic</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f6f7;
            color: #1d2935;
            margin: 0
        }

        main {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px
        }

        h1 {
            margin: 0 0 8px
        }

        h2 {
            margin-top: 28px
        }

        .card {
            background: #fff;
            border: 1px solid #dce1e5;
            border-radius: 10px;
            padding: 18px;
            margin: 14px 0
        }

        .ok {
            display: inline-block;
            background: #e8f5ec;
            color: #1f6b39;
            padding: 3px 7px;
            border-radius: 5px;
            font-weight: 700
        }

        .bad {
            display: inline-block;
            background: #fdeceb;
            color: #a3312d;
            padding: 3px 7px;
            border-radius: 5px;
            font-weight: 700
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff
        }

        th,
        td {
            text-align: left;
            padding: 9px;
            border-bottom: 1px solid #e4e7e9;
            vertical-align: top
        }

        th {
            background: #f3f5f6
        }

        code,
        pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace
        }

        pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            background: #f7f8f9;
            border: 1px solid #e1e4e6;
            padding: 10px;
            border-radius: 6px
        }

        .summary-good {
            border-left: 5px solid #2f8b50
        }

        .summary-bad {
            border-left: 5px solid #b94740
        }

        .note {
            color: #61707d
        }

        a {
            color: #275f8c
        }
    </style>
</head>

<body>
    <main>
        <h1>Planning database diagnostic</h1>
        <p class="note">Read-only diagnostic. It does not create, update, or delete any database data.</p>

        <div class="card <?= $allGood ? 'summary-good' : 'summary-bad' ?>">
            <strong><?= $allGood ? 'Database structure looks complete.' : 'One or more required database items are missing or a query is failing.' ?></strong>
            <p>Database: <code><?= e($databaseName) ?></code><br>
                MySQL/MariaDB: <code><?= e($serverVersion) ?></code></p>
        </div>

        <h2>Required tables</h2>
        <table>
            <tr>
                <th>Table</th>
                <th>Status</th>
                <th>Rows</th>
            </tr>
            <?php foreach ($tables as $table): $exists = table_exists($pdo, $table); ?>
                <tr>
                    <td><code><?= e($table) ?></code></td>
                    <td><?= status_badge($exists) ?></td>
                    <td><?= $exists ? row_count_safe($pdo, $table) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>Piece-link columns</h2>
        <table>
            <tr>
                <th>Table</th>
                <th>Column</th>
                <th>Status</th>
            </tr>
            <?php foreach ($pieceLinkColumns as $table => $columns): ?>
                <?php foreach ($columns as $column): $exists = column_exists($pdo, $table, $column); ?>
                    <tr>
                        <td><code><?= e($table) ?></code></td>
                        <td><code><?= e($column) ?></code></td>
                        <td><?= status_badge($exists) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </table>

        <h2>Planning foundation columns</h2>
        <table>
            <tr>
                <th>Table</th>
                <th>Column</th>
                <th>Status</th>
            </tr>
            <?php foreach ($assignmentColumns as $table => $columns): ?>
                <?php foreach ($columns as $column): $exists = column_exists($pdo, $table, $column); ?>
                    <tr>
                        <td><code><?= e($table) ?></code></td>
                        <td><code><?= e($column) ?></code></td>
                        <td><?= status_badge($exists) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </table>

        <h2>Exact query tests</h2>
        <?php foreach ($tests as $test): ?>
            <div class="card">
                <strong><?= e($test['label']) ?></strong>
                <div style="margin-top:8px"><?= status_badge($test['ok']) ?></div>
                <?php if (!$test['ok']): ?>
                    <pre><?= e($test['error']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <strong>What to send me</strong>
            <p>Open this page on the same server/database where Planning is failing, then copy the sections marked <b>MISSING / ERROR</b> and the error text under <b>Exact query tests</b>. That will tell us exactly which repair SQL is needed.</p>
        </div>

        <p><a href="planning.php">← Planning</a> &nbsp; <a href="statistics.php">Statistics</a></p>
    </main>
</body>

</html>