<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Schedule.php';

require_login();

$userRepository = new User($pdo);
$scheduleRepository = new Schedule($pdo);

$members = $userRepository->activeUsers();
$context = $scheduleRepository->monthContext((int) ($_GET['year'] ?? date('Y')), (int) ($_GET['month'] ?? date('n')));
$days = $scheduleRepository->daysForMonth($context['firstDay'], $context['lastDay']);
$availability = $scheduleRepository->availabilityForMonth($context['firstDay'], $context['lastDay']);
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($context['monthTitle']) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>

        <nav class="month-navigation" aria-label="Month navigation">
            <a class="month-arrow" href="?year=<?= $context['previousMonth']->format('Y') ?>&month=<?= $context['previousMonth']->format('n') ?>" aria-label="Previous month">‹</a>
            <h1><?= e($context['monthTitle']) ?></h1>
            <a class="month-arrow" href="?year=<?= $context['nextMonth']->format('Y') ?>&month=<?= $context['nextMonth']->format('n') ?>" aria-label="Next month">›</a>
        </nav>

        <div class="account">
            <span><?= e($_SESSION['name']) ?></span>
            <?php if (is_admin()): ?>

                <a href="admin/import-calendar.php">

                    Import calendar

                </a>

                <a href="admin/users.php">

                    Users

                </a>

            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">
        <div class="legend"><span class="available-mark">×</span> available <span class="unavailable-mark">•</span> unavailable <span class="muted">blank = unanswered</span></div>

        <div class="schedule-wrap">
            <table class="schedule-table">
                <thead>
                    <tr class="group-header">
                        <th colspan="<?= count($members) + 1 ?>">Evening</th>
                        <th rowspan="2" class="date-head">Date</th>
                        <th colspan="<?= count($members) + 1 ?>">Morning</th>
                    </tr>
                    <tr class="names-header">
                        <?php foreach ($members as $member): ?>
                            <th class="member-head"><span><?= e($member['name']) ?></span></th>
                        <?php endforeach; ?>
                        <th class="activity-head">Activity</th>
                        <th class="activity-head">Activity</th>
                        <?php foreach ($members as $member): ?>
                            <th class="member-head"><span><?= e($member['name']) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($days as $day): ?>
                        <tr class="<?= $day['weekday'] === 'Sunday' ? 'week-end' : '' ?>">
                            <?php foreach ($members as $member):
                                $status = $availability[$day['date']]['evening'][(int) $member['id']] ?? '';
                                $editable = is_admin() || (int) $member['id'] === current_user_id();
                            ?>
                                <td class="availability-td">
                                    <button type="button"
                                        class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>"
                                        data-user-id="<?= (int) $member['id'] ?>"
                                        data-date="<?= e($day['date']) ?>"
                                        data-period="evening"
                                        data-status="<?= e($status) ?>"
                                        <?= !$editable ? 'disabled' : '' ?>></button>
                                </td>
                            <?php endforeach; ?>

                            <td class="activity-cell evening-activity <?= is_admin() ? 'activity-editable' : '' ?>" data-date="<?= e($day['date']) ?>" data-period="evening"><?= e($day['evening']) ?></td>

                            <td class="date-cell">
                                <div class="day-number"><?= (int) $day['day'] ?></div>
                                <div class="weekday"><?= e($day['weekday_short']) ?></div>
                            </td>

                            <td class="activity-cell morning-activity <?= is_admin() ? 'activity-editable' : '' ?>" data-date="<?= e($day['date']) ?>" data-period="morning"><?= e($day['morning']) ?></td>

                            <?php foreach ($members as $member):
                                $status = $availability[$day['date']]['morning'][(int) $member['id']] ?? '';
                                $editable = is_admin() || (int) $member['id'] === current_user_id();
                            ?>
                                <td class="availability-td">
                                    <button type="button"
                                        class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>"
                                        data-user-id="<?= (int) $member['id'] ?>"
                                        data-date="<?= e($day['date']) ?>"
                                        data-period="morning"
                                        data-status="<?= e($status) ?>"
                                        <?= !$editable ? 'disabled' : '' ?>></button>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        window.SECTION_SCHEDULE = <?= json_encode(['csrfToken' => $csrf], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/js/app.js"></script>
</body>

</html>