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
$report = ['users' => []];
$rep = [];
$weeks = [];
$current = [];
$matrix = [];
$favours = [];
try {
    $activityFrom = clone $from;
    if ((int)$activityFrom->format('N') !== 1) $activityFrom->modify('monday this week');
    $activityTo = clone $to;
    if ((int)$activityTo->format('N') !== 7) $activityTo->modify('sunday this week');
    $activities = $planning->activities($activityFrom, $activityTo);
    $report = $statistics->report($from, $to, $activities);
    $rep = $statistics->repertoire($activities);
    $matrix = $statistics->repertoireMatrix($rep);
    $weeks = $statistics->weeklyBalances($from, $to, $activities);
    $current = $statistics->currentBalance($to, $activities);
    $favours = $planning->favourBalances();
} catch (Throwable $e) {
    $error = 'Statistics could not be loaded: ' . $e->getMessage();
}
$users = $report['users'] ?? [];

function sn(float|int|null $v): string
{
    if ($v === null) return '—';
    $n = (float)$v;
    return abs($n - round($n)) < .00001 ? (string)(int)round($n) : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
function signed(float|int|null $v): string
{
    if ($v === null) return '—';
    return ($v > 0 ? '+' : '') . sn($v);
}
function balanceClass($v): string
{
    return $v === null ? '' : ($v < -.0001 ? 'stat-behind' : ($v > .0001 ? 'stat-ahead' : 'stat-even'));
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
    <link rel="stylesheet" href="assets/css/statistics.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Statistics</h1>
        <div class="account"><a href="index.php">Calendar</a><a href="planning.php">Planning</a><a href="admin/index.php">Admin</a><a href="logout.php">Logout</a></div>
    </header>
    <main class="statistics-page">
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="stats-filter statistics-filter"><label>From<input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>"></label><label>To<input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>"></label><button class="button">Apply</button><span class="statistics-range-note"><?= e($from->format('j M Y')) ?> – <?= e($to->format('j M Y')) ?></span></form>

        <section class="leader-dashboard">
            <div class="statistics-section-heading dashboard-heading">
                <div><span class="eyebrow">NOW</span>
                    <h2>Who needs work?</h2>
                    <p>Monday actual + this week's Planning assignments = projected Sunday. Negative means the musician is behind the projected section average.</p>
                </div>
                <div class="week-chip"><?= !empty($current['week_start']) ? e((new DateTime($current['week_start']))->format('j M')) . ' – ' . e((new DateTime($current['week_end']))->format('j M')) : '—' ?></div>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table current-balance-table">
                    <thead>
                        <tr>
                            <th rowspan="2">Musician</th>
                            <th colspan="5">Rehearsal points</th>
                            <th colspan="5">Performance points</th>
                            <th rowspan="2">14d calls</th>
                        </tr>
                        <tr>
                            <th>Mon</th>
                            <th>Assigned</th>
                            <th>Sun</th>
                            <th>vs avg</th>
                            <th>Catch up</th>
                            <th>Mon</th>
                            <th>Assigned</th>
                            <th>Sun</th>
                            <th>vs avg</th>
                            <th>Catch up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $reh = $current['rehearsal'] ?? [];
                        $perf = $current['performance'] ?? [];
                        foreach ($users as $i => $u): $r = $reh[$i] ?? [];
                            $p = $perf[$i] ?? []; ?><tr>
                                <th><?= e($u['name']) ?><small><?= e($u['position'] ?? '') ?></small></th>
                                <td><?= sn($r['opening'] ?? null) ?></td>
                                <td><?= signed($r['assigned'] ?? 0) ?></td>
                                <td class="stat-strong"><?= sn($r['projected'] ?? null) ?></td>
                                <td class="<?= balanceClass($r['difference'] ?? null) ?>"><?= signed($r['difference'] ?? null) ?></td>
                                <td class="<?= ($r['catch_up'] ?? 0) > 0 ? 'catch-up' : '' ?>"><?= ($r['catch_up'] ?? null) !== null ? sn($r['catch_up']) : '—' ?></td>
                                <td><?= sn($p['opening'] ?? null) ?></td>
                                <td><?= signed($p['assigned'] ?? 0) ?></td>
                                <td class="stat-strong"><?= sn($p['projected'] ?? null) ?></td>
                                <td class="<?= balanceClass($p['difference'] ?? null) ?>"><?= signed($p['difference'] ?? null) ?></td>
                                <td class="<?= ($p['catch_up'] ?? 0) > 0 ? 'catch-up' : '' ?>"><?= ($p['catch_up'] ?? null) !== null ? sn($p['catch_up']) : '—' ?></td>
                                <td><?= $u['recent_14'] ?></td>
                            </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading"><span class="eyebrow">FAIRNESS</span>
                <h2>Workload overview</h2>
                <p>Only activities visible in the effective Planning schedule are counted. One assigned musician on one activity = one call.</p>
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
                <h2>Weekly movement</h2>
                <p>Historical Monday actual → assigned points → projected Sunday → difference from that week's projected average.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table weekly-movement">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Type</th>
                            <th>Projected avg</th><?php foreach ($users as $u): ?><th><?= e($u['name']) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weeks as $w): foreach (['rehearsal' => 'Reh.', 'performance' => 'Perf.'] as $type => $label): $entries = $w[$type];
                                $avg = $entries[0]['average'] ?? null; ?><tr>
                                    <th><?= e((new DateTime($w['week_start']))->format('j M')) ?></th>
                                    <td><?= $label ?></td>
                                    <td class="stat-strong"><?= sn($avg) ?></td><?php foreach ($entries as $x): ?><td class="<?= balanceClass($x['difference']) ?>"><span class="movement"><?= sn($x['opening']) ?> <b>+<?= sn($x['assigned']) ?></b> → <strong><?= sn($x['projected']) ?></strong></span><small><?= signed($x['difference']) ?></small></td><?php endforeach; ?>
                                </tr><?php endforeach;
                                endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading"><span class="eyebrow">REPERTOIRE</span>
                <h2>Production matrix</h2>
                <p>Total assigned calls. Small text shows rehearsals + performances. This makes repertoire imbalance visible at a glance.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table repertoire-matrix">
                    <thead>
                        <tr>
                            <th>Production</th><?php foreach ($matrix['users'] ?? [] as $u): ?><th><?= e($u['name']) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($matrix['rows'] ?? [] as $row): ?><tr>
                                <th><?= e($row['title']) ?></th><?php foreach ($matrix['users'] as $u): $x = $row['users'][(int)$u['id']]; ?><td class="<?= $x['calls'] === 0 ? 'zero-cell' : '' ?>"><strong><?= $x['calls'] ?></strong><small><?= $x['rehearsals'] ?>R + <?= $x['performances'] ?>P</small></td><?php endforeach; ?>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <h2>Production details</h2>
                <p>Exact rehearsal/performance distribution and last-played date.</p>
            </div><?php if (!$rep): ?><p class="statistics-empty">No assigned repertoire.</p><?php else: ?><div class="repertoire-grid"><?php foreach ($rep as $piece): ?><article class="repertoire-card">
                            <header>
                                <div>
                                    <h3><?= e($piece['title']) ?></h3><small><?= $piece['calls'] ?> calls · <?= $piece['rehearsals'] ?> reh. · <?= $piece['performances'] ?> perf.</small>
                                </div><span><?= e((new DateTime($piece['last_played']))->format('j M')) ?></span>
                            </header>
                            <table class="statistics-table">
                                <thead>
                                    <tr>
                                        <th>Musician</th>
                                        <th>R</th>
                                        <th>P</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody><?php foreach ($piece['users'] as $x): ?><tr>
                                            <th><?= e($x['name']) ?></th>
                                            <td><?= $x['rehearsals'] ?></td>
                                            <td><?= $x['performances'] ?></td>
                                            <td class="stat-strong"><?= $x['calls'] ?></td>
                                            <td><?= e((new DateTime($x['last_played']))->format('j M')) ?></td>
                                        </tr><?php endforeach; ?></tbody>
                            </table>
                        </article><?php endforeach; ?></div><?php endif; ?>
        </section>

        <section class="statistics-panel secondary-panel">
            <div class="statistics-section-heading">
                <h2>Weekday / difficult-call pressure</h2>
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

        <section class="statistics-panel secondary-panel">
            <div class="statistics-section-heading">
                <h2>Availability exceptions</h2>
                <p>Preference snapshot at the moment the assignment was made.</p>
            </div>
            <div class="statistics-scroll">
                <table class="statistics-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Calls</th>
                            <th>On cross</th>
                            <th>Against dot</th>
                            <th>Unanswered</th>
                            <th>Uncertain</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($users as $u): ?><tr>
                                <th><?= e($u['name']) ?></th>
                                <td><?= $u['calls'] ?></td>
                                <td><?= $u['available_calls'] ?></td>
                                <td class="<?= $u['against_preference'] ? 'stat-warning' : '' ?>"><?= $u['against_preference'] ?></td>
                                <td><?= $u['unanswered_calls'] ?></td>
                                <td><?= $u['uncertain_calls'] ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel secondary-panel">
            <div class="statistics-section-heading">
                <h2>Favour ledger</h2>
            </div><?php if (!$favours): ?><p class="statistics-empty">No swaps/favours recorded yet.</p><?php else: ?><div class="favour-grid"><?php foreach ($favours as $f): ?><div class="favour-item"><span><?= e($f['from_name']) ?> → <?= e($f['to_name']) ?></span><strong><?= $f['favour_count'] ?></strong></div><?php endforeach; ?></div><?php endif; ?>
        </section>
    </main>
</body>

</html>