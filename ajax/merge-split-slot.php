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

$date =
    $_POST['date'] ?? '';

$period =
    $_POST['period'] ?? '';

$force =
    ($_POST['force'] ?? '0') === '1';

if (
    !is_valid_date($date)
    ||
    !in_array(
        $period,
        ['morning', 'evening'],
        true
    )
) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

try {
    $schedule = new Schedule($pdo);

    $result =
        $schedule->mergeSplitSlot(
            $date,
            $period,
            current_user_id(),
            $force
        );

    if (
        isset($result['success'])
        &&
        $result['success'] === false
        &&
        !empty($result['conflicts'])
    ) {
        json_response([
            'success' => false,
            'needs_confirmation' => true,
            'conflict_count' => count($result['conflicts']),
            'message' => 'Some members have different answers between these events.'
        ], 409);
    }

    json_response($result);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
