<?php

require_once __DIR__ . '/_bootstrap.php';

$eventId = (int) ($_POST['split_event_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
$uncertain = (string) ($_POST['uncertain'] ?? '0') === '1';

ajax_require_member_access($userId);
ajax_require_active_user($pdo, $userId);
ajax_require_split_event($pdo, $eventId);

$schedule->setSplitUncertain(
    $eventId,
    $userId,
    $uncertain,
    current_user_id()
);

json_response([
    'success' => true,
    'uncertain' => $uncertain,
]);
