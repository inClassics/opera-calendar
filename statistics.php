<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/Planning.php';
require_admin();
$p = new Planning($pdo);
$from = new DateTime($_GET['from'] ?? (date('Y') . '-08-01'));
$to = new DateTime($_GET['to'] ?? date('Y-m-d'));
$error = '';
try {
    $stats = $p->statistics($from, $to);
    $favours = $p->favourBalances();
} catch (Throwable $e) {
    $stats = [];
    $favours = [];
    $error = 'Run the Planning foundation migration first.';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Statistics · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/planning.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Statistics</h1>
        <div class="account"><a href="index.php">Calendar</a><a href="planning.php">Planning</a><a href="admin/index.php">Admin</a><a href="logout.php">Logout</a></div>
    </header>
    <main class="planning-page"><?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="stats-filter"><label>From<input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>"></label><label>To<input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>"></label><button class="button">Apply</button></form>
        <section class="panel">
            <h2>Workload & difficult calls</h2>
            <p class="muted">Counts are based on actual assignments. A dot only becomes a “worked against preference” event when that musician is actually assigned.</p>
            <div class="stats-table">
                <div class="stats-row stats-head"><b>Musician</b><b>Assigned</b><b>Fri evenings</b><b>Saturdays</b><b>Sundays</b><b>Against preference</b></div>
                <?php foreach ($stats as $s): ?><div class="stats-row"><strong><?= e($s['name']) ?></strong><span><?= (int)$s['total_assigned'] ?></span><span><?= (int)$s['friday_evenings'] ?></span><span><?= (int)$s['saturdays'] ?></span><span><?= (int)$s['sundays'] ?></span><span><?= (int)$s['forced_calls'] ?></span></div><?php endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <h2>Favour ledger</h2><?php if (!$favours): ?><p class="muted">No swaps/favours recorded yet.</p><?php else: ?><?php foreach ($favours as $f): ?><p><?= e($f['from_name']) ?> → <?= e($f['to_name']) ?>: <strong><?= (int)$f['favour_count'] ?></strong></p><?php endforeach; ?><?php endif; ?>
        </section>
    </main>
</body>

</html>