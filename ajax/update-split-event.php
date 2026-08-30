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
    SELECT
        schedule_date,
        period,
        activity,
        activity_override,
        calendar_event_id
    FROM schedule_split_events
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$eventId]);
$oldRow = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    $schedule->updateSplitEvent(
        $eventId,
        $activity,
        current_user_id()
    );

    $oldActivity = (string) (
        $oldRow['activity_override']
        ?? $oldRow['activity']
        ?? ''
    );

    if ($oldActivity !== $activity) {
        $activityLogger->log(
            current_user_id(),
            'split_event_activity_changed',
            'split_event',
            $eventId,
            'Split event activity changed',
            [
                'activity' => $oldActivity,
            ],
            [
                'activity' => $activity,
            ],
            null,
            $oldRow['schedule_date'] ?? null,
            $oldRow['period'] ?? null
        );
    }

    json_response([
        'success' => true,
        'activity' => $activity,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
