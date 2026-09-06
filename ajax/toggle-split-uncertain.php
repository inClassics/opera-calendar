<?php

require_once __DIR__ . '/_bootstrap.php';

$eventId =
    (int) (
        $_POST['split_event_id']
        ?? 0
    );

$userId =
    (int) (
        $_POST['user_id']
        ?? 0
    );

$uncertain =
    (string) (
        $_POST['uncertain']
        ?? '0'
    )
    === '1';

ajax_require_member_access(
    $userId
);

ajax_require_active_user(
    $pdo,
    $userId
);

ajax_require_split_event(
    $pdo,
    $eventId
);

$stmt =
    $pdo->prepare("
        SELECT
            se.schedule_date,
            se.period,
            se.activity,
            sa.id AS availability_id,
            sa.status,
            sa.uncertain
        FROM schedule_split_events se
        LEFT JOIN split_availability sa
            ON sa.split_event_id = se.id
           AND sa.user_id = ?
        WHERE se.id = ?
        LIMIT 1
    ");

$stmt->execute([
    $userId,
    $eventId,
]);

$row =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );

if (
    !$row
    ||
    empty($row['status'])
) {
    json_response([
        'success' => false,
        'message' =>
        'Set availability before adding a question mark.',
    ], 422);
}

$before = [
    'status' =>
    $row['status'],
    'uncertain' =>
    !empty($row['uncertain']),
];

$schedule->setSplitUncertain(
    $eventId,
    $userId,
    $uncertain,
    current_user_id()
);

$after = [
    'status' =>
    $row['status'],
    'uncertain' =>
    $uncertain,
];

$activityLogger
    ->logSplitAvailabilityState(
        current_user_id(),
        $userId,
        $eventId,
        (string) (
            $row['schedule_date']
            ?? ''
        ),
        (string) (
            $row['period']
            ?? ''
        ),
        (string) (
            $row['activity']
            ?? ''
        ),
        $before,
        $after
    );

json_response([
    'success' => true,
    'uncertain' => $uncertain,
]);
