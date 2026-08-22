<?php

require_once __DIR__ . '/_bootstrap.php';

$userId = (int) ($_POST['user_id'] ?? 0);
$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$uncertain = (string) ($_POST['uncertain'] ?? '0') === '1';

ajax_require_member_access($userId);
ajax_require_active_user($pdo, $userId);

$stmt = $pdo->prepare(
    'SELECT id
     FROM availability
     WHERE user_id = ?
       AND schedule_date = ?
       AND period = ?
       AND status IS NOT NULL
     LIMIT 1'
);

$stmt->execute([
    $userId,
    $date,
    $period,
]);

if (!$stmt->fetchColumn()) {
    json_response([
        'success' => false,
        'message' => 'Set availability before adding a question mark.',
    ], 422);
}

$schedule->setUncertain(
    $userId,
    $date,
    $period,
    $uncertain,
    current_user_id()
);

json_response([
    'success' => true,
    'uncertain' => $uncertain,
]);
