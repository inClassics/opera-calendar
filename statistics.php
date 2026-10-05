<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/Planning.php';
require_once __DIR__ . '/classes/Statistics.php';
require_admin();
$planning = new Planning($pdo);
$statistics = new Statistics($pdo);
$df = date('Y') . '-08-01';
$dt = date('Y-m-d');
try {
    $from = new DateTime($_GET['from'] ?? $df);
    $to = new DateTime($_GET['to'] ?? $dt);
} catch (Throwable) {
    $from = new DateTime($df);
    $to = new DateTime($dt);
}
if ($to < $from) [$from, $to] = [$to, $from];
$error = '';
$report = ['users' => [], 'totals' => []];
$favours = [];
$rep = [];
$weeks = [];
try {
    $report = $statistics->report($from, $to);
    $favours = $planning->favourBalances();
    $rep = $statistics->repertoire($from, $to);
    $weeks = $statistics->weeklyBalances($from, $to);
} catch (Throwable $e) {
    $error = 'Statistics could not be loaded. ' . $e->getMessage();
}
$users = $report['users'] ?? [];
$tot = $report['totals'] ?? [];
function sn(float|int|null $v): string
{
    if ($v === null) return '—';
    $n = (float)$v;
    return abs($n - round($n)) < .00001 ? (string)(int)round($n) : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
function sp(int $a, int $b): string
{
    return $b ? number_format($a / $b * 100, 1) . '%' : '—';
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Statistics · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/planning.css">
    <link rel="stylesheet" href="assets/css/statistics.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Statistics</h1>
        <div class="account"><a href="index.php">Calendar</a><a href="planning.php">Planning</a><a href="admin/index.php">Admin</a><a href="logout.php">Logout</a></div>
    </header>
    <main class="statistics-page">
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><form class="stats-filter statistics-filter"><label>From<input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>"></label><label>To<input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>"></label><button class="button">Apply</button><span class="statistics-range-note"><?= e($from->format('j M Y')) ?> – <?= e($to->format('j M Y')) ?></span></form>
        <section class="statistics-summary"><?php foreach ([['Assignments', $tot['calls'] ?? 0, '1 assignment = 1 call'], ['Rehearsals', $tot['rehearsal_calls'] ?? 0, ''], ['Performances', $tot['performance_calls'] ?? 0, ''], ['Rehearsal pts', sn($tot['rehearsal_points'] ?? 0), ''], ['Performance pts', sn($tot['performance_points'] ?? 0), ''], ['Against dot', $tot['against_preference'] ?? 0, '']] as $c): ?><div class="stat-card"><span><?= $c[0] ?></span><strong><?= $c[1] ?></strong><small><?= $c[2] ?></small></div><?php endforeach; ?></section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Workload overview</h2>
                <p>Actual assignments, separate rehearsal/performance workload, recent pressure and double-call days.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table workload-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Calls</th>
                            <th>Reh.</th>
                            <th>Perf.</th>
                            <th>Reh pts</th>
                            <th>Perf pts</th>
                            <th>Total pts</th>
                            <th>7d</th>
                            <th>14d</th>
                            <th>28d</th>
                            <th>Double days</th>
                            <th>Against dot</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($users as $u): ?><tr>
                                <th><?= e($u['name']) ?><small><?= e($u['position'] ?? '') ?></small></th>
                                <td class="stat-strong"><?= $u['calls'] ?></td>
                                <td><?= $u['rehearsal_calls'] ?></td>
                                <td><?= $u['performance_calls'] ?></td>
                                <td><?= sn($u['rehearsal_points']) ?></td>
                                <td><?= sn($u['performance_points']) ?></td>
                                <td class="stat-strong"><?= sn($u['weighted_points']) ?></td>
                                <td><?= $u['recent_7'] ?></td>
                                <td><?= $u['recent_14'] ?></td>
                                <td><?= $u['recent_28'] ?></td>
                                <td><?= $u['double_days'] ?></td>
                                <td class="<?= $u['against_preference'] ? 'stat-warning' : '' ?>"><?= $u['against_preference'] ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Weekly point equalisation</h2>
                <p>Actual Monday balances. Red is below that week's section average; green is above. Only musicians with an entered balance are included in the average.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table weekly-balance-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Type</th>
                            <th>Average</th><?php foreach ($users as $u): ?><th><?= e($u['name']) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($weeks as $w): foreach (['rehearsal' => 'Rehearsal', 'performance' => 'Performance'] as $type => $label): $x = $w[$type];
                                    $avg = $x[0]['average'] ?? null; ?><tr>
                                    <th><?= e((new DateTime($w['week_start']))->format('j M')) ?></th>
                                    <td><?= $label ?></td>
                                    <td class="stat-strong"><?= sn($avg) ?></td><?php foreach ($x as $v): ?><td class="<?= ($v['difference'] ?? 0) < 0 ? 'stat-behind' : (($v['difference'] ?? 0) > 0 ? 'stat-ahead' : '') ?>"><?= sn($v['points']) ?><?php if ($v['difference'] !== null): ?><small><?= ($v['difference'] >= 0 ? '+' : '') . sn($v['difference']) ?></small><?php endif; ?></td><?php endforeach; ?>
                                </tr><?php endforeach;
                                endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Repertoire / productions</h2>
                <p>Who played each show, how many rehearsals and performances, and when they last played it.</p>
            </div><?php if (!$rep): ?><p class="statistics-empty">No assigned repertoire in this range.</p><?php else: ?><div class="repertoire-grid"><?php foreach ($rep as $p): ?><article class="repertoire-card">
                            <header>
                                <div>
                                    <h3><?= e($p['title']) ?></h3><small><?= $p['calls'] ?> calls · <?= $p['rehearsals'] ?> reh. · <?= $p['performances'] ?> perf.</small>
                                </div><span>Last <?= e((new DateTime($p['last_played']))->format('j M')) ?></span>
                            </header>
                            <table class="statistics-table">
                                <thead>
                                    <tr>
                                        <th>Musician</th>
                                        <th>Reh.</th>
                                        <th>Perf.</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody><?php foreach ($p['users'] as $u): ?><tr>
                                            <th><?= e($u['name']) ?></th>
                                            <td><?= $u['rehearsals'] ?></td>
                                            <td><?= $u['performances'] ?></td>
                                            <td class="stat-strong"><?= $u['calls'] ?></td>
                                            <td><?= e((new DateTime($u['last_played']))->format('j M')) ?></td>
                                        </tr><?php endforeach; ?></tbody>
                            </table>
                        </article><?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Weekday / difficult-call pressure</h2>
                <p>Complete AM/PM distribution including Friday evenings and weekends.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table weekday-table">
                    <thead>
                        <tr>
                            <th>Musician</th><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><th><?= $d ?> AM</th>
                                <th><?= $d ?> PM</th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($users as $u): ?><tr>
                                <th><?= e($u['name']) ?></th><?php for ($d = 1; $d <= 7; $d++): ?><td><?= $u['weekday_periods'][$d]['morning'] ?></td>
                                    <td><?= $u['weekday_periods'][$d]['evening'] ?></td><?php endfor; ?>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Availability / assignment exceptions</h2>
                <p>Uses the preference snapshot captured when the assignment was made.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table exception-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Calls</th>
                            <th>On cross</th>
                            <th>Against dot</th>
                            <th>Against %</th>
                            <th>Unanswered</th>
                            <th>Uncertain</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($users as $u): ?><tr>
                                <th><?= e($u['name']) ?></th>
                                <td><?= $u['calls'] ?></td>
                                <td><?= $u['available_calls'] ?></td>
                                <td class="<?= $u['against_preference'] ? 'stat-warning' : '' ?>"><?= $u['against_preference'] ?></td>
                                <td><?= sp($u['against_preference'], $u['calls']) ?></td>
                                <td><?= $u['unanswered_calls'] ?></td>
                                <td><?= $u['uncertain_calls'] ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Favour ledger</h2>
                <p>Recorded swaps explicitly marked as favours.</p>
            </div><?php if (!$favours): ?><p class="statistics-empty">No swaps/favours recorded yet.</p><?php else: ?><div class="favour-grid"><?php foreach ($favours as $f): ?><div class="favour-item"><span><?= e($f['from_name']) ?> → <?= e($f['to_name']) ?></span><strong><?= $f['favour_count'] ?></strong></div><?php endforeach; ?></div><?php endif; ?>
        </section>
    </main>
</body>

</html>