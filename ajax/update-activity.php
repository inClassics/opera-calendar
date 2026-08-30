<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$date =
    ajax_date(
        (string) (
            $_POST['date']
            ?? ''
        )
    );

$period =
    ajax_period(
        (string) (
            $_POST['period']
            ?? ''
        )
    );

$activity =
    trim(
        (string) (
            $_POST['activity']
            ?? ''
        )
    );

if (
    mb_strlen(
        $activity
    ) > 255
) {
    json_response([
        'success' => false,
        'message' => 'Activity is too long.',
    ], 422);
}

$stmt =
    $pdo->prepare("
        SELECT
            id,
            activity
        FROM schedule_slots
        WHERE schedule_date = ?
          AND period = ?
        LIMIT 1
    ");

$stmt->execute([
    $date,
    $period,
]);

$oldRow =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );

$oldActivity =
    trim(
        (string) (
            $oldRow['activity']
            ?? ''
        )
    );

$schedule->saveActivity(
    $date,
    $period,
    $activity,
    current_user_id()
);

$stmt =
    $pdo->prepare("
        SELECT id
        FROM schedule_slots
        WHERE schedule_date = ?
          AND period = ?
        LIMIT 1
    ");

$stmt->execute([
    $date,
    $period,
]);

$slotId =
    (int) (
        $stmt->fetchColumn()
        ?: 0
    );

if (
    $oldActivity !== $activity
) {
    $activityLogger->log(
        current_user_id(),
        'schedule_activity_changed',
        'schedule_slot',
        $slotId > 0
            ? $slotId
            : null,
        'Schedule activity changed',
        [
            'activity' =>
                $oldActivity,
        ],
        [
            'activity' =>
                $activity,
        ],
        null,
        $date,
        $period
    );
}

json_response([
    'success' => true,
    'activity' => $activity,
]);
