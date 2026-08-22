<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Not logged in.'], 401);
}

if (!is_admin()) {
    json_response(['success' => false, 'message' => 'Admin access required.'], 403);
}

verify_csrf_or_fail($_POST['csrf_token'] ?? null);

$eventId =
    (int) ($_POST['split_event_id'] ?? 0);

if (!$eventId) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

try {
    $schedule = new Schedule($pdo);

    $result =
        $schedule->deleteSplitEvent(
            $eventId,
            current_user_id()
        );

    json_response([
        'success' => true,
        ...$result
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
