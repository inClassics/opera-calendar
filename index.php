<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schedule_helpers.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Schedule.php';
require_once __DIR__ . '/classes/PointCalculator.php';
require_once __DIR__ . '/classes/PointCounting.php';
require_once __DIR__ . '/classes/ScheduleChangeTracker.php';

require_login();

$userRepository =
    new User(
        $pdo
    );

$scheduleRepository =
    new Schedule(
        $pdo
    );

$pointCalculator =
    new PointCalculator();

$pointCounting =
    new PointCounting(
        $pdo
    );

$changeTracker =
    new ScheduleChangeTracker(
        $pdo
    );

/*
|--------------------------------------------------------------------------
| Users and displayed month
|--------------------------------------------------------------------------
*/

$members =
    $userRepository
    ->activeUsers();

$context =
    $scheduleRepository
    ->monthContext(
        (int) (
            $_GET['year']
            ?? date('Y')
        ),
        (int) (
            $_GET['month']
            ?? date('n')
        )
    );

$days =
    $scheduleRepository
    ->daysForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

/*
|--------------------------------------------------------------------------
| Changes since this user last checked this month
|--------------------------------------------------------------------------
*/

$scheduleChanges = [
    'month' =>
    $context['firstDay']
        ->format('Y-m'),

    'last_seen_activity_id' =>
    0,

    'current_activity_id' =>
    0,

    'count' =>
    0,

    'changes' =>
    [],
];

try {
    $scheduleChanges =
        $changeTracker
        ->changesForMonth(
            current_user_id(),
            is_admin(),
            $context['firstDay'],
            $context['lastDay']
        );
} catch (
    Throwable $e
) {
    /*
    | Keep the schedule usable if the migration has not yet been run.
    */
}

/*
|--------------------------------------------------------------------------
| Display data
|--------------------------------------------------------------------------
*/

$availability =
    $scheduleRepository
    ->availabilityForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

$availability =
    $pointCounting
    ->applyNormalFlags(
        $availability,
        $context['firstDay'],
        $context['lastDay']
    );

$splitEvents =
    $scheduleRepository
    ->splitEventsForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

$splitAvailability =
    $scheduleRepository
    ->splitAvailabilityForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

$splitAvailability =
    $pointCounting
    ->applySplitFlags(
        $splitAvailability,
        $context['firstDay'],
        $context['lastDay']
    );

$activityPointItems =
    $scheduleRepository
    ->activityPointItemsForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

/*
|--------------------------------------------------------------------------
| Mobile: hide dates before the current week
|--------------------------------------------------------------------------
*/

$today =
    new DateTime(
        'today'
    );

$currentWeekStart =
    clone $today;

if (
    (int) $currentWeekStart
        ->format('N')
    !== 1
) {
    $currentWeekStart
        ->modify(
            'monday this week'
        );
}

$mobileDays =
    array_values(
        array_filter(
            $days,
            static fn(array $day): bool =>
            new DateTime(
                $day['date']
            )
                >=
                $currentWeekStart
        )
    );

/*
|--------------------------------------------------------------------------
| Cumulative points
|--------------------------------------------------------------------------
|
| Morning points = rehearsal points.
| Evening points = performance points.
|
| Physical morning/evening time does not determine point category.
| point_type determines point category.
|
*/

$seasonStartDate =
    new DateTime(
        defined(
            'SEASON_START_DATE'
        )
            ? SEASON_START_DATE
            : '2026-08-01'
    );

$weeklyRehearsalPoints =
    [];

$weeklyPerformancePoints =
    [];

if (
    $context['lastDay']
    >=
    $seasonStartDate
) {
    $pointAvailability =
        $scheduleRepository
        ->availabilityForMonth(
            $seasonStartDate,
            $context['lastDay']
        );

    $pointAvailability =
        $pointCounting
        ->applyNormalFlags(
            $pointAvailability,
            $seasonStartDate,
            $context['lastDay']
        );

    $pointSplitEvents =
        $scheduleRepository
        ->splitEventsForMonth(
            $seasonStartDate,
            $context['lastDay']
        );

    $pointSplitAvailability =
        $scheduleRepository
        ->splitAvailabilityForMonth(
            $seasonStartDate,
            $context['lastDay']
        );

    $pointSplitAvailability =
        $pointCounting
        ->applySplitFlags(
            $pointSplitAvailability,
            $seasonStartDate,
            $context['lastDay']
        );

    $pointActivityItems =
        $scheduleRepository
        ->activityPointItemsForMonth(
            $seasonStartDate,
            $context['lastDay']
        );

    $pointTotals =
        $pointCalculator
        ->calculate(
            $members,
            $seasonStartDate,
            $context['lastDay'],
            $pointAvailability,
            $pointSplitEvents,
            $pointSplitAvailability,
            $pointActivityItems
        );

    $weeklyRehearsalPoints =
        $pointTotals['weekly_rehearsal'];

    $weeklyPerformancePoints =
        $pointTotals['weekly_performance'];
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf =
    csrf_token();

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        <?= e($context['monthTitle']) ?>
        ·
        <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/app.css">

    <link
        rel="stylesheet"
        href="assets/css/mobile.css">

    <link
        rel="stylesheet"
        href="assets/css/split-events.css">

    <link
        rel="stylesheet"
        href="assets/css/desktop.css">

    <link
        rel="stylesheet"
        href="assets/css/activity-points.css">

    <link
        rel="stylesheet"
        href="assets/css/point-counting.css">

    <link
        rel="stylesheet"
        href="assets/css/schedule-changes.css">
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

            <span class="account-name">
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

                <a href="admin/activity-log.php">
                    Changes
                </a>

            <?php endif; ?>

            <a href="change-password.php">
                Change password
            </a>

            <a href="logout.php">
                Logout
            </a>

        </div>

    </header>

    <main class="page">

        <?php if (
            ($scheduleChanges['count'] ?? 0)
            > 0
        ): ?>

            <div
                class="schedule-change-notice"
                id="schedule-change-notice">

                <div class="schedule-change-notice-copy">

                    <span
                        class="schedule-change-notice-dot"
                        aria-hidden="true">
                    </span>

                    <div>
                        <strong>
                            <?= (int) $scheduleChanges['count'] ?>
                            <?= (int) $scheduleChanges['count'] === 1
                                ? 'change'
                                : 'changes' ?>
                            since you last checked this month
                        </strong>

                        <div class="muted">
                            Highlighted areas were changed by another member,
                            an administrator, or the calendar system.
                        </div>
                    </div>

                </div>

                <button
                    type="button"
                    class="button"
                    id="mark-schedule-changes-seen">
                    Mark as seen
                </button>

            </div>

        <?php endif; ?>

        <div class="legend desktop-legend">

            <span class="available-mark">
                ×
            </span>

            available

            <span class="unavailable-mark">
                •
            </span>

            unavailable

            <span class="muted">
                ? = uncertain
            </span>

            <span class="muted">
                ×⁰ = available but does not count for points
            </span>

            <span class="muted">
                blank = unanswered
            </span>

        </div>

        <?php
        require
            __DIR__
            .
            '/views/desktop-schedule.php';
        ?>

        <?php
        require
            __DIR__
            .
            '/views/mobile-schedule.php';
        ?>

    </main>

    <script>
        window.SECTION_SCHEDULE =
            <?= json_encode(
                [
                    'csrfToken' =>
                    $csrf,

                    'currentUserId' =>
                    current_user_id(),

                    'isAdmin' =>
                    is_admin(),

                    'scheduleChanges' =>
                    $scheduleChanges,
                ],
                JSON_UNESCAPED_SLASHES
                    |
                    JSON_UNESCAPED_UNICODE
            ) ?>;
    </script>

    <script src="assets/js/core.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/availability.js"></script>
    <script src="assets/js/split-events.js"></script>
    <script src="assets/js/activity-points.js"></script>
    <script src="assets/js/schedule-changes.js"></script>

</body>

</html>