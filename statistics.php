<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/Planning.php';
require_once __DIR__ . '/classes/Statistics.php';

require_admin();

$planning = new Planning($pdo);
$statistics = new Statistics($pdo);

$defaultFrom = date('Y') . '-08-01';
$defaultTo = date('Y-m-d');

try {
    $from = new DateTime($_GET['from'] ?? $defaultFrom);
    $to = new DateTime($_GET['to'] ?? $defaultTo);
} catch (Throwable $e) {
    $from = new DateTime($defaultFrom);
    $to = new DateTime($defaultTo);
}

if ($to < $from) {
    [$from, $to] = [$to, $from];
}

$error = '';
$report = [
    'users' => [],
    'totals' => [],
    'assignments' => [],
];
$favours = [];

try {
    $report = $statistics->report($from, $to);
    $favours = $planning->favourBalances();
} catch (Throwable $e) {
    $error = 'Statistics could not be loaded. Check that the Planning assignment tables are installed.';
}

$users = $report['users'] ?? [];
$totals = $report['totals'] ?? [];

$weekdayNames = [
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
    7 => 'Sun',
];

function statNumber(float|int $value): string
{
    $number = (float)$value;

    if (abs($number - round($number)) < 0.00001) {
        return (string)(int)round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function statPercent(int $part, int $total): string
{
    if ($total <= 0) {
        return '—';
    }

    return number_format(($part / $total) * 100, 1) . '%';
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

        <div class="account">
            <a href="index.php">Calendar</a>
            <a href="planning.php">Planning</a>
            <a href="admin/index.php">Admin</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <main class="statistics-page">
        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="stats-filter statistics-filter">
            <label>
                From
                <input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>">
            </label>

            <label>
                To
                <input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>">
            </label>

            <button class="button">Apply</button>

            <span class="statistics-range-note">
                <?= e($from->format('j M Y')) ?> – <?= e($to->format('j M Y')) ?>
            </span>
        </form>

        <section class="statistics-summary">
            <div class="stat-card">
                <span>Total assignments</span>
                <strong><?= (int)($totals['calls'] ?? 0) ?></strong>
                <small>1 musician assigned to 1 activity = 1 call</small>
            </div>

            <div class="stat-card">
                <span>Rehearsal calls</span>
                <strong><?= (int)($totals['rehearsal_calls'] ?? 0) ?></strong>
                <small><?= statPercent((int)($totals['rehearsal_calls'] ?? 0), (int)($totals['calls'] ?? 0)) ?> of calls</small>
            </div>

            <div class="stat-card">
                <span>Performance calls</span>
                <strong><?= (int)($totals['performance_calls'] ?? 0) ?></strong>
                <small><?= statPercent((int)($totals['performance_calls'] ?? 0), (int)($totals['calls'] ?? 0)) ?> of calls</small>
            </div>

            <div class="stat-card">
                <span>Against preference</span>
                <strong><?= (int)($totals['against_preference'] ?? 0) ?></strong>
                <small>Assigned while availability was a dot</small>
            </div>

            <div class="stat-card">
                <span>Raw assigned points</span>
                <strong><?= statNumber($totals['raw_points'] ?? 0) ?></strong>
                <small>Event points before musician multiplier</small>
            </div>

            <div class="stat-card">
                <span>Weighted assigned points</span>
                <strong><?= statNumber($totals['weighted_points'] ?? 0) ?></strong>
                <small>Event points × musician multiplier</small>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <div>
                    <h2>Workload overview</h2>
                    <p>
                        These figures count actual assignments, not availability.
                        A musician assigned to two separate activities on the same day has two calls.
                    </p>
                </div>
            </div>

            <div class="statistics-scroll">
                <table class="statistics-table workload-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Calls</th>
                            <th>Reh.</th>
                            <th>Perf.</th>
                            <th>Untyped</th>
                            <th>Raw pts</th>
                            <th>Weighted pts</th>
                            <th>Against dot</th>
                            <th>Against %</th>
                            <th>On cross</th>
                            <th>Unanswered</th>
                            <th>Uncertain</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <th>
                                    <?= e($user['name']) ?>
                                    <?php if (!empty($user['position'])): ?>
                                        <small><?= e($user['position']) ?></small>
                                    <?php endif; ?>
                                </th>
                                <td class="stat-strong"><?= (int)$user['calls'] ?></td>
                                <td><?= (int)$user['rehearsal_calls'] ?></td>
                                <td><?= (int)$user['performance_calls'] ?></td>
                                <td><?= (int)$user['untyped_calls'] ?></td>
                                <td><?= statNumber($user['raw_points']) ?></td>
                                <td><?= statNumber($user['weighted_points']) ?></td>
                                <td class="<?= (int)$user['against_preference'] > 0 ? 'stat-warning' : '' ?>">
                                    <?= (int)$user['against_preference'] ?>
                                </td>
                                <td><?= statPercent((int)$user['against_preference'], (int)$user['calls']) ?></td>
                                <td><?= (int)$user['available_calls'] ?></td>
                                <td><?= (int)$user['unanswered_calls'] ?></td>
                                <td><?= (int)$user['uncertain_calls'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th>Section total</th>
                            <td><?= (int)($totals['calls'] ?? 0) ?></td>
                            <td><?= (int)($totals['rehearsal_calls'] ?? 0) ?></td>
                            <td><?= (int)($totals['performance_calls'] ?? 0) ?></td>
                            <td><?= (int)($totals['untyped_calls'] ?? 0) ?></td>
                            <td><?= statNumber($totals['raw_points'] ?? 0) ?></td>
                            <td><?= statNumber($totals['weighted_points'] ?? 0) ?></td>
                            <td><?= (int)($totals['against_preference'] ?? 0) ?></td>
                            <td><?= statPercent((int)($totals['against_preference'] ?? 0), (int)($totals['calls'] ?? 0)) ?></td>
                            <td><?= (int)($totals['available_calls'] ?? 0) ?></td>
                            <td><?= (int)($totals['unanswered_calls'] ?? 0) ?></td>
                            <td><?= (int)($totals['uncertain_calls'] ?? 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <div>
                    <h2>Calls by weekday and time of day</h2>
                    <p>
                        Every cell is the number of assignments in that weekday/period.
                        For example, Monday AM counts all musicians assigned to Monday-morning activities in the selected range.
                    </p>
                </div>
            </div>

            <div class="statistics-scroll">
                <table class="statistics-table weekday-table">
                    <thead>
                        <tr>
                            <th rowspan="2">Musician</th>

                            <?php foreach ($weekdayNames as $weekday): ?>
                                <th colspan="2"><?= e($weekday) ?></th>
                            <?php endforeach; ?>

                            <th rowspan="2">Total</th>
                        </tr>

                        <tr>
                            <?php foreach ($weekdayNames as $weekday): ?>
                                <th>AM</th>
                                <th>PM</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <th><?= e($user['name']) ?></th>

                                <?php for ($day = 1; $day <= 7; $day++): ?>
                                    <td><?= (int)$user['weekday_periods'][$day]['morning'] ?></td>
                                    <td><?= (int)$user['weekday_periods'][$day]['evening'] ?></td>
                                <?php endfor; ?>

                                <td class="stat-strong"><?= (int)$user['calls'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th>Section total</th>

                            <?php for ($day = 1; $day <= 7; $day++): ?>
                                <td><?= (int)($totals['weekday_periods'][$day]['morning'] ?? 0) ?></td>
                                <td><?= (int)($totals['weekday_periods'][$day]['evening'] ?? 0) ?></td>
                            <?php endfor; ?>

                            <td><?= (int)($totals['calls'] ?? 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <div>
                    <h2>Weekend and evening pressure</h2>
                    <p>
                        A compact view of the less convenient parts of the week.
                        These are derived from the same Monday–Sunday assignment matrix above.
                    </p>
                </div>
            </div>

            <div class="statistics-scroll">
                <table class="statistics-table pressure-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Fri AM</th>
                            <th>Fri PM</th>
                            <th>Sat AM</th>
                            <th>Sat PM</th>
                            <th>Sun AM</th>
                            <th>Sun PM</th>
                            <th>Weekend total</th>
                            <th>Evening total</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user):
                            $weekend =
                                (int)$user['weekday_periods'][6]['morning']
                                + (int)$user['weekday_periods'][6]['evening']
                                + (int)$user['weekday_periods'][7]['morning']
                                + (int)$user['weekday_periods'][7]['evening'];

                            $evenings = 0;
                            for ($day = 1; $day <= 7; $day++) {
                                $evenings += (int)$user['weekday_periods'][$day]['evening'];
                            }
                        ?>
                            <tr>
                                <th><?= e($user['name']) ?></th>
                                <td><?= (int)$user['weekday_periods'][5]['morning'] ?></td>
                                <td><?= (int)$user['weekday_periods'][5]['evening'] ?></td>
                                <td><?= (int)$user['weekday_periods'][6]['morning'] ?></td>
                                <td><?= (int)$user['weekday_periods'][6]['evening'] ?></td>
                                <td><?= (int)$user['weekday_periods'][7]['morning'] ?></td>
                                <td><?= (int)$user['weekday_periods'][7]['evening'] ?></td>
                                <td class="stat-strong"><?= $weekend ?></td>
                                <td class="stat-strong"><?= $evenings ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <div>
                    <h2>Availability / assignment exceptions</h2>
                    <p>
                        “Against dot” means the musician was assigned while their saved preference snapshot was unavailable.
                        Unanswered and uncertain calls remain separate rather than being treated as dots.
                    </p>
                </div>
            </div>

            <div class="statistics-scroll">
                <table class="statistics-table exception-table">
                    <thead>
                        <tr>
                            <th>Musician</th>
                            <th>Total calls</th>
                            <th>On cross</th>
                            <th>Against dot</th>
                            <th>Against %</th>
                            <th>Unanswered</th>
                            <th>Uncertain</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <th><?= e($user['name']) ?></th>
                                <td><?= (int)$user['calls'] ?></td>
                                <td><?= (int)$user['available_calls'] ?></td>
                                <td class="<?= (int)$user['against_preference'] > 0 ? 'stat-warning' : '' ?>">
                                    <?= (int)$user['against_preference'] ?>
                                </td>
                                <td><?= statPercent((int)$user['against_preference'], (int)$user['calls']) ?></td>
                                <td><?= (int)$user['unanswered_calls'] ?></td>
                                <td><?= (int)$user['uncertain_calls'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="statistics-panel">
            <div class="statistics-section-heading">
                <div>
                    <h2>Favour ledger</h2>
                    <p>Recorded assignment swaps that were explicitly marked as favours.</p>
                </div>
            </div>

            <?php if (!$favours): ?>
                <p class="statistics-empty">No swaps/favours recorded yet.</p>
            <?php else: ?>
                <div class="favour-grid">
                    <?php foreach ($favours as $f): ?>
                        <div class="favour-item">
                            <span><?= e($f['from_name']) ?> → <?= e($f['to_name']) ?></span>
                            <strong><?= (int)$f['favour_count'] ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>