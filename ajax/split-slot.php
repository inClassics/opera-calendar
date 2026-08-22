<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

require_login();

if (!is_admin()) {
    json_response([
        'success' => false,
        'message' => 'Admin access required.'
    ], 403);
}

verify_csrf_or_fail(
    $_POST['csrf_token'] ?? null
);

$date =
    $_POST['date'] ?? '';

$period =
    $_POST['period'] ?? '';

$activity =
    trim($_POST['activity'] ?? '');

if (
    !is_valid_date($date)
    ||
    !in_array(
        $period,
        ['morning', 'evening'],
        true
    )
    ||
    $activity === ''
) {
    json_response([
        'success' => false,
        'message' => 'Invalid request.'
    ], 400);
}

$schedule =
    new Schedule($pdo);

try {

    $ids =
        $schedule->splitSlot(
            $date,
            $period,
            $activity,
            current_user_id()
        );

    json_response([
        'success' => true,
        'event_ids' => $ids
    ]);
} catch (Throwable $e) {

    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
