<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$eventId = (int) ($_POST['split_event_id'] ?? 0);
$activity = trim((string) ($_POST['activity'] ?? ''));

ajax_require_split_event($pdo, $eventId);

if ($activity === '') {
    json_response([
        'success' => false,
        'message' => 'Activity cannot be empty.',
    ], 400);
}

if (mb_strlen($activity) > 255) {
    json_response([
        'success' => false,
        'message' => 'Activity is too long.',
    ], 422);
}

$stmt = $pdo->prepare("
    SELECT schedule_date, period, activity
    FROM schedule_split_events
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$eventId]);
$parentEvent = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    $newId = $schedule->addSplitEvent(
        $eventId,
        $activity,
        current_user_id()
    );

    $activityLogger->log(
        current_user_id(),
        'split_event_created',
        'split_event',
        $newId,
        'Split event added',
        null,
        [
            'activity' => $activity,
            'added_after_event_id' => $eventId,
        ],
        null,
        $parentEvent['schedule_date'] ?? null,
        $parentEvent['period'] ?? null
    );

    json_response([
        'success' => true,
        'split_event_id' => $newId,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
