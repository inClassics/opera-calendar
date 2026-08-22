<?php
/*
|--------------------------------------------------------------------------
| Desktop schedule — paper layout
|--------------------------------------------------------------------------
|
| Per week, from top to bottom:
|   morning member availability
|   morning activities
|   dates
|   evening activities
|   evening member availability
|
*/

$desktopWeeks = array_chunk($days, 7);

function desktopPaperActivityParts(string $activity): array
{
    $activity = trim($activity);

    if ($activity === '') {
        return [
            'time' => '',
            'title' => '',
            'details' => '',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Extract time from beginning
    |--------------------------------------------------------------------------
    |
    | Supports:
    |
    | 11:00–14:00
    | 11:00-14:00
    | 19:00
    |
    */

    $time = '';

    if (
        preg_match(
            '/^(\d{1,2}:\d{2}(?:\s*[–—-]\s*\d{1,2}:\d{2})?)/u',
            $activity,
            $match
        )
    ) {
        $time = trim($match[1]);

        $activity = trim(
            mb_substr(
                $activity,
                mb_strlen($match[0])
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Move final (...) part into details
    |--------------------------------------------------------------------------
    */

    $details = '';

    if (
        preg_match(
            '/\s*(\([^()]+\))\s*$/u',
            $activity,
            $match
        )
    ) {
        $details = trim($match[1]);

        $activity = trim(
            mb_substr(
                $activity,
                0,
                mb_strlen($activity) - mb_strlen($match[0])
            )
        );
    }

    return [
        'time' => $time,
        'title' => $activity,
        'details' => $details,
    ];
}

function desktopPaperAvailability(
    array $event,
    array $day,
    string $period,
    int $userId,
    array $availability,
    array $splitAvailability
): array {
    $eventId = $event['id'] !== null ? (int) $event['id'] : null;

    if ($eventId) {
        return $splitAvailability[$eventId][$userId] ?? [
            'status' => '',
            'uncertain' => false,
        ];
    }

    return $availability[$day['date']][$period][$userId] ?? [
        'status' => '',
        'uncertain' => false,
    ];
}

function desktopPaperWeekNumber(array $week): string
{
    if (empty($week[0]['date'])) {
        return '';
    }

    return (new DateTime($week[0]['date']))->format('W');
}

function desktopPaperDayClass(array $day): string
{
    $classes = [];

    if (in_array($day['weekday'], ['Saturday', 'Sunday'], true)) {
        $classes[] = 'is-weekend';
    }

    if ($day['date'] === date('Y-m-d')) {
        $classes[] = 'is-today';
    }

    return implode(' ', $classes);
}

function desktopPaperRenderRoster(
    string $period,
    array $week,
    array $members,
    array $availability,
    array $splitEvents,
    array $splitAvailability,
    array $points
): void {
?>
    <div class="desktop-paper-roster">

        <div class="desktop-paper-roster-left">

            <div class="desktop-paper-roster-labels">
                <span>Name</span>
                <span>Pts</span>
            </div>

            <?php foreach ($members as $member): ?>
                <?php
                $userId = (int) $member['id'];
                $isCurrentUser = $userId === current_user_id();
                ?>

                <div class="desktop-paper-person <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>">
                    <span class="desktop-paper-person-name">
                        <?= e($member['name']) ?>
                    </span>

                    <strong class="desktop-paper-person-points">
                        <?= (int) ($points[$userId] ?? 0) ?>
                    </strong>
                </div>
            <?php endforeach; ?>

        </div>

        <div class="desktop-paper-roster-days">

            <?php foreach ($week as $day): ?>
                <?php
                $events = slotEvents($day, $period, $splitEvents);
                $eventCount = max(1, count($events));
                ?>

                <div class="desktop-paper-roster-day <?= e(desktopPaperDayClass($day)) ?>">
                    <div
                        class="desktop-paper-event-grid"
                        style="--event-count: <?= $eventCount ?>">
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventId = $event['id'] !== null ? (int) $event['id'] : null;
                            ?>

                            <div class="desktop-paper-event-marks">

                                <?php foreach ($members as $member): ?>
                                    <?php
                                    $userId = (int) $member['id'];

                                    $item = desktopPaperAvailability(
                                        $event,
                                        $day,
                                        $period,
                                        $userId,
                                        $availability,
                                        $splitAvailability
                                    );

                                    $status = $item['status'] ?? '';
                                    $uncertain = !empty($item['uncertain']);
                                    $editable = is_admin() || $userId === current_user_id();
                                    $isCurrentUser = $userId === current_user_id();
                                    $specialDayClass = getSpecialDayClass($member, $day['date']);
                                    ?>

                                    <div class="desktop-paper-mark-wrap <?= $isCurrentUser ? 'current-user-column' : '' ?> <?= e($specialDayClass) ?>">
                                        <?php if ($eventId): ?>
                                            <button
                                                type="button"
                                                class="member-cell split-availability-cell desktop-paper-mark <?= $editable ? 'editable' : '' ?>"
                                                data-split-event-id="<?= $eventId ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="member-cell availability-cell desktop-paper-mark <?= $editable ? 'editable' : '' ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-date="<?= e($day['date']) ?>"
                                                data-period="<?= e($period) ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>></button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

    </div>
<?php
}

function desktopPaperRenderActivities(
    string $period,
    array $week,
    array $splitEvents,
    bool $isAdmin
): void {
    $periodLabel = $period === 'morning' ? 'Morning' : 'Evening';
?>
    <div class="desktop-paper-activities desktop-paper-activities-<?= e($period) ?>">

        <div class="desktop-paper-activities-label">
            <?= e($periodLabel) ?>
        </div>

        <div class="desktop-paper-activities-days">

            <?php foreach ($week as $day): ?>
                <?php
                $events = slotEvents($day, $period, $splitEvents);
                $eventCount = max(1, count($events));
                ?>

                <div class="desktop-paper-activity-day <?= e(desktopPaperDayClass($day)) ?>">
                    <div
                        class="desktop-paper-event-grid"
                        style="--event-count: <?= $eventCount ?>">
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventId = $event['id'] !== null ? (int) $event['id'] : null;
                            $activity = trim((string) ($event['activity'] ?? ''));
                            ?>

                            <div
                                class="desktop-paper-activity activity-cell
                                    <?= $period === 'morning' ? 'morning-activity' : 'evening-activity' ?>
                                    <?= (!$eventId && $isAdmin) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>
                                    <?= $activity === '' ? 'is-empty-event' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="<?= e($period) ?>"
                                data-split-event-id="<?= $eventId ?: '' ?>">


                                <?php
                                $activityParts =
                                    desktopPaperActivityParts(
                                        $activity
                                    );
                                ?>

                                <span class="desktop-paper-activity-text">

                                    <?php if ($activity === ''): ?>

                                        <span class="desktop-paper-activity-empty">
                                            —
                                        </span>

                                    <?php else: ?>

                                        <?php if ($activityParts['time'] !== ''): ?>

                                            <strong class="desktop-paper-activity-time">
                                                <?= e($activityParts['time']) ?>
                                            </strong>

                                        <?php endif; ?>

                                        <?php if ($activityParts['title'] !== ''): ?>

                                            <span class="desktop-paper-activity-title">
                                                <?= e($activityParts['title']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if ($activityParts['details'] !== ''): ?>

                                            <span class="desktop-paper-activity-details">
                                                <?= e($activityParts['details']) ?>
                                            </span>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

    </div>
<?php
}
?>

<div class="desktop-schedule desktop-paper-schedule">

    <?php foreach ($desktopWeeks as $week): ?>
        <?php
        $mondayDate = $week[0]['date'] ?? null;
        $weekNumber = desktopPaperWeekNumber($week);

        $eveningWeekPoints = [];

        if ($mondayDate) {
            foreach ($members as $member) {
                $userId = (int) $member['id'];

                $eveningWeekPoints[$userId] =
                    $weeklyEveningPoints[$mondayDate][$userId]
                    ?? $member['evening_starting_points']
                    ?? 0;
            }
        }

        $morningWeekPoints = $runningMorningPoints;
        ?>

        <article class="desktop-paper-week">

            <header class="desktop-paper-week-header">
                <div class="desktop-paper-week-title">
                    Week <?= e($weekNumber) ?>
                </div>

                <div class="desktop-paper-day-headings">
                    <?php foreach ($week as $day): ?>
                        <div class="desktop-paper-day-heading <?= e(desktopPaperDayClass($day)) ?>">
                            <strong><?= e(strtoupper($day['weekday_short'])) ?></strong>
                            <span><?= (new DateTime($day['date']))->format('j/n') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </header>

            <?php
            /* 1. Morning names + crosses/dots */
            desktopPaperRenderRoster(
                'morning',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $morningWeekPoints
            );

            /* 2. Morning activities directly above dates */
            desktopPaperRenderActivities(
                'morning',
                $week,
                $splitEvents,
                is_admin()
            );
            ?>

            <!-- 3. Dates -->
            <div class="desktop-paper-date-axis">
                <div class="desktop-paper-date-label">Date</div>

                <div class="desktop-paper-date-days">
                    <?php foreach ($week as $day): ?>
                        <div class="desktop-paper-date-cell <?= e(desktopPaperDayClass($day)) ?>">
                            <strong><?= (int) $day['day'] ?></strong>
                            <span><?= e(strtoupper($day['weekday_short'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            /* 4. Evening activities directly below dates */
            desktopPaperRenderActivities(
                'evening',
                $week,
                $splitEvents,
                is_admin()
            );

            /* 5. Evening names + crosses/dots */
            desktopPaperRenderRoster(
                'evening',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $eveningWeekPoints
            );
            ?>

        </article>
    <?php endforeach; ?>

</div>