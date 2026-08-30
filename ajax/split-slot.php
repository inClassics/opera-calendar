<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));

if ($activity === '') {
    json_response([
        'success' => false,
        'message' => 'The activity is empty.',
    ], 400);
}

try {
    $ids = $schedule->splitSlot(
        $date,
        $period,
        $activity,
        current_user_id()
    );

    $activityLogger->log(
        current_user_id(),
        'schedule_slot_split',
        'schedule_slot',
        null,
        'Schedule slot split into separate events',
        [
            'activity' => $activity,
        ],
        [
            'split_event_ids' => array_map('intval', $ids),
        ],
        null,
        $date,
        $period
    );

    json_response([
        'success' => true,
        'event_ids' => $ids,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
