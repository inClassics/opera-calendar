<?php

/*
|--------------------------------------------------------------------------
| Mobile overview
|--------------------------------------------------------------------------
|
| Same structure as desktop:
|   rehearsal points + morning availability
|   morning activities
|   date row
|   evening activities
|   concert points + evening availability
|
*/

$mobileWeeks = array_chunk($mobileDays, 7);

function mobileOverviewDayHasActivity(
    array $day,
    array $splitEvents,
    array $activityPointItems
): bool {
    $date = $day['date'] ?? '';

    if (trim((string) ($day['morning'] ?? '')) !== '') {
        return true;
    }

    if (trim((string) ($day['evening'] ?? '')) !== '') {
        return true;
    }

    if (
        !empty($splitEvents[$date]['morning'] ?? [])
        || !empty($splitEvents[$date]['evening'] ?? [])
    ) {
        return true;
    }

    if (
        !empty($activityPointItems[$date]['morning'] ?? [])
        || !empty($activityPointItems[$date]['evening'] ?? [])
    ) {
        return true;
    }

    return false;
}

function mobileOverviewDayClass(
    array $day,
    array $splitEvents,
    array $activityPointItems
): string {
    $classes = [];

    if (
        in_array(
            $day['weekday'],
            ['Saturday', 'Sunday'],
            true
        )
    ) {
        $classes[] = 'is-weekend';
    }

    if (($day['weekday'] ?? '') === 'Monday') {
        $classes[] = 'is-monday';

        $classes[] = mobileOverviewDayHasActivity(
            $day,
            $splitEvents,
            $activityPointItems
        )
            ? 'monday-has-event'
            : 'monday-day-off';
    }

    if (($day['date'] ?? '') === date('Y-m-d')) {
        $classes[] = 'is-today';
    }

    return implode(' ', $classes);
}

function mobileOverviewAvailability(
    array $event,
    array $day,
    string $period,
    int $userId,
    array $availability,
    array $splitAvailability
): array {
    $eventId = $event['id'] !== null
        ? (int) $event['id']
        : null;

    if ($eventId) {
        return $splitAvailability[$eventId][$userId] ?? [
            'status' => '',
            'uncertain' => false,
            'counts_for_points' => true,
        ];
    }

    return $availability[$day['date']][$period][$userId] ?? [
        'status' => '',
        'uncertain' => false,
        'counts_for_points' => true,
    ];
}

function mobileOverviewPointBadge(?array $pointItem): void
{
    if (!$pointItem) {
        return;
    }

    $pointValue = (float) ($pointItem['point_value'] ?? 0);
    $pointType = $pointItem['point_type'] ?? null;

    if (
        $pointValue <= 0
        || !in_array(
            $pointType,
            ['rehearsal', 'performance'],
            true
        )
    ) {
        return;
    }

    $letter = $pointType === 'rehearsal' ? 'R' : 'P';
    $label = $pointType === 'rehearsal'
        ? 'Rehearsal'
        : 'Performance';
?>
    <span
        class="mobile-overview-point-badge mobile-overview-point-badge-<?= e($pointType) ?>"
        title="<?= e(
            $label
            . ' · '
            . format_points($pointValue)
            . ($pointValue == 1 ? ' point' : ' points')
        ) ?>"
    >
        <?= e($letter) ?> · <?= e(format_points($pointValue)) ?>
    </span>
<?php
}

function mobileOverviewRenderRoster(
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
    <div class="mobile-overview-roster">

        <div class="mobile-overview-roster-left">

            <div class="mobile-overview-roster-labels">
                <span>Name</span>

                <span>
                    <?= $period === 'morning' ? 'Reh.' : 'Conc.' ?>
                    pts
                </span>
            </div>

            <?php foreach ($members as $member): ?>
                <?php
                $userId = (int) $member['id'];

                $isCurrentUser =
                    $userId === current_user_id();
                ?>

                <div
                    class="mobile-overview-person <?= $isCurrentUser ? 'current-user-mobile-overview' : '' ?>"
                >
                    <span class="mobile-overview-person-name">
                        <?= e($member['name']) ?>
                    </span>

                    <strong class="mobile-overview-person-points">
                        <?= e(
                            format_points(
                                $points[$userId] ?? 0
                            )
                        ) ?>
                    </strong>
                </div>
            <?php endforeach; ?>

        </div>

        <div class="mobile-overview-days">

            <?php foreach ($week as $day): ?>
                <?php
                $events = slotEvents(
                    $day,
                    $period,
                    $splitEvents,
                    $activityPointItems
                );

                $eventCount = max(1, count($events));

                $dayClass = mobileOverviewDayClass(
                    $day,
                    $splitEvents,
                    $activityPointItems
                );
                ?>

                <div
                    class="mobile-overview-roster-day <?= e($dayClass) ?>"
                >
                    <div
                        class="mobile-overview-event-grid"
                        style="--event-count: <?= $eventCount ?>"
                    >
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventId = $event['id'] !== null
                                ? (int) $event['id']
                                : null;
                            ?>

                            <div class="mobile-overview-event-marks">

                                <div class="mobile-overview-mark-spacer"></div>

                                <?php foreach ($members as $member): ?>
                                    <?php
                                    $userId = (int) $member['id'];

                                    $item = mobileOverviewAvailability(
                                        $event,
                                        $day,
                                        $period,
                                        $userId,
                                        $availability,
                                        $splitAvailability
                                    );

                                    $status = $item['status'] ?? '';

                                    $uncertain =
                                        !empty($item['uncertain']);

                                    $countsForPoints =
                                        $item['counts_for_points'] ?? true;

                                    $editable =
                                        is_admin()
                                        || $userId === current_user_id();

                                    $isCurrentUser =
                                        $userId === current_user_id();

                                    $specialDayClass =
                                        getSpecialDayClass(
                                            $member,
                                            $day['date']
                                        );
                                    ?>

                                    <div
                                        class="mobile-member-row mobile-overview-mark-wrap
                                            <?= $isCurrentUser ? 'current-user-mobile-overview' : '' ?>
                                            <?= e($specialDayClass) ?>"
                                    >
                                        <?php if ($eventId): ?>

                                            <button
                                                type="button"
                                                class="member-cell split-availability-cell mobile-overview-mark <?= $editable ? 'editable' : '' ?>"
                                                data-split-event-id="<?= $eventId ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                data-counts-for-points="<?= $countsForPoints ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>
                                            ></button>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="member-cell availability-cell mobile-overview-mark <?= $editable ? 'editable' : '' ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-date="<?= e($day['date']) ?>"
                                                data-period="<?= e($period) ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                data-counts-for-points="<?= $countsForPoints ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>
                                            ></button>

                                        <?php endif; ?>

                                        <?php if ($editable): ?>
                                            <button
                                                type="button"
                                                class="mobile-options-button mobile-overview-options-button"
                                                aria-label="Options for <?= e($member['name']) ?>"
                                            >
                                                ⋯
                                            </button>
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

function mobileOverviewRenderActivities(
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
        class="mobile-overview-activities mobile-overview-activities-<?= e($period) ?>"
    >
        <div class="mobile-overview-activities-label">
            <?= e($periodLabel) ?>
        </div>

        <div class="mobile-overview-activity-days">

            <?php foreach ($week as $day): ?>
                <?php
                $events = slotEvents(
                    $day,
                    $period,
                    $splitEvents,
                    $activityPointItems
                );

                $eventCount = max(1, count($events));

                $dayClass = mobileOverviewDayClass(
                    $day,
                    $splitEvents,
                    $activityPointItems
                );
                ?>

                <div
                    class="mobile-overview-activity-day <?= e($dayClass) ?>"
                >
                    <div
                        class="mobile-overview-event-grid"
                        style="--event-count: <?= $eventCount ?>"
                    >
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventId = $event['id'] !== null
                                ? (int) $event['id']
                                : null;

                            $activity = trim(
                                (string) ($event['activity'] ?? '')
                            );

                            $pointItems =
                                $event['point_items'] ?? [];

                            if (count($pointItems) > 1) {
                                $displayItems = array_map(
                                    static fn(array $item): array => [
                                        'activity' =>
                                            $item['activity'] ?? '',
                                        'point_item' => $item,
                                    ],
                                    $pointItems
                                );
                            } else {
                                $displayItems = [[
                                    'activity' => $activity,
                                    'point_item' =>
                                        $pointItems[0] ?? null,
                                ]];
                            }
                            ?>

                            <div
                                class="mobile-overview-activity activity-cell
                                    <?= $period === 'morning' ? 'morning-activity' : 'evening-activity' ?>
                                    <?= (!$eventId && $isAdmin) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>
                                    <?= $activity === '' ? 'is-empty-event' : '' ?>"
                                data-date="<?= e($day['date']) ?>"
                                data-period="<?= e($period) ?>"
                                data-split-event-id="<?= $eventId ?: '' ?>"
                                data-activity-raw="<?= e($activity) ?>"
                                data-event-count="<?= max(1, count($pointItems)) ?>"
                            >
                                <?php foreach ($displayItems as $displayItem): ?>
                                    <?php
                                    $displayActivity = trim(
                                        (string) (
                                            $displayItem['activity'] ?? ''
                                        )
                                    );

                                    $pointItem =
                                        $displayItem['point_item'] ?? null;

                                    $parts =
                                        desktopPaperActivityParts(
                                            $displayActivity
                                        );
                                    ?>

                                    <div class="mobile-overview-activity-item">

                                        <div class="mobile-overview-activity-text">

                                            <?php if ($displayActivity === ''): ?>

                                                <span class="mobile-overview-empty">
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

                                                <span class="mobile-overview-custom">
                                                    <?= renderActivityMarkup(
                                                        $displayActivity
                                                    ) ?>
                                                </span>

                                            <?php else: ?>

                                                <?php if ($parts['time'] !== ''): ?>
                                                    <strong class="mobile-overview-activity-time">
                                                        <?= e($parts['time']) ?>
                                                    </strong>
                                                <?php endif; ?>

                                                <?php if ($parts['title'] !== ''): ?>
                                                    <span class="mobile-overview-activity-title">
                                                        <?= e($parts['title']) ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($parts['details'] !== ''): ?>
                                                    <span class="mobile-overview-activity-details">
                                                        <?= e($parts['details']) ?>
                                                    </span>
                                                <?php endif; ?>

                                            <?php endif; ?>

                                        </div>

                                        <?php
                                        mobileOverviewPointBadge(
                                            $pointItem
                                        );
                                        ?>

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
?>

<div class="mobile-schedule mobile-overview-schedule">

    <div class="mobile-overview-legend">
        <span>
            <strong class="available-mark">×</strong>
            available
        </span>

        <span>
            <strong class="unavailable-mark">•</strong>
            unavailable
        </span>

        <span>? uncertain</span>
        <span>×⁰ no points</span>
    </div>

    <?php foreach ($mobileWeeks as $week): ?>
        <?php
        if (empty($week)) {
            continue;
        }

        $mondayDate =
            $week[0]['date'] ?? null;

        $weekNumber = (
            new DateTime(
                $week[0]['date']
            )
        )->format('W');

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

        <article class="mobile-overview-week">

            <header class="mobile-overview-week-header">

                <div class="mobile-overview-week-label">
                    W<?= e($weekNumber) ?>
                </div>

                <div class="mobile-overview-day-headings">

                    <?php foreach ($week as $day): ?>
                        <?php
                        $dayClass = mobileOverviewDayClass(
                            $day,
                            $splitEvents,
                            $activityPointItems
                        );
                        ?>

                        <div
                            class="mobile-overview-day-heading <?= e($dayClass) ?>"
                        >
                            <strong>
                                <?= e(
                                    strtoupper(
                                        mb_substr(
                                            $day['weekday_short'],
                                            0,
                                            1
                                        )
                                    )
                                ) ?>
                            </strong>

                            <span>
                                <?= (int) $day['day'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                </div>

            </header>

            <?php
            mobileOverviewRenderRoster(
                'morning',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $activityPointItems,
                $rehearsalWeekPoints
            );

            mobileOverviewRenderActivities(
                'morning',
                $week,
                $splitEvents,
                $activityPointItems,
                is_admin()
            );
            ?>

            <div class="mobile-overview-date-axis">

                <div class="mobile-overview-date-label">
                    Date
                </div>

                <div class="mobile-overview-date-days">

                    <?php foreach ($week as $day): ?>
                        <?php
                        $dayClass = mobileOverviewDayClass(
                            $day,
                            $splitEvents,
                            $activityPointItems
                        );
                        ?>

                        <div
                            class="mobile-overview-date-cell <?= e($dayClass) ?>"
                        >
                            <strong>
                                <?= (int) $day['day'] ?>
                            </strong>

                            <span>
                                <?= e(
                                    strtoupper(
                                        mb_substr(
                                            $day['weekday_short'],
                                            0,
                                            1
                                        )
                                    )
                                ) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                </div>

            </div>

            <?php
            mobileOverviewRenderActivities(
                'evening',
                $week,
                $splitEvents,
                $activityPointItems,
                is_admin()
            );

            mobileOverviewRenderRoster(
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
