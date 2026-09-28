<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/Planning.php';
require_admin();

$planning = new Planning($pdo);
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}
$from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$to = (clone $from)->modify('last day of this month');
$prev = (clone $from)->modify('-1 month');
$next = (clone $from)->modify('+1 month');
$error = '';
try {
    $activities = $planning->activities($from, $to);
    $pieces = $planning->activePieces();
} catch (Throwable $e) {
    $activities = [];
    $pieces = [];
    $error = 'Planning is not ready yet. Run database/2026-09-29-planning-foundation.sql and the Piece-link migration first.';
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Planning · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/planning.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <nav class="month-navigation"><a class="month-arrow" href="?year=<?= $prev->format('Y') ?>&month=<?= $prev->format('n') ?>">‹</a>
            <h1><?= e($from->format('F Y')) ?> Planning</h1><a class="month-arrow" href="?year=<?= $next->format('Y') ?>&month=<?= $next->format('n') ?>">›</a>
        </nav>
        <div class="account"><a href="index.php">Calendar</a><a href="statistics.php">Statistics</a><a href="admin/index.php">Admin</a><a href="logout.php">Logout</a></div>
    </header>
    <main class="planning-page">
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
        <div class="planning-toolbar">
            <input id="planning-search" type="search" placeholder="Search activity or Piece…">
            <label class="planning-check"><input id="filter-unconnected" type="checkbox"> Unconnected only</label>
            <label class="planning-check"><input id="filter-shortage" type="checkbox"> Problems only</label>
        </div>
        <div class="planning-list">
            <div class="planning-row planning-head">
                <div>Date</div>
                <div>Activity</div>
                <div>Piece</div>
                <div>Need</div>
                <div>Available</div>
                <div>Assigned</div>
                <div>Status</div>
            </div>
            <?php foreach ($activities as $a):
                $need = $a['effective_required'];
                $problem = $need !== null && ((int)$a['available_count'] < $need || (int)$a['assigned_count'] < $need);
                $search = strtolower(($a['title'] ?? '') . ' ' . ($a['title'] ?? ''));
            ?>
                <div class="planning-row planning-activity <?= $problem ? 'has-problem' : '' ?>" data-search="<?= e($search) ?>" data-unconnected="<?= $a['piece_id'] ? '0' : '1' ?>" data-problem="<?= $problem ? '1' : '0' ?>" data-source-type="<?= e($a['source_type']) ?>" data-source-id="<?= (int)$a['source_id'] ?>">
                    <div class="planning-date"><strong><?= e((new DateTime($a['schedule_date']))->format('D j M')) ?></strong><span><?= e(ucfirst($a['period'])) ?></span></div>
                    <div class="planning-title"><strong><?= e($a['title']) ?></strong><span><?= e($a['source_type']) ?> #<?= (int)$a['source_id'] ?><?= ($a['sync_status'] ?? '') === 'missing' ? ' · missing from Lydian' : '' ?></span></div>
                    <div><select class="planning-piece">
                            <option value="">No Piece</option><?php foreach ($pieces as $p): ?><option value="<?= (int)$p['id'] ?>" data-default="<?= (int)$p['default_basses'] ?>" <?= $a['piece_id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div><input class="planning-required" type="number" min="1" max="7" placeholder="<?= $a['default_basses'] ?? '—' ?>" value="<?= e($a['required_basses_override'] !== null ? (string)$a['required_basses_override'] : '') ?>"></div>
                    <div class="planning-number"><?= (int)$a['available_count'] ?></div>
                    <div class="planning-number assigned-count"><?= (int)$a['assigned_count'] ?></div>
                    <div><button type="button" class="planning-manage button <?= $problem ? 'planning-warning' : '' ?>"><?= $problem ? 'Manage ⚠' : 'Manage' ?></button></div>
                </div><?php endforeach; ?>
        </div>
    </main>
    <div class="planning-drawer-backdrop" hidden></div>
    <aside class="planning-drawer" hidden><button class="planning-drawer-close" type="button">×</button>
        <h2>Activity staffing</h2>
        <div class="planning-drawer-meta"></div>
        <div class="planning-members"></div>
    </aside>
    <script>
        window.PLANNING = {
            csrfToken: <?= json_encode($csrf) ?>
        };
    </script>
    <script src="assets/js/planning.js"></script>
</body>

</html>