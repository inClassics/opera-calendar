<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ScheduleChangeTracker.php';

$month = trim(
    (string) (
        $_POST['month']
        ?? ''
    )
);

$tracker = new ScheduleChangeTracker($pdo);

try {
    $activityId = $tracker->markMonthSeen(
        current_user_id(),
        $month
    );

    json_response([
        'success' => true,
        'last_seen_activity_id' => $activityId,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
