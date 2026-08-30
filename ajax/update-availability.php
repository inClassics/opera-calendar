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

/*
|--------------------------------------------------------------------------
| Current value before change
|--------------------------------------------------------------------------
*/

$stmt =
    $pdo->prepare("
        SELECT status
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

$schedule->saveAvailability(
    $userId,
    $date,
    $period,
    $status,
    current_user_id()
);

/*
|--------------------------------------------------------------------------
| Log only if something actually changed
|--------------------------------------------------------------------------
*/

if (
    $oldStatus !== $status
) {
    $activityLogger->log(
        current_user_id(),
        'availability_changed',
        'availability',
        null,
        'Availability changed',
        [
            'status' =>
            $oldStatus,
        ],
        [
            'status' =>
            $status,
        ],
        $userId,
        $date,
        $period
    );
}

json_response([
    'success' => true,
    'status' => $status,
]);
