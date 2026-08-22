<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$eventId = (int) ($_POST['split_event_id'] ?? 0);
$activity = trim((string) ($_POST['activity'] ?? ''));

ajax_require_split_event($pdo, $eventId);

if ($activity === '') {
    json_response([
        'success' => false,
        'message' => 'Activity cannot be empty.',
    ], 400);
}

if (mb_strlen($activity) > 255) {
    json_response([
        'success' => false,
        'message' => 'Activity is too long.',
    ], 422);
}

try {
    $schedule->updateSplitEvent(
        $eventId,
        $activity,
        current_user_id()
    );

    json_response([
        'success' => true,
        'activity' => $activity,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
