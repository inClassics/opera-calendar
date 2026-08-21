<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Not logged in.'
    ], 401);
}

verify_csrf_or_fail(
    $_POST['csrf_token'] ?? null
);

$userId = (int) ($_POST['user_id'] ?? 0);
$date = $_POST['date'] ?? '';
$period = $_POST['period'] ?? '';
$uncertain = ($_POST['uncertain'] ?? '') === '1';

if (
    !$userId ||
    !is_valid_date($date) ||
    !in_array($period, ['morning', 'evening'], true)
) {
    json_response([
        'success' => false,
        'message' => 'Invalid request.'
    ], 400);
}

if (
    !is_admin() &&
    $userId !== current_user_id()
) {
    json_response([
        'success' => false,
        'message' => 'You cannot edit this member.'
    ], 403);
}

/*
|--------------------------------------------------------------------------
| There must already be × or •
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM availability
    WHERE user_id = ?
      AND schedule_date = ?
      AND period = ?
      AND status IS NOT NULL
    LIMIT 1
");

$stmt->execute([
    $userId,
    $date,
    $period
]);

if (!$stmt->fetch()) {
    json_response([
        'success' => false,
        'message' => 'Set availability before adding a question mark.'
    ], 422);
}

$schedule = new Schedule($pdo);

$schedule->setUncertain(
    $userId,
    $date,
    $period,
    $uncertain,
    current_user_id()
);

json_response([
    'success' => true,
    'uncertain' => $uncertain
]);
