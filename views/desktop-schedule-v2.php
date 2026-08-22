<?php
/*
|--------------------------------------------------------------------------
| Desktop schedule v2
|--------------------------------------------------------------------------
|
| Paper-style weekly matrix:
| - Morning on top
| - Dates/events through the middle
| - Evening on the bottom
| - One week per block
| - Split events remain independently editable
|
*/

$weeks = array_chunk($days, 7);

function desktopEventAvailability(
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
?>

<div class="desktop-schedule desktop-schedule-v2">

    <?php foreach ($weeks as $week): ?>

        <?php
        $mondayDate =
            $week[0]['date'] ?? null;

        $weekEveningPoints = [];

        if ($mondayDate) {
            foreach ($members as $member) {
                $userId =
                    (int) $member['id'];

                $weekEveningPoints[$userId] =
                    $weeklyEveningPoints[$mondayDate][$userId]
                    ?? $member['evening_starting_points']
                    ?? 0;
            }
        }
        ?>

        <section class="desktop-week">

            <!-- Sticky names / labels -->

            <div class="desktop-week-grid desktop-week-names">

                <div class="desktop-side-label">
                    Morning
                </div>

                <div class="desktop-points-label">
                    Pts
                </div>

                <?php foreach ($week as $day): ?>
                    <div class="desktop-day-name">
                        <?= e(strtoupper($day['weekday_short'])) ?>
                    </div>
                <?php endforeach; ?>

            </div>

            <!-- Morning member rows -->

            <div class="desktop-period desktop-period-morning">

                <?php foreach ($members as $member): ?>

                    <?php
                    $userId =
                        (int) $member['id'];

                    $isCurrentUser =
                        $userId === current_user_id();
                    ?>

                    <div class="desktop-week-grid desktop-member-row <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>">

                        <div class="desktop-member-name">
                            <?= e($member['name']) ?>
                        </div>

                        <div class="desktop-member-points">
                            <?= (int) ($runningMorningPoints[$userId] ?? 0) ?>
                        </div>

                        <?php foreach ($week as $day): ?>

                            <?php
                            $events =
                                slotEvents(
                                    $day,
                                    'morning',
                                    $splitEvents
                                );
                            ?>

                            <div class="desktop-day-availability <?= e(getSpecialDayClass($member, $day['date'])) ?>">

                                <?php foreach ($events as $event): ?>

                                    <?php
                                    $eventId =
                                        $event['id'] !== null
                                        ? (int) $event['id']
                                        : null;

                                    $item =
                                        desktopEventAvailability(
                                            $event,
                                            $day,
                                            'morning',
                                            $userId,
                                            $availability,
                                            $splitAvailability
                                        );

                                    $status =
                                        $item['status']
                                        ?? '';

                                    $uncertain =
                                        !empty(
                                            $item['uncertain']
                                        );

                                    $editable =
                                        is_admin()
                                        ||
                                        $userId === current_user_id();
                                    ?>

                                    <?php if ($eventId): ?>

                                        <button
                                            type="button"
                                            class="member-cell split-availability-cell desktop-matrix-cell <?= $editable ? 'editable' : '' ?>"
                                            data-split-event-id="<?= $eventId ?>"
                                            data-user-id="<?= $userId ?>"
                                            data-status="<?= e($status) ?>"
                                            data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                            <?= !$editable ? 'disabled' : '' ?>
                                        ></button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="member-cell availability-cell desktop-matrix-cell <?= $editable ? 'editable' : '' ?>"
                                            data-user-id="<?= $userId ?>"
                                            data-date="<?= e($day['date']) ?>"
                                            data-period="morning"
                                            data-status="<?= e($status) ?>"
                                            data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                            <?= !$editable ? 'disabled' : '' ?>
                                        ></button>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- Morning event text -->

            <div class="desktop-week-grid desktop-event-row desktop-event-row-morning">

                <div class="desktop-event-side-label">
                    Morning events
                </div>

                <div class="desktop-event-points-spacer"></div>

                <?php foreach ($week as $day): ?>

                    <?php
                    $events =
                        slotEvents(
                            $day,
                            'morning',
                            $splitEvents
                        );
                    ?>

                    <div class="desktop-day-events">

                        <?php foreach ($events as $event): ?>

                            <?php
                            $eventId =
                                $event['id'] !== null
                                ? (int) $event['id']
                                : null;
                            ?>

                            <div
                                class="desktop-event-item activity-cell morning-activity
                                    <?= (!$eventId && is_admin()) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="morning"
                                data-split-event-id="<?= $eventId ?: '' ?>"
                            >
                                <?= e($event['activity']) ?>
                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- Date axis -->

            <div class="desktop-week-grid desktop-date-axis">

                <div class="desktop-date-axis-label">
                    Date
                </div>

                <div class="desktop-date-axis-spacer"></div>

                <?php foreach ($week as $day): ?>

                    <div class="desktop-date-box <?= $day['date'] === date('Y-m-d') ? 'is-today' : '' ?>">

                        <div class="desktop-date-number">
                            <?= (int) $day['day'] ?>
                        </div>

                        <div class="desktop-date-weekday">
                            <?= e(strtoupper($day['weekday_short'])) ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- Evening event text -->

            <div class="desktop-week-grid desktop-event-row desktop-event-row-evening">

                <div class="desktop-event-side-label">
                    Evening events
                </div>

                <div class="desktop-event-points-spacer"></div>

                <?php foreach ($week as $day): ?>

                    <?php
                    $events =
                        slotEvents(
                            $day,
                            'evening',
                            $splitEvents
                        );
                    ?>

                    <div class="desktop-day-events">

                        <?php foreach ($events as $event): ?>

                            <?php
                            $eventId =
                                $event['id'] !== null
                                ? (int) $event['id']
                                : null;
                            ?>

                            <div
                                class="desktop-event-item activity-cell evening-activity
                                    <?= (!$eventId && is_admin()) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="evening"
                                data-split-event-id="<?= $eventId ?: '' ?>"
                            >
                                <?= e($event['activity']) ?>
                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- Evening member rows -->

            <div class="desktop-period desktop-period-evening">

                <?php foreach ($members as $member): ?>

                    <?php
                    $userId =
                        (int) $member['id'];

                    $isCurrentUser =
                        $userId === current_user_id();
                    ?>

                    <div class="desktop-week-grid desktop-member-row <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>">

                        <div class="desktop-member-name">
                            <?= e($member['name']) ?>
                        </div>

                        <div class="desktop-member-points">
                            <?= (int) ($weekEveningPoints[$userId] ?? 0) ?>
                        </div>

                        <?php foreach ($week as $day): ?>

                            <?php
                            $events =
                                slotEvents(
                                    $day,
                                    'evening',
                                    $splitEvents
                                );
                            ?>

                            <div class="desktop-day-availability <?= e(getSpecialDayClass($member, $day['date'])) ?>">

                                <?php foreach ($events as $event): ?>

                                    <?php
                                    $eventId =
                                        $event['id'] !== null
                                        ? (int) $event['id']
                                        : null;

                                    $item =
                                        desktopEventAvailability(
                                            $event,
                                            $day,
                                            'evening',
                                            $userId,
                                            $availability,
                                            $splitAvailability
                                        );

                                    $status =
                                        $item['status']
                                        ?? '';

                                    $uncertain =
                                        !empty(
                                            $item['uncertain']
                                        );

                                    $editable =
                                        is_admin()
                                        ||
                                        $userId === current_user_id();
                                    ?>

                                    <?php if ($eventId): ?>

                                        <button
                                            type="button"
                                            class="member-cell split-availability-cell desktop-matrix-cell <?= $editable ? 'editable' : '' ?>"
                                            data-split-event-id="<?= $eventId ?>"
                                            data-user-id="<?= $userId ?>"
                                            data-status="<?= e($status) ?>"
                                            data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                            <?= !$editable ? 'disabled' : '' ?>
                                        ></button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="member-cell availability-cell desktop-matrix-cell <?= $editable ? 'editable' : '' ?>"
                                            data-user-id="<?= $userId ?>"
                                            data-date="<?= e($day['date']) ?>"
                                            data-period="evening"
                                            data-status="<?= e($status) ?>"
                                            data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                            <?= !$editable ? 'disabled' : '' ?>
                                        ></button>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endforeach; ?>

</div>
