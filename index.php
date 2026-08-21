<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Schedule.php';

require_login();

/*
|--------------------------------------------------------------------------
| Special days
|--------------------------------------------------------------------------
*/

function getSpecialDayClass(array $member, string $date): string
{
    $classes = [];

    $currentMonthDay = date(
        'm-d',
        strtotime($date)
    );

    if (!empty($member['birthday'])) {

        $birthdayMonthDay = date(
            'm-d',
            strtotime($member['birthday'])
        );

        if ($birthdayMonthDay === $currentMonthDay) {
            $classes[] = 'birthday-cell';
        }
    }

    if (!empty($member['name_day'])) {

        $nameDayMonthDay = date(
            'm-d',
            strtotime($member['name_day'])
        );

        if ($nameDayMonthDay === $currentMonthDay) {
            $classes[] = 'name-day-cell';
        }
    }

    return implode(' ', $classes);
}

/*
|--------------------------------------------------------------------------
| Repositories
|--------------------------------------------------------------------------
*/

$userRepository = new User($pdo);
$scheduleRepository = new Schedule($pdo);

/*
|--------------------------------------------------------------------------
| Season
|--------------------------------------------------------------------------
*/

$seasonStartDate = new DateTime('2026-08-01');

/*
|--------------------------------------------------------------------------
| Users
|--------------------------------------------------------------------------
*/

$members = $userRepository->activeUsers();
$membersReversed = array_reverse($members);

/*
|--------------------------------------------------------------------------
| Starting points
|--------------------------------------------------------------------------
*/

$runningEveningPoints = [];
$runningMorningPoints = [];

foreach ($members as $member) {

    $userId = (int) $member['id'];

    $runningEveningPoints[$userId] =
        (int) ($member['evening_starting_points'] ?? 0);

    $runningMorningPoints[$userId] =
        (int) ($member['morning_starting_points'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Month
|--------------------------------------------------------------------------
*/

$context = $scheduleRepository->monthContext(
    (int) ($_GET['year'] ?? date('Y')),
    (int) ($_GET['month'] ?? date('n'))
);

$days = $scheduleRepository->daysForMonth(
    $context['firstDay'],
    $context['lastDay']
);

/*
|--------------------------------------------------------------------------
| Availability displayed on current calendar
|--------------------------------------------------------------------------
*/

$availability = $scheduleRepository->availabilityForMonth(
    $context['firstDay'],
    $context['lastDay']
);

/*
|--------------------------------------------------------------------------
| Availability used for cumulative point calculation
|--------------------------------------------------------------------------
|
| Always load from season start, so opening another month does not reset
| the points.
|
*/

$pointsAvailability = $scheduleRepository->availabilityForMonth(
    $seasonStartDate,
    $context['lastDay']
);

/*
|--------------------------------------------------------------------------
| Calculate cumulative Evening weekly points
|--------------------------------------------------------------------------
*/

$weeklyEveningPoints = [];

$calculationDate = clone $seasonStartDate;

while ($calculationDate <= $context['lastDay']) {

    $date =
        $calculationDate->format('Y-m-d');

    $weekdayNumber =
        (int) $calculationDate->format('N');

    /*
    |--------------------------------------------------------------------------
    | Store total at beginning of Monday
    |--------------------------------------------------------------------------
    |
    | Monday's own points have NOT yet been added.
    |
    */

    if ($weekdayNumber === 1) {
        $weeklyEveningPoints[$date] =
            $runningEveningPoints;
    }

    /*
    |--------------------------------------------------------------------------
    | Evening point value
    |--------------------------------------------------------------------------
    |
    | Monday-Friday = 1
    | Saturday-Sunday = 2
    |
    */

    $pointsForCross =
        $weekdayNumber >= 6
        ? 2
        : 1;

    foreach ($members as $member) {

        $userId =
            (int) $member['id'];

        $item =
            $pointsAvailability[$date]['evening'][$userId]
            ?? null;

        if (
            is_array($item)
            &&
            ($item['status'] ?? '') === 'available'
        ) {
            $runningEveningPoints[$userId] +=
                $pointsForCross;
        }
    }

    $calculationDate->modify('+1 day');
}

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
*/

$csrf = csrf_token();

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        <?= e($context['monthTitle']) ?> · <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/app.css">

</head>

<body>

    <header class="topbar">

        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <nav
            class="month-navigation"
            aria-label="Month navigation">

            <a
                class="month-arrow"
                href="?year=<?= $context['previousMonth']->format('Y') ?>&month=<?= $context['previousMonth']->format('n') ?>"
                aria-label="Previous month">
                ‹
            </a>

            <h1>
                <?= e($context['monthTitle']) ?>
            </h1>

            <a
                class="month-arrow"
                href="?year=<?= $context['nextMonth']->format('Y') ?>&month=<?= $context['nextMonth']->format('n') ?>"
                aria-label="Next month">
                ›
            </a>

        </nav>

        <div class="account">

            <span>
                <?= e($_SESSION['name']) ?>
            </span>

            <button
                type="button"
                class="button edit-mode-toggle"
                id="edit-mode-toggle"
                aria-pressed="false">
                Edit schedule
            </button>

            <?php if (is_admin()): ?>

                <a href="admin/import-calendar.php">
                    Import calendar
                </a>

                <a href="admin/users.php">
                    Users
                </a>

            <?php endif; ?>

            <a href="logout.php">
                Logout
            </a>

        </div>

    </header>

    <main class="page">

        <div class="legend">

            <span class="available-mark">×</span>
            available

            <span class="unavailable-mark">•</span>
            unavailable

            <span class="muted">
                ? = uncertain
            </span>

            <span class="muted">
                blank = unanswered
            </span>

        </div>

        <div class="schedule-wrap">

            <table class="schedule-table">

                <thead>

                    <tr class="group-header">

                        <th colspan="<?= count($members) + 1 ?>">
                            Evening
                        </th>

                        <th
                            rowspan="2"
                            class="date-head">
                            Date
                        </th>

                        <th colspan="<?= count($members) + 1 ?>">
                            Morning
                        </th>

                    </tr>

                    <tr class="names-header">

                        <!-- Evening names -->

                        <?php foreach ($membersReversed as $member): ?>

                            <?php

                            $isCurrentUser =
                                (int) $member['id'] === current_user_id();

                            ?>

                            <th class="member-head <?= $isCurrentUser ? 'current-user-column' : '' ?>">

                                <span>
                                    <?= e($member['name']) ?>
                                </span>

                            </th>

                        <?php endforeach; ?>

                        <th class="activity-head">
                            Activity
                        </th>

                        <th class="activity-head">
                            Activity
                        </th>

                        <!-- Morning names -->

                        <?php foreach ($membersReversed as $member): ?>

                            <?php

                            $isCurrentUser =
                                (int) $member['id'] === current_user_id();

                            ?>

                            <th class="member-head <?= $isCurrentUser ? 'current-user-column' : '' ?>">

                                <span>
                                    <?= e($member['name']) ?>
                                </span>

                            </th>

                        <?php endforeach; ?>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($days as $day): ?>

                        <!-- Weekly points row -->

                        <?php if ($day['weekday'] === 'Monday'): ?>

                            <tr class="points-row">

                                <!-- Evening points -->

                                <?php foreach ($membersReversed as $member): ?>

                                    <?php

                                    $userId =
                                        (int) $member['id'];

                                    $isCurrentUser =
                                        $userId === current_user_id();

                                    $weekPoints =
                                        $weeklyEveningPoints[$day['date']][$userId]
                                        ?? $member['evening_starting_points']
                                        ?? 0;

                                    ?>

                                    <td class="points-cell <?= $isCurrentUser ? 'current-user-column' : '' ?>">

                                        <?= (int) $weekPoints ?>

                                    </td>

                                <?php endforeach; ?>

                                <td class="points-label">
                                    Points
                                </td>

                                <td class="points-week">
                                    Week
                                </td>

                                <td class="points-label">
                                    Points
                                </td>

                                <!-- Morning points -->

                                <?php foreach ($membersReversed as $member): ?>

                                    <?php

                                    $userId =
                                        (int) $member['id'];

                                    $isCurrentUser =
                                        $userId === current_user_id();

                                    ?>

                                    <td class="points-cell <?= $isCurrentUser ? 'current-user-column' : '' ?>">

                                        <?= (int) ($runningMorningPoints[$userId] ?? 0) ?>

                                    </td>

                                <?php endforeach; ?>

                            </tr>

                        <?php endif; ?>

                        <!-- Normal schedule row -->

                        <tr class="<?= $day['weekday'] === 'Sunday' ? 'week-end' : '' ?>">

                            <!-- Evening availability -->

                            <?php foreach ($membersReversed as $member): ?>

                                <?php

                                $userId =
                                    (int) $member['id'];

                                $availabilityItem =
                                    $availability[$day['date']]['evening'][$userId]
                                    ?? [
                                        'status' => '',
                                        'uncertain' => false,
                                    ];

                                $status =
                                    $availabilityItem['status'] ?? '';

                                $uncertain =
                                    !empty($availabilityItem['uncertain']);

                                $editable =
                                    is_admin()
                                    ||
                                    $userId === current_user_id();

                                $isCurrentUser =
                                    $userId === current_user_id();

                                /*
                            |--------------------------------------------------------------------------
                            | IMPORTANT
                            |--------------------------------------------------------------------------
                            |
                            | Calculate the special-day class separately for THIS exact
                            | user and THIS exact date.
                            |
                            */

                                $specialDayClass =
                                    getSpecialDayClass(
                                        $member,
                                        $day['date']
                                    );

                                ?>

                                <td class="availability-td <?= $isCurrentUser ? 'current-user-column' : '' ?> <?= e($specialDayClass) ?>">

                                    <button
                                        type="button"
                                        class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>"
                                        data-user-id="<?= $userId ?>"
                                        data-date="<?= e($day['date']) ?>"
                                        data-period="evening"
                                        data-status="<?= e($status) ?>"
                                        data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                        <?= !$editable ? 'disabled' : '' ?>></button>

                                </td>

                            <?php endforeach; ?>

                            <!-- Evening activity -->

                            <td
                                class="activity-cell evening-activity <?= is_admin() ? 'activity-editable' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="evening">
                                <?= e($day['evening']) ?>
                            </td>

                            <!-- Date -->

                            <td class="date-cell">

                                <div class="day-number">
                                    <?= (int) $day['day'] ?>
                                </div>

                                <div class="weekday">
                                    <?= e($day['weekday_short']) ?>
                                </div>

                            </td>

                            <!-- Morning activity -->

                            <td
                                class="activity-cell morning-activity <?= is_admin() ? 'activity-editable' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="morning">
                                <?= e($day['morning']) ?>
                            </td>

                            <!-- Morning availability -->

                            <?php foreach ($membersReversed as $member): ?>

                                <?php

                                $userId =
                                    (int) $member['id'];

                                $availabilityItem =
                                    $availability[$day['date']]['morning'][$userId]
                                    ?? [
                                        'status' => '',
                                        'uncertain' => false,
                                    ];

                                $status =
                                    $availabilityItem['status'] ?? '';

                                $uncertain =
                                    !empty($availabilityItem['uncertain']);

                                $editable =
                                    is_admin()
                                    ||
                                    $userId === current_user_id();

                                $isCurrentUser =
                                    $userId === current_user_id();

                                /*
                            |--------------------------------------------------------------------------
                            | Special day for this Morning cell
                            |--------------------------------------------------------------------------
                            */

                                $specialDayClass =
                                    getSpecialDayClass(
                                        $member,
                                        $day['date']
                                    );

                                ?>

                                <td class="availability-td <?= $isCurrentUser ? 'current-user-column' : '' ?> <?= e($specialDayClass) ?>">

                                    <button
                                        type="button"
                                        class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>"
                                        data-user-id="<?= $userId ?>"
                                        data-date="<?= e($day['date']) ?>"
                                        data-period="morning"
                                        data-status="<?= e($status) ?>"
                                        data-uncertain="<?= $uncertain ? '1' : '0' ?>"
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
        window.SECTION_SCHEDULE = <?= json_encode(
                                        [
                                            'csrfToken' => $csrf
                                        ],
                                        JSON_UNESCAPED_SLASHES
                                    ) ?>;
    </script>

    <script src="assets/js/app.js"></script>

</body>

</html>