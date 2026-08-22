<?php
/*
|--------------------------------------------------------------------------
| Desktop schedule v3
|--------------------------------------------------------------------------
|
| One complete week per desktop block:
| - Monday → Sunday across the screen
| - Morning on top
| - Date axis in the middle
| - Evening on the bottom
| - Event text vertically inside each event column
| - Split events become side-by-side mini-columns
|
*/

$desktopWeeks = array_chunk($days, 7);

function desktopV3Availability(
    array $event,
    array $day,
    string $period,
    int $userId,
    array $availability,
    array $splitAvailability
): array {
    $eventId =
        $event['id'] !== null
        ? (int) $event['id']
        : null;

    if ($eventId) {
        return $splitAvailability[$eventId][$userId]
            ?? [
                'status' => '',
                'uncertain' => false,
            ];
    }

    return $availability[$day['date']][$period][$userId]
        ?? [
            'status' => '',
            'uncertain' => false,
        ];
}

function desktopV3WeekNumber(array $week): string
{
    if (empty($week[0]['date'])) {
        return '';
    }

    return (new DateTime($week[0]['date']))->format('W');
}

function desktopV3RenderPeriod(
    string $period,
    array $week,
    array $members,
    array $availability,
    array $splitEvents,
    array $splitAvailability,
    array $points,
    bool $isAdmin
): void {
    $label =
        $period === 'morning'
        ? 'Morning'
        : 'Evening';

    $periodClass =
        $period === 'morning'
        ? 'desktop-v3-period-morning'
        : 'desktop-v3-period-evening';
?>

    <section class="desktop-v3-period <?= e($periodClass) ?>">

        <div class="desktop-v3-roster">

            <div class="desktop-v3-period-title">
                <?= e($label) ?>
            </div>



            <?php foreach ($members as $member): ?>

                <?php
                $userId =
                    (int) $member['id'];

                $isCurrentUser =
                    $userId === current_user_id();
                ?>

                <div class="desktop-v3-roster-row <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>">

                    <span class="desktop-v3-roster-name">
                        <?= e($member['name']) ?>
                    </span>

                    <strong class="desktop-v3-roster-points">
                        <?= (int) ($points[$userId] ?? 0) ?>
                    </strong>

                </div>

            <?php endforeach; ?>

        </div>

        <div class="desktop-v3-days">

            <?php foreach ($week as $day): ?>

                <?php
                $events =
                    slotEvents(
                        $day,
                        $period,
                        $splitEvents
                    );

                $eventCount =
                    max(
                        1,
                        count($events)
                    );
                ?>

                <div class="desktop-v3-day <?= in_array($day['weekday'], ['Saturday', 'Sunday'], true) ? 'is-weekend' : '' ?>">

                    <div
                        class="desktop-v3-events"
                        style="--event-count: <?= $eventCount ?>">

                        <?php foreach ($events as $event): ?>

                            <?php
                            $eventId =
                                $event['id'] !== null
                                ? (int) $event['id']
                                : null;

                            $activity =
                                trim(
                                    (string) (
                                        $event['activity']
                                        ?? ''
                                    )
                                );
                            ?>

                            <div class="desktop-v3-event-column">

                                <div
                                    class="desktop-v3-event-label activity-cell
                                        <?= $period === 'morning' ? 'morning-activity' : 'evening-activity' ?>
                                        <?= (!$eventId && $isAdmin) ? 'activity-editable' : '' ?>
                                        <?= $eventId ? 'split-activity-cell' : '' ?>
                                        <?= $activity === '' ? 'is-empty-event' : '' ?>"
                                    data-date="<?= e($day['date']) ?>"
                                    data-period="<?= e($period) ?>"
                                    data-split-event-id="<?= $eventId ?: '' ?>">
                                    <span class="desktop-v3-event-label-inner">
                                        <?= $activity !== '' ? e($activity) : '—' ?>
                                    </span>
                                </div>

                                <div class="desktop-v3-event-availability">

                                    <?php foreach ($members as $member): ?>

                                        <?php
                                        $userId =
                                            (int) $member['id'];

                                        $item =
                                            desktopV3Availability(
                                                $event,
                                                $day,
                                                $period,
                                                $userId,
                                                $availability,
                                                $splitAvailability
                                            );

                                        $status =
                                            $item['status']
                                            ?? '';

                                        $uncertain =
                                            !empty($item['uncertain']);

                                        $editable =
                                            is_admin()
                                            ||
                                            $userId === current_user_id();

                                        $isCurrentUser =
                                            $userId === current_user_id();

                                        $specialDayClass =
                                            getSpecialDayClass(
                                                $member,
                                                $day['date']
                                            );
                                        ?>

                                        <div class="desktop-v3-mark-wrap
                                            <?= $isCurrentUser ? 'current-user-column' : '' ?>
                                            <?= e($specialDayClass) ?>">

                                            <?php if ($eventId): ?>

                                                <button
                                                    type="button"
                                                    class="member-cell split-availability-cell desktop-v3-mark <?= $editable ? 'editable' : '' ?>"
                                                    data-split-event-id="<?= $eventId ?>"
                                                    data-user-id="<?= $userId ?>"
                                                    data-status="<?= e($status) ?>"
                                                    data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                    <?= !$editable ? 'disabled' : '' ?>></button>

                                            <?php else: ?>

                                                <button
                                                    type="button"
                                                    class="member-cell availability-cell desktop-v3-mark <?= $editable ? 'editable' : '' ?>"
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

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

<?php
}
?>

<div class="desktop-schedule desktop-schedule-v3">

    <?php foreach ($desktopWeeks as $weekIndex => $week): ?>

        <?php
        $mondayDate =
            $week[0]['date']
            ?? null;

        $weekNumber =
            desktopV3WeekNumber(
                $week
            );

        $eveningWeekPoints = [];

        if ($mondayDate) {
            foreach ($members as $member) {
                $userId =
                    (int) $member['id'];

                $eveningWeekPoints[$userId] =
                    $weeklyEveningPoints[$mondayDate][$userId]
                    ?? $member['evening_starting_points']
                    ?? 0;
            }
        }

        /*
        | Morning points are still the values currently supplied by the existing
        | application. Once Morning point accumulation is implemented, this view
        | can consume the weekly Morning totals in exactly the same way.
        */
        $morningWeekPoints =
            $runningMorningPoints;
        ?>

        <article class="desktop-v3-week">

            <header class="desktop-v3-week-header">

                <div class="desktop-v3-week-number">
                    Week <?= e($weekNumber) ?>
                </div>

                <div class="desktop-v3-day-headings">

                    <?php foreach ($week as $day): ?>

                        <?php
                        $isToday =
                            $day['date']
                            === date('Y-m-d');

                        $isWeekend =
                            in_array(
                                $day['weekday'],
                                ['Saturday', 'Sunday'],
                                true
                            );
                        ?>

                        <div class="desktop-v3-day-heading
                            <?= $isToday ? 'is-today' : '' ?>
                            <?= $isWeekend ? 'is-weekend' : '' ?>">

                            <strong>
                                <?= e(strtoupper($day['weekday_short'])) ?>
                            </strong>

                            <span>
                                <?= (new DateTime($day['date']))->format('j/n') ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </header>

            <?php
            desktopV3RenderPeriod(
                'morning',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $morningWeekPoints,
                is_admin()
            );
            ?>

            <div class="desktop-v3-date-axis">

                <div class="desktop-v3-date-label">
                    Date
                </div>

                <div class="desktop-v3-date-days">

                    <?php foreach ($week as $day): ?>

                        <?php
                        $isToday =
                            $day['date']
                            === date('Y-m-d');
                        ?>

                        <div class="desktop-v3-date-cell <?= $isToday ? 'is-today' : '' ?>">

                            <strong>
                                <?= (int) $day['day'] ?>
                            </strong>

                            <span>
                                <?= e(strtoupper($day['weekday_short'])) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

            <?php
            desktopV3RenderPeriod(
                'evening',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $eveningWeekPoints,
                is_admin()
            );
            ?>

        </article>

    <?php endforeach; ?>

</div>