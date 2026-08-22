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

if (!is_admin()) {
    json_response([
        'success' => false,
        'message' => 'Admin access required.'
    ], 403);
}

verify_csrf_or_fail(
    $_POST['csrf_token'] ?? null
);

$sourceType =
    $_POST['source_type']
    ?? '';

$sourceId =
    (int) (
        $_POST['source_id']
        ?? 0
    );

$rawValue =
    trim(
        (string) (
            $_POST['point_value']
            ?? '0'
        )
    );

$pointType =
    trim(
        (string) (
            $_POST['point_type']
            ?? ''
        )
    );

if (
    !$sourceId
    ||
    !in_array(
        $sourceType,
        [
            'calendar',
            'split',
            'slot'
        ],
        true
    )
    ||
    !is_numeric($rawValue)
) {
    json_response([
        'success' => false,
        'message' => 'Invalid point settings.'
    ], 400);
}

$pointValue =
    round(
        (float) $rawValue,
        2
    );

if ($pointType === '') {
    $pointType = null;
}

try {

    $schedule =
        new Schedule($pdo);

    $schedule->updateActivityPoints(
        $sourceType,
        $sourceId,
        $pointValue,
        $pointType,
        current_user_id()
    );

    json_response([
        'success' => true,
        'point_value' => $pointValue,
        'point_type' => $pointType
    ]);

} catch (Throwable $e) {

    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
