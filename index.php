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
    $events = $splitEvents[$day['date']][$period] ?? [];
    if ($events) return $events;

    return [[
        'id' => null,
        'activity' => $day[$period] ?? '',
        'sort_order' => 0,
    ]];
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

$today = new DateTime('today');
$currentWeekStart = clone $today;
if ((int) $currentWeekStart->format('N') !== 1) $currentWeekStart->modify('monday this week');

$mobileDays = array_values(array_filter(
    $days,
    static fn(array $day) => new DateTime($day['date']) >= $currentWeekStart
));

$pointsAvailability = $scheduleRepository->availabilityForMonth($seasonStartDate, $context['lastDay']);
$pointSplitEvents = $scheduleRepository->splitEventsForMonth($seasonStartDate, $context['lastDay']);
$pointSplitAvailability = $scheduleRepository->splitAvailabilityForMonth($seasonStartDate, $context['lastDay']);

$weeklyEveningPoints = [];
$calculationDate = clone $seasonStartDate;
while ($calculationDate <= $context['lastDay']) {
    $date = $calculationDate->format('Y-m-d');
    $weekdayNumber = (int) $calculationDate->format('N');

    if ($weekdayNumber === 1) {
        $weeklyEveningPoints[$date] = $runningEveningPoints;
    }

    $pointsForCross = $weekdayNumber >= 6 ? 2 : 1;
    $eveningSplit = $pointSplitEvents[$date]['evening'] ?? [];

    foreach ($members as $member) {
        $userId = (int) $member['id'];

        if ($eveningSplit) {
            foreach ($eveningSplit as $event) {
                $item = $pointSplitAvailability[(int) $event['id']][$userId] ?? null;
                if (is_array($item) && ($item['status'] ?? '') === 'available') {
                    $runningEveningPoints[$userId] += $pointsForCross;
                }
            }
        } else {
            $item = $pointsAvailability[$date]['evening'][$userId] ?? null;
            if (is_array($item) && ($item['status'] ?? '') === 'available') {
                $runningEveningPoints[$userId] += $pointsForCross;
            }
        }
    }

    $calculationDate->modify('+1 day');
}

$csrf = csrf_token();
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

    <div class="desktop-schedule">
        <div class="schedule-wrap">
            <table class="schedule-table">
                <thead>
                    <tr class="group-header">
                        <th colspan="<?= count($members) + 1 ?>">Evening</th>
                        <th rowspan="2" class="date-head">Date</th>
                        <th colspan="<?= count($members) + 1 ?>">Morning</th>
                    </tr>
                    <tr class="names-header">
                        <?php foreach ($membersReversed as $member): ?>
                            <?php $isCurrentUser = (int) $member['id'] === current_user_id(); ?>
                            <th class="member-head <?= $isCurrentUser ? 'current-user-column' : '' ?>"><span><?= e($member['name']) ?></span></th>
                        <?php endforeach; ?>
                        <th class="activity-head">Activity</th>
                        <th class="activity-head">Activity</th>
                        <?php foreach ($membersReversed as $member): ?>
                            <?php $isCurrentUser = (int) $member['id'] === current_user_id(); ?>
                            <th class="member-head <?= $isCurrentUser ? 'current-user-column' : '' ?>"><span><?= e($member['name']) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($days as $day): ?>
                    <?php if ($day['weekday'] === 'Monday'): ?>
                        <tr class="points-row">
                            <?php foreach ($membersReversed as $member): ?>
                                <?php
                                $userId = (int) $member['id'];
                                $isCurrentUser = $userId === current_user_id();
                                $weekPoints = $weeklyEveningPoints[$day['date']][$userId] ?? $member['evening_starting_points'] ?? 0;
                                ?>
                                <td class="points-cell <?= $isCurrentUser ? 'current-user-column' : '' ?>"><?= (int) $weekPoints ?></td>
                            <?php endforeach; ?>
                            <td class="points-label">Points</td>
                            <td class="points-week">Week</td>
                            <td class="points-label">Points</td>
                            <?php foreach ($membersReversed as $member): ?>
                                <?php $userId = (int) $member['id']; $isCurrentUser = $userId === current_user_id(); ?>
                                <td class="points-cell <?= $isCurrentUser ? 'current-user-column' : '' ?>"><?= (int) ($runningMorningPoints[$userId] ?? 0) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endif; ?>

                    <?php
                    $eveningRows = slotEvents($day, 'evening', $splitEvents);
                    $morningRows = slotEvents($day, 'morning', $splitEvents);
                    $rowCount = max(count($eveningRows), count($morningRows));
                    ?>

                    <?php for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++): ?>
                        <?php
                        $eveningEvent = $eveningRows[$rowIndex] ?? null;
                        $morningEvent = $morningRows[$rowIndex] ?? null;
                        $rowClasses = [];
                        if ($rowCount > 1) $rowClasses[] = 'split-row';
                        if ($day['weekday'] === 'Sunday' && $rowIndex === $rowCount - 1) $rowClasses[] = 'week-end';
                        ?>
                        <tr class="<?= e(implode(' ', $rowClasses)) ?>">
                            <?php if ($eveningEvent): ?>
                                <?php $eveningEventId = $eveningEvent['id'] !== null ? (int) $eveningEvent['id'] : null; ?>
                                <?php foreach ($membersReversed as $member): ?>
                                    <?php
                                    $userId = (int) $member['id'];
                                    $item = $eveningEventId
                                        ? ($splitAvailability[$eveningEventId][$userId] ?? ['status' => '', 'uncertain' => false])
                                        : ($availability[$day['date']]['evening'][$userId] ?? ['status' => '', 'uncertain' => false]);
                                    $status = $item['status'] ?? '';
                                    $uncertain = !empty($item['uncertain']);
                                    $editable = is_admin() || $userId === current_user_id();
                                    $isCurrentUser = $userId === current_user_id();
                                    $specialDayClass = getSpecialDayClass($member, $day['date']);
                                    ?>
                                    <td class="availability-td <?= $isCurrentUser ? 'current-user-column' : '' ?> <?= e($specialDayClass) ?>">
                                        <?php if ($eveningEventId): ?>
                                            <button type="button" class="member-cell split-availability-cell <?= $editable ? 'editable' : '' ?>" data-split-event-id="<?= $eveningEventId ?>" data-user-id="<?= $userId ?>" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php else: ?>
                                            <button type="button" class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>" data-user-id="<?= $userId ?>" data-date="<?= e($day['date']) ?>" data-period="evening" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="activity-cell evening-activity <?= (!$eveningEventId && is_admin()) ? 'activity-editable' : '' ?> <?= $eveningEventId ? 'split-activity-cell' : '' ?>" data-date="<?= e($day['date']) ?>" data-period="evening" data-split-event-id="<?= $eveningEventId ?: '' ?>"><?= e($eveningEvent['activity']) ?></td>
                            <?php else: ?>
                                <?php foreach ($membersReversed as $member): ?><td class="availability-td split-empty-cell"></td><?php endforeach; ?>
                                <td class="activity-cell split-empty-cell"></td>
                            <?php endif; ?>

                            <?php if ($rowIndex === 0): ?>
                                <td class="date-cell" rowspan="<?= $rowCount ?>">
                                    <div class="day-number"><?= (int) $day['day'] ?></div>
                                    <div class="weekday"><?= e($day['weekday_short']) ?></div>
                                </td>
                            <?php endif; ?>

                            <?php if ($morningEvent): ?>
                                <?php $morningEventId = $morningEvent['id'] !== null ? (int) $morningEvent['id'] : null; ?>
                                <td class="activity-cell morning-activity <?= (!$morningEventId && is_admin()) ? 'activity-editable' : '' ?> <?= $morningEventId ? 'split-activity-cell' : '' ?>" data-date="<?= e($day['date']) ?>" data-period="morning" data-split-event-id="<?= $morningEventId ?: '' ?>"><?= e($morningEvent['activity']) ?></td>
                                <?php foreach ($membersReversed as $member): ?>
                                    <?php
                                    $userId = (int) $member['id'];
                                    $item = $morningEventId
                                        ? ($splitAvailability[$morningEventId][$userId] ?? ['status' => '', 'uncertain' => false])
                                        : ($availability[$day['date']]['morning'][$userId] ?? ['status' => '', 'uncertain' => false]);
                                    $status = $item['status'] ?? '';
                                    $uncertain = !empty($item['uncertain']);
                                    $editable = is_admin() || $userId === current_user_id();
                                    $isCurrentUser = $userId === current_user_id();
                                    $specialDayClass = getSpecialDayClass($member, $day['date']);
                                    ?>
                                    <td class="availability-td <?= $isCurrentUser ? 'current-user-column' : '' ?> <?= e($specialDayClass) ?>">
                                        <?php if ($morningEventId): ?>
                                            <button type="button" class="member-cell split-availability-cell <?= $editable ? 'editable' : '' ?>" data-split-event-id="<?= $morningEventId ?>" data-user-id="<?= $userId ?>" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php else: ?>
                                            <button type="button" class="member-cell availability-cell <?= $editable ? 'editable' : '' ?>" data-user-id="<?= $userId ?>" data-date="<?= e($day['date']) ?>" data-period="morning" data-status="<?= e($status) ?>" data-uncertain="<?= $uncertain ? '1' : '0' ?>" <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td class="activity-cell split-empty-cell"></td>
                                <?php foreach ($membersReversed as $member): ?><td class="availability-td split-empty-cell"></td><?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    <?php endfor; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

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
                            <h3>Evening points</h3>
                            <div class="mobile-points-grid">
                                <?php foreach ($members as $member): ?>
                                    <?php $userId = (int) $member['id']; $weekPoints = $weeklyEveningPoints[$day['date']][$userId] ?? $member['evening_starting_points'] ?? 0; ?>
                                    <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>"><span><?= e($member['name']) ?></span><strong><?= (int) $weekPoints ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <h3>Morning points</h3>
                            <div class="mobile-points-grid">
                                <?php foreach ($members as $member): ?>
                                    <?php $userId = (int) $member['id']; ?>
                                    <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>"><span><?= e($member['name']) ?></span><strong><?= (int) ($runningMorningPoints[$userId] ?? 0) ?></strong></div>
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

<script>window.SECTION_SCHEDULE = <?= json_encode(['csrfToken' => $csrf], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/mobile.js"></script>
<script src="assets/js/split-events.js"></script>
</body>
</html>
