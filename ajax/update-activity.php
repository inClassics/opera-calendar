<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));

if (mb_strlen($activity) > 255) {
    json_response([
        'success' => false,
        'message' => 'Activity is too long.',
    ], 422);
}

$schedule->saveActivity(
    $date,
    $period,
    $activity,
    current_user_id()
);

json_response([
    'success' => true,
    'activity' => $activity,
]);
