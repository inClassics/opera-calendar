<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

if (!is_logged_in()) json_response(['success' => false, 'message' => 'Not logged in.'], 401);
verify_csrf_or_fail($_POST['csrf_token'] ?? null);

$eventId = (int) ($_POST['split_event_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
$uncertain = ($_POST['uncertain'] ?? '0') === '1';

if (!$eventId || !$userId) json_response(['success' => false, 'message' => 'Invalid request.'], 400);
if (!is_admin() && $userId !== current_user_id()) {
    json_response(['success' => false, 'message' => 'You cannot edit this member.'], 403);
}

$stmt = $pdo->prepare('SELECT id FROM schedule_split_events WHERE id = ? LIMIT 1');
$stmt->execute([$eventId]);
if (!$stmt->fetch()) json_response(['success' => false, 'message' => 'Split event not found.'], 404);

$schedule = new Schedule($pdo);
$schedule->setSplitUncertain($eventId, $userId, $uncertain, current_user_id());
json_response(['success' => true, 'uncertain' => $uncertain]);
