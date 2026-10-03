<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/WeeklyPointBalance.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$csrf = (string) ($_POST['csrf'] ?? '');

if (!hash_equals(csrf_token(), $csrf)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$weekStart = trim((string) ($_POST['week_start'] ?? ''));
$userId = (int) ($_POST['user_id'] ?? 0);
$pointType = trim((string) ($_POST['point_type'] ?? ''));
$rawOpening = trim((string) ($_POST['opening_points'] ?? ''));

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid musician.']);
    exit;
}

$openingPoints = null;

if ($rawOpening !== '') {
    if (!is_numeric($rawOpening)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Points must be numeric.']);
        exit;
    }

    $openingPoints = (float) $rawOpening;
}

try {
    $repository = new WeeklyPointBalance($pdo);
    $repository->save(
        $weekStart,
        $userId,
        $pointType,
        $openingPoints,
        current_user_id()
    );

    echo json_encode([
        'ok' => true,
        'opening_points' => $openingPoints,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save weekly points.']);
}
