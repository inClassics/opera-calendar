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

$date = $_POST['date'] ?? '';
$period = $_POST['period'] ?? '';
$activity = trim($_POST['activity'] ?? '');

if (!is_valid_date($date) || !in_array($period, ['morning', 'evening'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

if (mb_strlen($activity) > 255) {
    json_response(['success' => false, 'message' => 'Activity is too long.'], 422);
}

$schedule = new Schedule($pdo);
$schedule->saveActivity($date, $period, $activity, current_user_id());

json_response(['success' => true, 'activity' => $activity]);
