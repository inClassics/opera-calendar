<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Not logged in.'], 401);
}

verify_csrf_or_fail($_POST['csrf_token'] ?? null);

$userId = (int) ($_POST['user_id'] ?? 0);
$date = $_POST['date'] ?? '';
$period = $_POST['period'] ?? '';
$status = $_POST['status'] ?? '';

if (!$userId || !is_valid_date($date) || !in_array($period, ['morning', 'evening'], true) || !in_array($status, ['', 'available', 'unavailable'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

if (!is_admin() && $userId !== current_user_id()) {
    json_response(['success' => false, 'message' => 'You cannot edit this member.'], 403);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND status = 1 LIMIT 1');
$stmt->execute([$userId]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'message' => 'User not found.'], 404);
}

$schedule = new Schedule($pdo);
$schedule->saveAvailability($userId, $date, $period, $status, current_user_id());

json_response(['success' => true, 'status' => $status]);
