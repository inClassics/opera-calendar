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
        'message' => 'Invalid availability status.',
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

/*
|--------------------------------------------------------------------------
| Split event information
|--------------------------------------------------------------------------
*/

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
    $stmt->fetch();

/*
|--------------------------------------------------------------------------
| Previous availability
|--------------------------------------------------------------------------
*/

$stmt =
    $pdo->prepare("
        SELECT status
        FROM split_availability
        WHERE split_event_id = ?
          AND user_id = ?
        LIMIT 1
    ");

$stmt->execute([
    $eventId,
    $userId
]);

$oldStatus =
    $stmt->fetchColumn();

if (
    $oldStatus === false
) {
    $oldStatus = '';
}

/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/

$schedule->saveSplitAvailability(
    $eventId,
    $userId,
    $status,
    current_user_id()
);

/*
|--------------------------------------------------------------------------
| Log
|--------------------------------------------------------------------------
*/

if (
    $oldStatus !== $status
) {
    $activityLogger->log(
        current_user_id(),
        'split_availability_changed',
        'split_event',
        $eventId,
        'Split-event availability changed',
        [
            'status' =>
            $oldStatus,

            'activity' =>
            $event['activity']
                ?? '',
        ],
        [
            'status' =>
            $status,

            'activity' =>
            $event['activity']
                ?? '',
        ],
        $userId,
        $event['schedule_date']
            ?? null,
        $event['period']
            ?? null
    );
}

json_response([
    'success' => true,
    'status' => $status,
]);
