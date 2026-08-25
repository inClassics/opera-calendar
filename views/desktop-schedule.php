<?php

$desktopWeeks =
    array_chunk(
        $days,
        7
    );

function desktopPaperPointEditor(
    array $pointItem
): void {
    if (!is_admin()) {
        return;
    }

    $pointType =
        $pointItem['point_type']
        ?? '';
?>
    <div
        class="activity-point-editor"
        data-point-source="<?= e($pointItem['source_type']) ?>"
        data-point-id="<?= (int) $pointItem['source_id'] ?>"
        data-point-type="<?= e($pointType) ?>">

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
                class="activity-point-type-button <?= $pointType === 'rehearsal' ? 'selected' : '' ?>"
                data-point-type="rehearsal"
                title="Rehearsal points">
                R
            </button>

            <button
                type="button"
                class="activity-point-type-button <?= $pointType === 'performance' ? 'selected' : '' ?>"
                data-point-type="performance"
                title="Performance points">
                P
            </button>

        </div>

    </div>
<?php
}

function desktopPaperPointBadge(
    ?array $pointItem
): void {
    if (!$pointItem) {
        return;
    }

    $pointValue =
        (float) (
            $pointItem['point_value']
            ?? 0
        );

    $pointType =
        $pointItem['point_type']
        ?? null;

    if (
        $pointValue <= 0
        ||
        !in_array(
            $pointType,
            [
                'rehearsal',
                'performance',
            ],
            true
        )
    ) {
        return;
    }

    $letter =
        $pointType === 'rehearsal'
        ? 'R'
        : 'P';

    $label =
        $pointType === 'rehearsal'
        ? 'Rehearsal'
        : 'Performance';
?>
    <div
        class="desktop-paper-point-badge desktop-paper-point-badge-<?= e($pointType) ?>"
        title="<?= e(
                    $label
                        .
                        ' · '
                        .
                        format_points($pointValue)
                        .
                        (
                            $pointValue == 1
                            ? ' point'
                            : ' points'
                        )
                ) ?>">

        <span class="desktop-paper-point-letter">
            <?= e($letter) ?>
        </span>

        <span class="desktop-paper-point-divider">
            ·
        </span>

        <strong class="desktop-paper-point-number">
            <?= e(format_points($pointValue)) ?>
        </strong>

    </div>
<?php
}

function desktopPaperAvailability(
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
        return
            $splitAvailability[$eventId][$userId]
            ??
            [
                'status' => '',
                'uncertain' => false,
                'counts_for_points' => true,
            ];
    }

    return
        $availability[$day['date']][$period][$userId]
        ??
        [
            'status' => '',
            'uncertain' => false,
            'counts_for_points' => true,
        ];
}

function desktopPaperWeekNumber(
    array $week
): string {
    if (
        empty($week[0]['date'])
    ) {
        return '';
    }

    return (
        new DateTime(
            $week[0]['date']
        )
    )->format('W');
}

/*
|--------------------------------------------------------------------------
| Does this date contain any real activity?
|--------------------------------------------------------------------------
|
| Monday is normally a day off.
|
| If either the morning or evening contains an activity, Monday changes
| from green to bright orange.
|
*/

function desktopPaperDayHasActivity(
    array $day,
    array $splitEvents = [],
    array $activityPointItems = []
): bool {
    $date =
        $day['date']
        ?? '';

    /*
    |--------------------------------------------------------------------------
    | Normal/manual/imported activity
    |--------------------------------------------------------------------------
    */

    if (
        trim(
            (string) (
                $day['morning']
                ?? ''
            )
        )
        !== ''
    ) {
        return true;
    }

    if (
        trim(
            (string) (
                $day['evening']
                ?? ''
            )
        )
        !== ''
    ) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Split events
    |--------------------------------------------------------------------------
    */

    if (
        !empty($splitEvents[$date]['morning']
            ?? [])
    ) {
        return true;
    }

    if (
        !empty($splitEvents[$date]['evening']
            ?? [])
    ) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Point-linked calendar activity fallback
    |--------------------------------------------------------------------------
    */

    if (
        !empty($activityPointItems[$date]['morning']
            ?? [])
    ) {
        return true;
    }

    if (
        !empty($activityPointItems[$date]['evening']
            ?? [])
    ) {
        return true;
    }

    return false;
}

function desktopPaperDayClass(
    array $day,
    array $splitEvents = [],
    array $activityPointItems = []
): string {
    $classes =
        [];

    if (
        in_array(
            $day['weekday'],
            [
                'Saturday',
                'Sunday',
            ],
            true
        )
    ) {
        $classes[] =
            'is-weekend';
    }

    /*
    |--------------------------------------------------------------------------
    | Monday
    |--------------------------------------------------------------------------
    */

    if (
        ($day['weekday'] ?? '')
        === 'Monday'
    ) {
        $classes[] =
            'is-monday';

        if (
            desktopPaperDayHasActivity(
                $day,
                $splitEvents,
                $activityPointItems
            )
        ) {
            $classes[] =
                'monday-has-event';
        } else {
            $classes[] =
                'monday-day-off';
        }
    }

    if (
        $day['date']
        ===
        date('Y-m-d')
    ) {
        $classes[] =
            'is-today';
    }

    return
        implode(
            ' ',
            $classes
        );
}

function desktopPaperRenderRoster(
    string $period,
    array $week,
    array $members,
    array $availability,
    array $splitEvents,
    array $splitAvailability,
    array $activityPointItems,
    array $points
): void {
?>
    <div class="desktop-paper-roster">

        <div class="desktop-paper-roster-left">

            <div class="desktop-paper-roster-labels">

                <span>
                    Name
                </span>

                <span>
                    <?= $period === 'morning'
                        ? 'Rehearsal '
                        : 'Concert ' ?>Pts
                </span>

            </div>

            <?php foreach ($members as $member): ?>

                <?php
                $userId =
                    (int) $member['id'];

                $isCurrentUser =
                    $userId
                    ===
                    current_user_id();
                ?>

                <div
                    class="desktop-paper-person <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>">

                    <span class="desktop-paper-person-name">
                        <?= e($member['name']) ?>
                    </span>

                    <strong class="desktop-paper-person-points">
                        <?= e(
                            format_points(
                                $points[$userId]
                                    ?? 0
                            )
                        ) ?>
                    </strong>

                </div>

            <?php endforeach; ?>

        </div>

        <div class="desktop-paper-roster-days">

            <?php foreach ($week as $day): ?>

                <?php
                $events =
                    slotEvents(
                        $day,
                        $period,
                        $splitEvents,
                        $activityPointItems
                    );

                $eventCount =
                    max(
                        1,
                        count($events)
                    );

                $dayClass =
                    desktopPaperDayClass(
                        $day,
                        $splitEvents,
                        $activityPointItems
                    );
                ?>

                <div
                    class="desktop-paper-roster-day <?= e($dayClass) ?>">

                    <div
                        class="desktop-paper-event-grid"
                        style="--event-count: <?= $eventCount ?>">

                        <?php foreach ($events as $event): ?>

                            <?php
                            $eventId =
                                $event['id'] !== null
                                ? (int) $event['id']
                                : null;
                            ?>

                            <div class="desktop-paper-event-marks">

                                <?php foreach ($members as $member): ?>

                                    <?php
                                    $userId =
                                        (int) $member['id'];

                                    $item =
                                        desktopPaperAvailability(
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

                                    $countsForPoints =
                                        $item['counts_for_points']
                                        ?? true;

                                    $editable =
                                        is_admin()
                                        ||
                                        $userId
                                        ===
                                        current_user_id();

                                    $isCurrentUser =
                                        $userId
                                        ===
                                        current_user_id();

                                    $specialDayClass =
                                        getSpecialDayClass(
                                            $member,
                                            $day['date']
                                        );
                                    ?>

                                    <div
                                        class="desktop-paper-mark-wrap
                                            <?= $isCurrentUser ? 'current-user-column' : '' ?>
                                            <?= e($specialDayClass) ?>">

                                        <?php if ($eventId): ?>

                                            <button
                                                type="button"
                                                class="member-cell split-availability-cell desktop-paper-mark <?= $editable ? 'editable' : '' ?>"
                                                data-split-event-id="<?= $eventId ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                data-counts-for-points="<?= $countsForPoints ? '1' : '0' ?>"
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
                                                data-counts-for-points="<?= $countsForPoints ? '1' : '0' ?>"
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
    array $activityPointItems,
    bool $isAdmin
): void {
    $periodLabel =
        $period === 'morning'
        ? 'Morning'
        : 'Evening';
?>
    <div
        class="desktop-paper-activities desktop-paper-activities-<?= e($period) ?>">

        <div class="desktop-paper-activities-label">
            <?= e($periodLabel) ?>
        </div>

        <div class="desktop-paper-activities-days">

            <?php foreach ($week as $day): ?>

                <?php
                $events =
                    slotEvents(
                        $day,
                        $period,
                        $splitEvents,
                        $activityPointItems
                    );

                $eventCount =
                    max(
                        1,
                        count($events)
                    );

                $dayClass =
                    desktopPaperDayClass(
                        $day,
                        $splitEvents,
                        $activityPointItems
                    );
                ?>

                <div
                    class="desktop-paper-activity-day <?= e($dayClass) ?>">

                    <div
                        class="desktop-paper-event-grid"
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

                            $pointItems =
                                $event['point_items']
                                ?? [];

                            if (
                                count($pointItems)
                                > 1
                            ) {
                                $displayItems =
                                    array_map(
                                        static fn(array $item): array => [
                                            'activity' =>
                                            $item['activity']
                                                ?? '',

                                            'point_item' =>
                                            $item,
                                        ],
                                        $pointItems
                                    );
                            } else {
                                $displayItems = [
                                    [
                                        'activity' =>
                                        $activity,

                                        'point_item' =>
                                        $pointItems[0]
                                            ?? null,
                                    ],
                                ];
                            }
                            ?>

                            <div
                                class="desktop-paper-activity activity-cell
                                    <?= $period === 'morning' ? 'morning-activity' : 'evening-activity' ?>
                                    <?= (!$eventId && $isAdmin) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>
                                    <?= $activity === '' ? 'is-empty-event' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="<?= e($period) ?>"
                                data-split-event-id="<?= $eventId ?: '' ?>"
                                data-activity-raw="<?= e($activity) ?>"
                                data-event-count="<?= max(1, count($pointItems)) ?>">

                                <div class="desktop-paper-activity-items">

                                    <?php foreach ($displayItems as $displayItem): ?>

                                        <?php
                                        $displayActivity =
                                            trim(
                                                (string) (
                                                    $displayItem['activity']
                                                    ?? ''
                                                )
                                            );

                                        $pointItem =
                                            $displayItem['point_item']
                                            ?? null;
                                        ?>

                                        <div class="desktop-paper-activity-item">

                                            <span class="desktop-paper-activity-text">

                                                <?php if ($displayActivity === ''): ?>

                                                    <span class="desktop-paper-activity-empty">
                                                        —
                                                    </span>

                                                <?php elseif (
                                                    str_contains(
                                                        $displayActivity,
                                                        '**'
                                                    )
                                                    ||
                                                    preg_match(
                                                        '/(?<!\*)\*[^*\r\n]+\*(?!\*)/u',
                                                        $displayActivity
                                                    )
                                                    ||
                                                    str_contains(
                                                        $displayActivity,
                                                        "\n"
                                                    )
                                                ): ?>

                                                    <span class="desktop-paper-activity-custom">
                                                        <?= renderActivityMarkup(
                                                            $displayActivity
                                                        ) ?>
                                                    </span>

                                                <?php else: ?>

                                                    <?php
                                                    $parts =
                                                        desktopPaperActivityParts(
                                                            $displayActivity
                                                        );
                                                    ?>

                                                    <?php if ($parts['time'] !== ''): ?>

                                                        <strong class="desktop-paper-activity-time">
                                                            <?= e($parts['time']) ?>
                                                        </strong>

                                                    <?php endif; ?>

                                                    <?php if ($parts['title'] !== ''): ?>

                                                        <span class="desktop-paper-activity-title">
                                                            <?= e($parts['title']) ?>
                                                        </span>

                                                    <?php endif; ?>

                                                    <?php if ($parts['details'] !== ''): ?>

                                                        <span class="desktop-paper-activity-details">
                                                            <?= e($parts['details']) ?>
                                                        </span>

                                                    <?php endif; ?>

                                                <?php endif; ?>

                                            </span>

                                            <?php
                                            desktopPaperPointBadge(
                                                $pointItem
                                            );
                                            ?>

                                            <?php
                                            if ($pointItem) {
                                                desktopPaperPointEditor(
                                                    $pointItem
                                                );
                                            }
                                            ?>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

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
        $mondayDate =
            $week[0]['date']
            ?? null;

        $weekNumber =
            desktopPaperWeekNumber(
                $week
            );

        $performanceWeekPoints =
            $weeklyPerformancePoints[$mondayDate]
            ??
            array_column(
                $members,
                'evening_starting_points',
                'id'
            );

        $rehearsalWeekPoints =
            $weeklyRehearsalPoints[$mondayDate]
            ??
            array_column(
                $members,
                'morning_starting_points',
                'id'
            );
        ?>

        <article class="desktop-paper-week">

            <?php
            desktopPaperRenderRoster(
                'morning',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $activityPointItems,
                $rehearsalWeekPoints
            );

            desktopPaperRenderActivities(
                'morning',
                $week,
                $splitEvents,
                $activityPointItems,
                is_admin()
            );
            ?>

            <div class="desktop-paper-date-axis">

                <div class="desktop-paper-date-label">
                    Date
                </div>

                <div class="desktop-paper-date-days">

                    <?php foreach ($week as $day): ?>

                        <?php
                        $dayClass =
                            desktopPaperDayClass(
                                $day,
                                $splitEvents,
                                $activityPointItems
                            );
                        ?>

                        <div
                            class="desktop-paper-date-cell <?= e($dayClass) ?>">

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
            desktopPaperRenderActivities(
                'evening',
                $week,
                $splitEvents,
                $activityPointItems,
                is_admin()
            );

            desktopPaperRenderRoster(
                'evening',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $activityPointItems,
                $performanceWeekPoints
            );
            ?>

        </article>

    <?php endforeach; ?>

</div>