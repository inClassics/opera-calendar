<?php

require_once __DIR__ . '/_bootstrap.php';

$userId =
    (int) (
        $_POST['user_id']
        ?? 0
    );

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

$stmt =
    $pdo->prepare("
        SELECT
            id,
            status,
            uncertain
        FROM availability
        WHERE user_id = ?
          AND schedule_date = ?
          AND period = ?
        LIMIT 1
    ");

$stmt->execute([
    $userId,
    $date,
    $period
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

$schedule->saveAvailability(
    $userId,
    $date,
    $period,
    $status,
    current_user_id()
);

$stmt =
    $pdo->prepare("
        SELECT
            id,
            status,
            uncertain
        FROM availability
        WHERE user_id = ?
          AND schedule_date = ?
          AND period = ?
        LIMIT 1
    ");

$stmt->execute([
    $userId,
    $date,
    $period
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
    ->logAvailabilityState(
        current_user_id(),
        $userId,
        $date,
        $period,
        $before,
        $after,
        isset($newRow['id'])
            ? (int) $newRow['id']
            : (
                isset($oldRow['id'])
                ? (int) $oldRow['id']
                : null
            )
    );

json_response([
    'success' => true,
    'status' => $status,
]);
