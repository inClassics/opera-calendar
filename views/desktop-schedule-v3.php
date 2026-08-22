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

function renderActivityMarkup(string $text): string
{
    $text = htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $text = preg_replace(
        '/\*\*(.+?)\*\*/su',
        '<strong>$1</strong>',
        $text
    );

    $text = preg_replace(
        '/(?<!\*)\*([^*\r\n]+?)\*(?!\*)/u',
        '<em>$1</em>',
        $text
    );

    return nl2br(
        $text,
        false
    );
}

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
                mb_strlen($activity)
                    -
                    mb_strlen($match[0])
            )
        );
    }

    return [
        'time' => $time,
        'title' => $activity,
        'details' => $details,
    ];
}

function desktopPaperPointEditor(array $pointItem): void
{
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

function desktopPaperPointBadge(?array $pointItem): void
{
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
                'performance'
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

    $title =
        ucfirst($pointType)
        .
        ' · '
        .
        format_points($pointValue)
        .
        (
            $pointValue == 1
            ? ' point'
            : ' points'
        );
?>

    <div
        class="
            desktop-paper-point-badge
            desktop-paper-point-badge-<?= e($pointType) ?>
        "
        title="<?= e($title) ?>">

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
            ];
    }

    return
        $availability[$day['date']][$period][$userId]
        ??
        [
            'status' => '',
            'uncertain' => false,
        ];
}

function desktopPaperWeekNumber(array $week): string
{
    if (empty($week[0]['date'])) {
        return '';
    }

    return (new DateTime($week[0]['date']))
        ->format('W');
}

function desktopPaperDayClass(array $day): string
{
    $classes = [];

    if (
        in_array(
            $day['weekday'],
            [
                'Saturday',
                'Sunday'
            ],
            true
        )
    ) {
        $classes[] =
            'is-weekend';
    }

    if (
        $day['date']
        ===
        date('Y-m-d')
    ) {
        $classes[] =
            'is-today';
    }

    return implode(
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
                $userId =
                    (int) $member['id'];

                $isCurrentUser =
                    $userId
                    ===
                    current_user_id();
                ?>

                <div
                    class="
                        desktop-paper-person
                        <?= $isCurrentUser ? 'current-user-desktop-row' : '' ?>
                    ">

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
                        $splitEvents
                    );

                $eventCount =
                    max(
                        1,
                        count($events)
                    );
                ?>

                <div
                    class="
                        desktop-paper-roster-day
                        <?= e(desktopPaperDayClass($day)) ?>
                    ">

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
                                        class="
                                            desktop-paper-mark-wrap
                                            <?= $isCurrentUser ? 'current-user-column' : '' ?>
                                            <?= e($specialDayClass) ?>
                                        ">

                                        <?php if ($eventId): ?>

                                            <button
                                                type="button"
                                                class="
                                                    member-cell
                                                    split-availability-cell
                                                    desktop-paper-mark
                                                    <?= $editable ? 'editable' : '' ?>
                                                "
                                                data-split-event-id="<?= $eventId ?>"
                                                data-user-id="<?= $userId ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>>
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="
                                                    member-cell
                                                    availability-cell
                                                    desktop-paper-mark
                                                    <?= $editable ? 'editable' : '' ?>
                                                "
                                                data-user-id="<?= $userId ?>"
                                                data-date="<?= e($day['date']) ?>"
                                                data-period="<?= e($period) ?>"
                                                data-status="<?= e($status) ?>"
                                                data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                <?= !$editable ? 'disabled' : '' ?>>
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

function desktopPaperRenderActivities(
    string $period,
    array $week,
    array $splitEvents,
    bool $isAdmin
): void {
    $periodLabel =
        $period === 'morning'
        ? 'Morning'
        : 'Evening';
?>

    <div
        class="
            desktop-paper-activities
            desktop-paper-activities-<?= e($period) ?>
        ">

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
                        $splitEvents
                    );

                $eventCount =
                    max(
                        1,
                        count($events)
                    );
                ?>

                <div
                    class="
                        desktop-paper-activity-day
                        <?= e(desktopPaperDayClass($day)) ?>
                    ">

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
                            ?>

                            <div
                                class="
                                    desktop-paper-activity
                                    activity-cell
                                    <?= $period === 'morning' ? 'morning-activity' : 'evening-activity' ?>
                                    <?= (!$eventId && $isAdmin) ? 'activity-editable' : '' ?>
                                    <?= $eventId ? 'split-activity-cell' : '' ?>
                                    <?= $activity === '' ? 'is-empty-event' : '' ?>
                                "
                                data-date="<?= e($day['date']) ?>"
                                data-period="<?= e($period) ?>"
                                data-split-event-id="<?= $eventId ?: '' ?>">

                                <?php
                                $pointItems =
                                    $event['point_items']
                                    ?? [];

                                /*
                                |--------------------------------------------------------------------------
                                | Several imported events can live in one physical slot
                                |--------------------------------------------------------------------------
                                */

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
                                                    $activityParts =
                                                        desktopPaperActivityParts(
                                                            $displayActivity
                                                        );
                                                    ?>

                                                    <?php if (
                                                        $activityParts['time']
                                                        !== ''
                                                    ): ?>

                                                        <strong class="desktop-paper-activity-time">
                                                            <?= e($activityParts['time']) ?>
                                                        </strong>

                                                    <?php endif; ?>

                                                    <?php if (
                                                        $activityParts['title']
                                                        !== ''
                                                    ): ?>

                                                        <span class="desktop-paper-activity-title">
                                                            <?= e($activityParts['title']) ?>
                                                        </span>

                                                    <?php endif; ?>

                                                    <?php if (
                                                        $activityParts['details']
                                                        !== ''
                                                    ): ?>

                                                        <span class="desktop-paper-activity-details">
                                                            <?= e($activityParts['details']) ?>
                                                        </span>

                                                    <?php endif; ?>

                                                <?php endif; ?>

                                            </span>

                                            <?php
                                            /*
                                            |--------------------------------------------------------------------------
                                            | Compact permanent badge
                                            |--------------------------------------------------------------------------
                                            |
                                            | Examples:
                                            |
                                            | R · 3
                                            | P · 2
                                            |
                                            */
                                            desktopPaperPointBadge(
                                                $pointItem
                                            );
                                            ?>

                                            <?php
                                            /*
                                            |--------------------------------------------------------------------------
                                            | Admin point editor
                                            |--------------------------------------------------------------------------
                                            */

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

        /*
        |--------------------------------------------------------------------------
        | Points displayed at beginning of week
        |--------------------------------------------------------------------------
        */

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

            <header class="desktop-paper-week-header">

                <div class="desktop-paper-week-title">
                    Week <?= e($weekNumber) ?>
                </div>

                <div class="desktop-paper-day-headings">

                    <?php foreach ($week as $day): ?>

                        <div
                            class="
                                desktop-paper-day-heading
                                <?= e(desktopPaperDayClass($day)) ?>
                            ">

                            <strong>
                                <?= e(
                                    strtoupper(
                                        $day['weekday_short']
                                    )
                                ) ?>
                            </strong>

                            <span>
                                <?= (
                                    new DateTime(
                                        $day['date']
                                    )
                                )->format('j/n') ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </header>

            <?php
            /*
            |--------------------------------------------------------------------------
            | 1. Morning availability
            |
            | Points shown = rehearsal points
            |--------------------------------------------------------------------------
            */

            desktopPaperRenderRoster(
                'morning',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $rehearsalWeekPoints
            );

            /*
            |--------------------------------------------------------------------------
            | 2. Morning activities
            |--------------------------------------------------------------------------
            */

            desktopPaperRenderActivities(
                'morning',
                $week,
                $splitEvents,
                is_admin()
            );
            ?>

            <!--
            |--------------------------------------------------------------------------
            | 3. Date axis
            |--------------------------------------------------------------------------
            -->

            <div class="desktop-paper-date-axis">

                <div class="desktop-paper-date-label">
                    Date
                </div>

                <div class="desktop-paper-date-days">

                    <?php foreach ($week as $day): ?>

                        <div
                            class="
                                desktop-paper-date-cell
                                <?= e(desktopPaperDayClass($day)) ?>
                            ">

                            <strong>
                                <?= (int) $day['day'] ?>
                            </strong>

                            <span>
                                <?= e(
                                    strtoupper(
                                        $day['weekday_short']
                                    )
                                ) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

            <?php
            /*
            |--------------------------------------------------------------------------
            | 4. Evening activities
            |--------------------------------------------------------------------------
            */

            desktopPaperRenderActivities(
                'evening',
                $week,
                $splitEvents,
                is_admin()
            );

            /*
            |--------------------------------------------------------------------------
            | 5. Evening availability
            |
            | Points shown = performance points
            |--------------------------------------------------------------------------
            */

            desktopPaperRenderRoster(
                'evening',
                $week,
                $members,
                $availability,
                $splitEvents,
                $splitAvailability,
                $performanceWeekPoints
            );
            ?>

        </article>

    <?php endforeach; ?>

</div>