<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Schedule.php';

require_login();

function getSpecialDayClass(array $member, string $date): string
{
    $classes = [];
    $md = date('m-d', strtotime($date));
    if (!empty($member['birthday']) && date('m-d', strtotime($member['birthday'])) === $md) $classes[] = 'birthday-cell';
    if (!empty($member['name_day']) && date('m-d', strtotime($member['name_day'])) === $md) $classes[] = 'name-day-cell';
    return implode(' ', $classes);
}

function getSpecialDayEmoji(array $member, string $date): string
{
    $classes = getSpecialDayClass($member, $date);
    $emoji = '';
    if (str_contains($classes, 'birthday-cell')) $emoji .= '🎂';
    if (str_contains($classes, 'name-day-cell')) $emoji .= '🌼';
    return $emoji;
}

function slotEvents(array $day, string $period, array $splitEvents): array
{
    $events =
        $splitEvents[$day['date']][$period]
        ?? [];

    if ($events) {
        return $events;
    }

    $pointItems =
        $GLOBALS['activityPointItems'][$day['date']][$period]
        ?? [];

    return [[
        'id' => null,
        'activity' => $day[$period] ?? '',
        'sort_order' => 0,
        'point_items' => $pointItems,
    ]];
}

function format_points(float|int $value): string
{
    $formatted =
        number_format(
            (float) $value,
            2,
            '.',
            ''
        );

    return rtrim(
        rtrim(
            $formatted,
            '0'
        ),
        '.'
    );
}

$userRepository = new User($pdo);
$scheduleRepository = new Schedule($pdo);
$seasonStartDate = new DateTime('2026-08-01');

$members = $userRepository->activeUsers();
$membersReversed = array_reverse($members);

$runningEveningPoints = [];
$runningMorningPoints = [];
foreach ($members as $member) {
    $userId = (int) $member['id'];
    $runningEveningPoints[$userId] = (int) ($member['evening_starting_points'] ?? 0);
    $runningMorningPoints[$userId] = (int) ($member['morning_starting_points'] ?? 0);
}

$context = $scheduleRepository->monthContext(
    (int) ($_GET['year'] ?? date('Y')),
    (int) ($_GET['month'] ?? date('n'))
);

$days = $scheduleRepository->daysForMonth($context['firstDay'], $context['lastDay']);
$availability = $scheduleRepository->availabilityForMonth($context['firstDay'], $context['lastDay']);
$splitEvents = $scheduleRepository->splitEventsForMonth($context['firstDay'], $context['lastDay']);
$splitAvailability = $scheduleRepository->splitAvailabilityForMonth($context['firstDay'], $context['lastDay']);

$activityPointItems =
    $scheduleRepository
    ->activityPointItemsForMonth(
        $context['firstDay'],
        $context['lastDay']
    );

$GLOBALS['activityPointItems'] =
    $activityPointItems;

$today = new DateTime('today');
$currentWeekStart = clone $today;
if ((int) $currentWeekStart->format('N') !== 1) $currentWeekStart->modify('monday this week');

$mobileDays = array_values(array_filter(
    $days,
    static fn(array $day) => new DateTime($day['date']) >= $currentWeekStart
));

$pointsAvailability =
    $scheduleRepository
    ->availabilityForMonth(
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

$pointActivityItems =
    $scheduleRepository
    ->activityPointItemsForMonth(
        $seasonStartDate,
        $context['lastDay']
    );

/*
|--------------------------------------------------------------------------
| Cumulative rehearsal / performance points
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Physical period and point category are completely independent.
|
| morning rehearsal     -> rehearsal total
| evening rehearsal     -> rehearsal total
| morning performance   -> performance total
| evening performance   -> performance total
|
| Rehearsal totals are DISPLAYED in the upper/morning points table.
| Performance totals are DISPLAYED in the lower/evening points table.
|
*/

$runningRehearsalPoints = [];
$runningPerformancePoints = [];

foreach ($members as $member) {

    $userId =
        (int) $member['id'];

    /*
    |--------------------------------------------------------------------------
    | Season starting values
    |--------------------------------------------------------------------------
    */

    $runningRehearsalPoints[$userId] =
        (float) (
            $member['morning_starting_points']
            ?? 0
        );

    $runningPerformancePoints[$userId] =
        (float) (
            $member['evening_starting_points']
            ?? 0
        );
}

/*
|--------------------------------------------------------------------------
| Weekly snapshots
|--------------------------------------------------------------------------
|
| The value stored for Monday is the total BEFORE Monday's activities.
|
*/

$weeklyRehearsalPoints = [];
$weeklyPerformancePoints = [];

/*
|--------------------------------------------------------------------------
| Helper: add one activity to the correct calculator
|--------------------------------------------------------------------------
*/

$addActivityPoints = function (
    float $pointValue,
    ?string $pointType,
    array $activityAvailability
) use (
    &$runningRehearsalPoints,
    &$runningPerformancePoints,
    $members
): void {

    /*
    |--------------------------------------------------------------------------
    | No valid points
    |--------------------------------------------------------------------------
    */

    if ($pointValue <= 0) {
        return;
    }

    if (
        !in_array(
            $pointType,
            [
                'rehearsal',
                'performance'
            ],
            true
        )
    ) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Check every musician
    |--------------------------------------------------------------------------
    */

    foreach ($members as $member) {

        $userId =
            (int) $member['id'];

        $availabilityItem =
            $activityAvailability[$userId]
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Only a CROSS counts
        |--------------------------------------------------------------------------
        |
        | available = cross
        | unavailable = dot
        | blank = no points
        |
        | "uncertain" does not change the point value:
        | an available + uncertain cell still has a cross.
        |
        */

        if (
            !is_array($availabilityItem)
            ||
            ($availabilityItem['status'] ?? '')
            !== 'available'
        ) {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Route by point TYPE, NOT by morning/evening
        |--------------------------------------------------------------------------
        */

        if ($pointType === 'rehearsal') {

            $runningRehearsalPoints[$userId] +=
                $pointValue;
        } elseif (
            $pointType === 'performance'
        ) {

            $runningPerformancePoints[$userId] +=
                $pointValue;
        }
    }
};

/*
|--------------------------------------------------------------------------
| Calculate from season start until displayed calendar end
|--------------------------------------------------------------------------
*/

$calculationDate =
    clone $seasonStartDate;

while (
    $calculationDate
    <=
    $context['lastDay']
) {

    $date =
        $calculationDate
        ->format('Y-m-d');

    $weekdayNumber =
        (int)
        $calculationDate
            ->format('N');

    /*
    |--------------------------------------------------------------------------
    | Monday snapshot
    |--------------------------------------------------------------------------
    |
    | Snapshot FIRST.
    | Then Monday's events are counted.
    |
    */

    if ($weekdayNumber === 1) {

        $weeklyRehearsalPoints[$date] =
            $runningRehearsalPoints;

        $weeklyPerformancePoints[$date] =
            $runningPerformancePoints;
    }

    /*
    |--------------------------------------------------------------------------
    | Scan BOTH physical periods
    |--------------------------------------------------------------------------
    |
    | We deliberately don't care which calculator they belong to yet.
    |
    */

    foreach (
        ['morning', 'evening']
        as $period
    ) {

        /*
        |--------------------------------------------------------------------------
        | Split events
        |--------------------------------------------------------------------------
        |
        | Once a slot is split, each split activity has:
        |
        | - its own points
        | - its own R/P type
        | - its own availability
        |
        */

        $splitForSlot =
            $pointSplitEvents[$date][$period]
            ?? [];

        if (!empty($splitForSlot)) {

            foreach ($splitForSlot as $event) {

                $eventId =
                    (int) (
                        $event['id']
                        ?? 0
                    );

                if ($eventId <= 0) {
                    continue;
                }

                $pointValue =
                    (float) (
                        $event['point_value']
                        ?? 0
                    );

                $pointType =
                    $event['point_type']
                    ?? null;

                $eventAvailability =
                    $pointSplitAvailability[$eventId]
                    ?? [];

                /*
                |--------------------------------------------------------------------------
                | Route this activity to rehearsal/performance calculator
                |--------------------------------------------------------------------------
                */

                $addActivityPoints(
                    $pointValue,
                    $pointType,
                    $eventAvailability
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Important
            |--------------------------------------------------------------------------
            |
            | A split slot replaces normal slot calculation.
            | Otherwise we would count the same activities twice.
            |
            */

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Normal unsplit slot
        |--------------------------------------------------------------------------
        |
        | A normal slot may contain one or several point items.
        |
        | They share the normal slot availability.
        |
        | Example:
        |
        | 11:00 rehearsal, 3 pts
        | 13:00 performance, 2 pts
        |
        | If both genuinely have different people, the slot should be split.
        |
        */

        $pointItems =
            $pointActivityItems[$date][$period]
            ?? [];

        if (empty($pointItems)) {
            continue;
        }

        $slotAvailability =
            $pointsAvailability[$date][$period]
            ?? [];

        foreach ($pointItems as $pointItem) {

            $pointValue =
                (float) (
                    $pointItem['point_value']
                    ?? 0
                );

            $pointType =
                $pointItem['point_type']
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Again: physical period is irrelevant here.
            |--------------------------------------------------------------------------
            */

            $addActivityPoints(
                $pointValue,
                $pointType,
                $slotAvailability
            );
        }
    }

    $calculationDate
        ->modify('+1 day');
}

/*
|--------------------------------------------------------------------------
| Compatibility aliases
|--------------------------------------------------------------------------
|
| Existing view code may still call these "morning/evening".
|
| But semantically:
|
| morning points = REHEARSAL
| evening points = PERFORMANCE
|
*/

$runningMorningPoints =
    $runningRehearsalPoints;

$runningEveningPoints =
    $runningPerformancePoints;

$weeklyEveningPoints =
    $weeklyPerformancePoints;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($context['monthTitle']) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/split-events.css">
    <link rel="stylesheet" href="assets/css/desktop-v3.css">
    <link rel="stylesheet" href="assets/css/activity-points.css">
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
            <span class="account-name"><?= e($_SESSION['name']) ?></span>
            <button type="button" class="button edit-mode-toggle" id="edit-mode-toggle" aria-pressed="false">Edit schedule</button>
            <?php if (is_admin()): ?>
                <a href="admin/import-calendar.php">Import calendar</a>
                <a href="admin/users.php">Users</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">
        <div class="legend desktop-legend">
            <span class="available-mark">×</span> available
            <span class="unavailable-mark">•</span> unavailable
            <span class="muted">? = uncertain</span>
            <span class="muted">blank = unanswered</span>
        </div>

        <?php require __DIR__ . '/views/desktop-schedule-v3.php'; ?>

        <div class="mobile-schedule">
            <div class="mobile-legend">
                <span><strong class="available-mark">×</strong> available</span>
                <span><strong class="unavailable-mark">•</strong> unavailable</span>
                <span class="muted">? uncertain</span>
            </div>

            <?php foreach ($mobileDays as $day): ?>
                <?php if ($day['weekday'] === 'Monday'): ?>
                    <section class="mobile-points-card">
                        <div class="mobile-week-title">Week of <?= e((new DateTime($day['date']))->format('j M')) ?></div>
                        <div class="mobile-points-columns">
                            <div>
                                <h3>Performance points</h3>
                                <div class="mobile-points-grid">
                                    <?php foreach ($members as $member): ?>
                                        <?php $userId = (int) $member['id'];
                                        $weekPoints = $weeklyEveningPoints[$day['date']][$userId] ?? $member['evening_starting_points'] ?? 0; ?>
                                        <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>"><span><?= e($member['name']) ?></span><strong><?= e(format_points($weekPoints)) ?></strong></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <h3>Rehearsal points</h3>
                                <div class="mobile-points-grid">
                                    <?php foreach ($members as $member): ?>
                                        <?php $userId = (int) $member['id']; ?>
                                        <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>"><span><?= e($member['name']) ?></span><strong><?= e(format_points($weeklyRehearsalPoints[$day['date']][$userId] ?? $member['morning_starting_points'] ?? 0)) ?></strong></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <article class="mobile-day-card <?= $day['weekday'] === 'Sunday' ? 'mobile-week-end' : '' ?>">
                    <header class="mobile-day-header">
                        <div class="mobile-day-number"><?= (int) $day['day'] ?></div>
                        <div>
                            <div class="mobile-weekday"><?= e($day['weekday']) ?></div>
                            <div class="mobile-date-full"><?= e((new DateTime($day['date']))->format('j F Y')) ?></div>
                        </div>
                    </header>

                    <div class="mobile-session-grid">
                        <?php foreach (['morning' => 'Morning', 'evening' => 'Evening'] as $period => $label): ?>
                            <?php $periodEvents = slotEvents($day, $period, $splitEvents); ?>
                            <section class="mobile-session">
                                <div class="mobile-session-heading"><span><?= $period === 'evening' ? '🌙' : '☀️' ?></span><strong><?= e($label) ?></strong></div>

                                <?php foreach ($periodEvents as $event): ?>
                                    <?php $eventId = $event['id'] !== null ? (int) $event['id'] : null; ?>
                                    <div class="mobile-event-block <?= $eventId ? 'is-split-event' : '' ?>">
                                        <div class="mobile-activity <?= (!$eventId && is_admin()) ? 'activity-editable' : '' ?> <?= $eventId ? 'split-activity-cell' : '' ?>" data-date="<?= e($day['date']) ?>" data-period="<?= e($period) ?>" data-split-event-id="<?= $eventId ?: '' ?>"><?= e($event['activity']) ?></div>

                                        <?php if (is_admin()): ?>
                                            <?php
                                            $mobilePointItems =
                                                $event['point_items']
                                                ?? [];
                                            ?>
                                            <?php foreach ($mobilePointItems as $pointItem): ?>
                                                <div
                                                    class="activity-point-editor mobile-activity-point-editor"
                                                    data-point-source="<?= e($pointItem['source_type']) ?>"
                                                    data-point-id="<?= (int) $pointItem['source_id'] ?>"
                                                    data-point-type="<?= e($pointItem['point_type'] ?? '') ?>">
                                                    <input

                                                        type="number"

                                                        class="activity-point-input"

                                                        value="<?= e(format_points($pointItem['point_value'] ?? 0)) ?>"

                                                        min="0"

                                                        max="9999"

                                                        step="1"

                                                        inputmode="numeric"

                                                        aria-label="Activity point value">

                                                    <div class="activity-point-type">
                                                        <button
                                                            type="button"
                                                            class="activity-point-type-button <?= ($pointItem['point_type'] ?? '') === 'rehearsal' ? 'selected' : '' ?>"
                                                            data-point-type="rehearsal"
                                                            title="Rehearsal points">R</button>

                                                        <button
                                                            type="button"
                                                            class="activity-point-type-button <?= ($pointItem['point_type'] ?? '') === 'performance' ? 'selected' : '' ?>"
                                                            data-point-type="performance"
                                                            title="Performance points">P</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <div class="mobile-members-grid">
                                            <?php foreach ($members as $member): ?>
                                                <?php
                                                $userId = (int) $member['id'];
                                                $item = $eventId
                                                    ? ($splitAvailability[$eventId][$userId] ?? ['status' => '', 'uncertain' => false])
                                                    : ($availability[$day['date']][$period][$userId] ?? ['status' => '', 'uncertain' => false]);
                                                $status = $item['status'] ?? '';
                                                $uncertain = !empty($item['uncertain']);
                                                $editable = is_admin() || $userId === current_user_id();
                                                $isCurrentUser = $userId === current_user_id();
                                                $specialDayClass = getSpecialDayClass($member, $day['date']);
                                                $specialDayEmoji = getSpecialDayEmoji($member, $day['date']);
                                                ?>
                                                <div class="mobile-member-row <?= $isCurrentUser ? 'current-user-mobile' : '' ?> <?= e($specialDayClass) ?>">
                                                    <span class="mobile-member-name"><?= e($member['name']) ?><?php if ($specialDayEmoji !== ''): ?> <span class="mobile-special-day"><?= e($specialDayEmoji) ?></span><?php endif; ?></span>
                                                    <div class="mobile-member-actions">
                                                        <?php if ($eventId): ?>
                                                            <button type="button" class="member-cell split-availability-cell mobile-availability-cell <?= $editable ? 'editable' : '' ?>" data-split-event-id="<?= $eventId ?>" data-user-id="<?= $userId ?>" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                                            <?php if ($editable): ?><button type="button" class="mobile-options-button split-mobile-options-button" aria-label="More options for <?= e($member['name']) ?>">⋯</button><?php endif; ?>
                                                        <?php else: ?>
                                                            <button type="button" class="member-cell availability-cell mobile-availability-cell <?= $editable ? 'editable' : '' ?>" data-user-id="<?= $userId ?>" data-date="<?= e($day['date']) ?>" data-period="<?= e($period) ?>" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                                            <?php if ($editable): ?><button type="button" class="mobile-options-button" aria-label="More options for <?= e($member['name']) ?>">⋯</button><?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        window.SECTION_SCHEDULE = <?= json_encode(['csrfToken' => $csrf], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/mobile.js"></script>
    <script src="assets/js/split-events.js"></script>
    <script src="assets/js/activity-points.js?v=4"></script>
</body>

</html>