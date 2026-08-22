<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$force = (string) ($_POST['force'] ?? '0') === '1';

try {
    $result = $schedule->mergeSplitSlot(
        $date,
        $period,
        current_user_id(),
        $force
    );

    if (
        isset($result['success'])
        && $result['success'] === false
        && !empty($result['conflicts'])
    ) {
        json_response([
            'success' => false,
            'needs_confirmation' => true,
            'conflict_count' => count($result['conflicts']),
            'message' => 'Some members have different answers between these events.',
        ], 409);
    }

    json_response($result);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
