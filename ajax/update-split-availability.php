<?php

require_once __DIR__ . '/_bootstrap.php';

$eventId = (int) ($_POST['split_event_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if (!in_array($status, ['', 'available', 'unavailable'], true)) {
    json_response([
        'success' => false,
        'message' => 'Invalid availability status.',
    ], 400);
}

ajax_require_member_access($userId);
ajax_require_active_user($pdo, $userId);
ajax_require_split_event($pdo, $eventId);

$schedule->saveSplitAvailability(
    $eventId,
    $userId,
    $status,
    current_user_id()
);

json_response([
    'success' => true,
    'status' => $status,
]);
