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
          AND status IS NOT NULL
        LIMIT 1
    ");

$stmt->execute([
    $userId,
    $date,
    $period,
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

$schedule->setUncertain(
    $userId,
    $date,
    $period,
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
    ->logAvailabilityState(
        current_user_id(),
        $userId,
        $date,
        $period,
        $before,
        $after,
        (int) $row['id']
    );

json_response([
    'success' => true,
    'uncertain' => $uncertain,
]);
