<?php

require_once __DIR__ . '/_bootstrap.php';

$userId = (int) ($_POST['user_id'] ?? 0);
$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$status = (string) ($_POST['status'] ?? '');

if (!in_array($status, ['', 'available', 'unavailable'], true)) {
    json_response([
        'success' => false,
        'message' => 'Invalid availability status.',
    ], 400);
}

ajax_require_member_access($userId);
ajax_require_active_user($pdo, $userId);

$schedule->saveAvailability(
    $userId,
    $date,
    $period,
    $status,
    current_user_id()
);

json_response([
    'success' => true,
    'status' => $status,
]);
