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

$status =
    (string) (
        $_POST['status']
        ?? ''
    );

if (
    !in_array(
        $status,
        [
            '',
            'available',
            'unavailable'
        ],
        true
    )
) {
    json_response([
        'success' => false,
        'message' =>
        'Invalid availability status.',
    ], 400);
}

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
            schedule_date,
            period,
            activity
        FROM schedule_split_events
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $eventId
]);

$event =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );

$stmt =
    $pdo->prepare("
        SELECT
            status,
            uncertain
        FROM split_availability
        WHERE split_event_id = ?
          AND user_id = ?
        LIMIT 1
    ");

$stmt->execute([
    $eventId,
    $userId
]);

$oldRow =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );

$before = [
    'status' =>
    $oldRow['status']
        ?? '',
    'uncertain' =>
    !empty($oldRow['uncertain']),
];

$schedule->saveSplitAvailability(
    $eventId,
    $userId,
    $status,
    current_user_id()
);

$stmt =
    $pdo->prepare("
        SELECT
            status,
            uncertain
        FROM split_availability
        WHERE split_event_id = ?
          AND user_id = ?
        LIMIT 1
    ");

$stmt->execute([
    $eventId,
    $userId
]);

$newRow =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );

$after = [
    'status' =>
    $newRow['status']
        ?? '',
    'uncertain' =>
    !empty($newRow['uncertain']),
];

$activityLogger
    ->logSplitAvailabilityState(
        current_user_id(),
        $userId,
        $eventId,
        (string) (
            $event['schedule_date']
            ?? ''
        ),
        (string) (
            $event['period']
            ?? ''
        ),
        (string) (
            $event['activity']
            ?? ''
        ),
        $before,
        $after
    );

json_response([
    'success' => true,
    'status' => $status,
]);
