<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schedule_helpers.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Schedule.php';
require_once __DIR__ . '/classes/PointCalculator.php';

require_login();

$userRepository = new User($pdo);
$scheduleRepository = new Schedule($pdo);
$pointCalculator = new PointCalculator();

/*
|--------------------------------------------------------------------------
| Users and displayed month
|--------------------------------------------------------------------------
*/

$members = $userRepository->activeUsers();

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
| Display data
|--------------------------------------------------------------------------
*/

$availability = $scheduleRepository->availabilityForMonth(
    $context['firstDay'],
    $context['lastDay']
);

$splitEvents = $scheduleRepository->splitEventsForMonth(
    $context['firstDay'],
    $context['lastDay']
);

$splitAvailability = $scheduleRepository->splitAvailabilityForMonth(
    $context['firstDay'],
    $context['lastDay']
);

$activityPointItems = $scheduleRepository->activityPointItemsForMonth(
    $context['firstDay'],
    $context['lastDay']
);

/*
|--------------------------------------------------------------------------
| Mobile: hide dates before the current week
|--------------------------------------------------------------------------
*/

$today = new DateTime('today');
$currentWeekStart = clone $today;

if ((int) $currentWeekStart->format('N') !== 1) {
    $currentWeekStart->modify('monday this week');
}

$mobileDays = array_values(
    array_filter(
        $days,
        static fn(array $day): bool =>
            new DateTime($day['date']) >= $currentWeekStart
    )
);

/*
|--------------------------------------------------------------------------
| Cumulative points
|--------------------------------------------------------------------------
|
| "Morning points" are rehearsal points.
| "Evening points" are performance points.
|
| The physical time of the event does NOT determine the points category.
| The event's point_type does.
|
*/

$seasonStartDate = new DateTime(
    defined('SEASON_START_DATE')
        ? SEASON_START_DATE
        : '2026-08-01'
);

$weeklyRehearsalPoints = [];
$weeklyPerformancePoints = [];

if ($context['lastDay'] >= $seasonStartDate) {
    $pointAvailability = $scheduleRepository->availabilityForMonth(
        $seasonStartDate,
        $context['lastDay']
    );

    $pointSplitEvents = $scheduleRepository->splitEventsForMonth(
        $seasonStartDate,
        $context['lastDay']
    );

    $pointSplitAvailability = $scheduleRepository->splitAvailabilityForMonth(
        $seasonStartDate,
        $context['lastDay']
    );

    $pointActivityItems = $scheduleRepository->activityPointItemsForMonth(
        $seasonStartDate,
        $context['lastDay']
    );

    $pointTotals = $pointCalculator->calculate(
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
|
| Always generate/read the token BEFORE serialising it to JavaScript.
| This fixes the "Invalid security token" bug in the pre-refactor version,
| where $csrf was referenced in the page but never assigned.
|
*/

$csrf = csrf_token();

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= e($context['monthTitle']) ?>
        ·
        <?= e(APP_NAME) ?>
    </title>

    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/split-events.css">
    <link rel="stylesheet" href="assets/css/desktop.css">
    <link rel="stylesheet" href="assets/css/activity-points.css">
</head>

<body>

    <header class="topbar">

        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <nav
            class="month-navigation"
            aria-label="Month navigation"
        >
            <a
                class="month-arrow"
                href="?year=<?= $context['previousMonth']->format('Y') ?>&month=<?= $context['previousMonth']->format('n') ?>"
                aria-label="Previous month"
            >‹</a>

            <h1>
                <?= e($context['monthTitle']) ?>
            </h1>

            <a
                class="month-arrow"
                href="?year=<?= $context['nextMonth']->format('Y') ?>&month=<?= $context['nextMonth']->format('n') ?>"
                aria-label="Next month"
            >›</a>
        </nav>

        <div class="account">
            <span class="account-name">
                <?= e($_SESSION['name']) ?>
            </span>

            <button
                type="button"
                class="button edit-mode-toggle"
                id="edit-mode-toggle"
                aria-pressed="false"
            >
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

        <div class="legend desktop-legend">
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

        <?php require __DIR__ . '/views/desktop-schedule.php'; ?>
        <?php require __DIR__ . '/views/mobile-schedule.php'; ?>

    </main>

    <script>
        window.SECTION_SCHEDULE = <?= json_encode(
            [
                'csrfToken' => $csrf,
                'currentUserId' => current_user_id(),
                'isAdmin' => is_admin(),
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        ) ?>;
    </script>

    <!-- Shared state/API first -->
    <script src="assets/js/core.js"></script>

    <!-- Feature modules: one responsibility each -->
    <script src="assets/js/app.js"></script>
    <script src="assets/js/availability.js"></script>
    <script src="assets/js/split-events.js"></script>
    <script src="assets/js/activity-points.js"></script>

</body>
</html>
