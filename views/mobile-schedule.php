<div class="mobile-schedule">
    <div class="mobile-legend">
        <span><strong class="available-mark">×</strong> available</span>
        <span><strong class="unavailable-mark">•</strong> unavailable</span>
        <span class="muted">? uncertain</span>
    </div>

    <?php foreach ($mobileDays as $day): ?>

        <?php if ($day['weekday'] === 'Monday'): ?>
            <section class="mobile-points-card">
                <div class="mobile-week-title">
                    Week of <?= e((new DateTime($day['date']))->format('j M')) ?>
                </div>

                <div class="mobile-points-columns">
                    <div>
                        <h3>Performance points</h3>

                        <div class="mobile-points-grid">
                            <?php foreach ($members as $member): ?>
                                <?php
                                $userId = (int) $member['id'];

                                $points =
                                    $weeklyPerformancePoints[$day['date']][$userId]
                                    ?? $member['evening_starting_points']
                                    ?? 0;
                                ?>

                                <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>">
                                    <span><?= e($member['name']) ?></span>
                                    <strong><?= e(format_points($points)) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <h3>Rehearsal points</h3>

                        <div class="mobile-points-grid">
                            <?php foreach ($members as $member): ?>
                                <?php
                                $userId = (int) $member['id'];

                                $points =
                                    $weeklyRehearsalPoints[$day['date']][$userId]
                                    ?? $member['morning_starting_points']
                                    ?? 0;
                                ?>

                                <div class="mobile-point-item <?= $userId === current_user_id() ? 'current-user-mobile' : '' ?>">
                                    <span><?= e($member['name']) ?></span>
                                    <strong><?= e(format_points($points)) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <article class="mobile-day-card <?= $day['weekday'] === 'Sunday' ? 'mobile-week-end' : '' ?>">

            <header class="mobile-day-header">
                <div class="mobile-day-number">
                    <?= (int) $day['day'] ?>
                </div>

                <div>
                    <div class="mobile-weekday">
                        <?= e($day['weekday']) ?>
                    </div>

                    <div class="mobile-date-full">
                        <?= e((new DateTime($day['date']))->format('j F Y')) ?>
                    </div>
                </div>
            </header>

            <div class="mobile-session-grid">

                <?php foreach (['morning' => 'Morning', 'evening' => 'Evening'] as $period => $label): ?>

                    <?php
                    $periodEvents = slotEvents(
                        $day,
                        $period,
                        $splitEvents,
                        $activityPointItems
                    );
                    ?>

                    <section class="mobile-session">
                        <div class="mobile-session-heading">
                            <span><?= $period === 'evening' ? '🌙' : '☀️' ?></span>
                            <strong><?= e($label) ?></strong>
                        </div>

                        <?php foreach ($periodEvents as $event): ?>

                            <?php
                            $eventId =
                                $event['id'] !== null
                                ? (int) $event['id']
                                : null;

                            $pointItems =
                                $event['point_items']
                                ?? [];

                            $rawActivity =
                                trim(
                                    (string) (
                                        $event['activity']
                                        ?? ''
                                    )
                                );
                            ?>

                            <div class="mobile-event-block <?= $eventId ? 'is-split-event' : '' ?>">

                                <div
                                    class="mobile-activity
                                        <?= (!$eventId && is_admin()) ? 'activity-editable' : '' ?>
                                        <?= $eventId ? 'split-activity-cell' : '' ?>"
                                    data-date="<?= e($day['date']) ?>"
                                    data-period="<?= e($period) ?>"
                                    data-split-event-id="<?= $eventId ?: '' ?>"
                                    data-activity-raw="<?= e($rawActivity) ?>"
                                    data-event-count="<?= max(1, count($pointItems)) ?>"
                                >
                                    <?php if (
                                        str_contains($rawActivity, '**')
                                        || preg_match(
                                            '/(?<!\*)\*[^*\r\n]+\*(?!\*)/u',
                                            $rawActivity
                                        )
                                        || str_contains($rawActivity, "\n")
                                    ): ?>
                                        <?= renderActivityMarkup($rawActivity) ?>
                                    <?php else: ?>
                                        <?= e($rawActivity) ?>
                                    <?php endif; ?>
                                </div>

                                <?php foreach ($pointItems as $pointItem): ?>
                                    <?php
                                    $pointType = $pointItem['point_type'] ?? null;
                                    $pointValue = (float) ($pointItem['point_value'] ?? 0);
                                    ?>

                                    <?php if (
                                        $pointValue > 0
                                        && in_array(
                                            $pointType,
                                            ['rehearsal', 'performance'],
                                            true
                                        )
                                    ): ?>
                                        <div class="mobile-activity-point-summary">
                                            <strong>
                                                <?= $pointType === 'rehearsal' ? 'R' : 'P' ?>
                                                ·
                                                <?= e(format_points($pointValue)) ?>
                                            </strong>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (is_admin()): ?>
                                        <div
                                            class="activity-point-editor mobile-activity-point-editor"
                                            data-point-source="<?= e($pointItem['source_type']) ?>"
                                            data-point-id="<?= (int) $pointItem['source_id'] ?>"
                                            data-point-type="<?= e($pointType ?? '') ?>"
                                        >
                                            <input
                                                type="number"
                                                class="activity-point-input"
                                                value="<?= e(format_points($pointValue)) ?>"
                                                min="0"
                                                max="9999"
                                                step="1"
                                                inputmode="numeric"
                                                aria-label="Activity point value"
                                            >

                                            <div class="activity-point-type">
                                                <button
                                                    type="button"
                                                    class="activity-point-type-button <?= $pointType === 'rehearsal' ? 'selected' : '' ?>"
                                                    data-point-type="rehearsal"
                                                    title="Rehearsal points"
                                                >R</button>

                                                <button
                                                    type="button"
                                                    class="activity-point-type-button <?= $pointType === 'performance' ? 'selected' : '' ?>"
                                                    data-point-type="performance"
                                                    title="Performance points"
                                                >P</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <div class="mobile-members-grid">
                                    <?php foreach ($members as $member): ?>

                                        <?php
                                        $userId = (int) $member['id'];

                                        $item = $eventId
                                            ? (
                                                $splitAvailability[$eventId][$userId]
                                                ?? [
                                                    'status' => '',
                                                    'uncertain' => false
                                                ]
                                            )
                                            : (
                                                $availability[$day['date']][$period][$userId]
                                                ?? [
                                                    'status' => '',
                                                    'uncertain' => false
                                                ]
                                            );

                                        $status = $item['status'] ?? '';
                                        $uncertain = !empty($item['uncertain']);

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

                                        $specialDayEmoji =
                                            getSpecialDayEmoji(
                                                $member,
                                                $day['date']
                                            );
                                        ?>

                                        <div class="mobile-member-row
                                            <?= $isCurrentUser ? 'current-user-mobile' : '' ?>
                                            <?= e($specialDayClass) ?>"
                                        >
                                            <span class="mobile-member-name">
                                                <?= e($member['name']) ?>

                                                <?php if ($specialDayEmoji !== ''): ?>
                                                    <span class="mobile-special-day">
                                                        <?= e($specialDayEmoji) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>

                                            <div class="mobile-member-actions">
                                                <?php if ($eventId): ?>
                                                    <button
                                                        type="button"
                                                        class="member-cell split-availability-cell mobile-availability-cell <?= $editable ? 'editable' : '' ?>"
                                                        data-split-event-id="<?= $eventId ?>"
                                                        data-user-id="<?= $userId ?>"
                                                        data-status="<?= e($status) ?>"
                                                        data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                        <?= !$editable ? 'disabled' : '' ?>
                                                    ></button>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="member-cell availability-cell mobile-availability-cell <?= $editable ? 'editable' : '' ?>"
                                                        data-user-id="<?= $userId ?>"
                                                        data-date="<?= e($day['date']) ?>"
                                                        data-period="<?= e($period) ?>"
                                                        data-status="<?= e($status) ?>"
                                                        data-uncertain="<?= $uncertain ? '1' : '0' ?>"
                                                        <?= !$editable ? 'disabled' : '' ?>
                                                    ></button>
                                                <?php endif; ?>

                                                <?php if ($editable): ?>
                                                    <button
                                                        type="button"
                                                        class="mobile-options-button"
                                                        aria-label="More options for <?= e($member['name']) ?>"
                                                    >⋯</button>
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
