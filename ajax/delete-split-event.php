<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$eventId = (int) ($_POST['split_event_id'] ?? 0);
ajax_require_split_event($pdo, $eventId);

try {
    $result = $schedule->deleteSplitEvent(
        $eventId,
        current_user_id()
    );

    json_response([
        'success' => true,
        ...$result,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
