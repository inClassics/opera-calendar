<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$eventId = (int) ($_POST['split_event_id'] ?? 0);
ajax_require_split_event($pdo, $eventId);

$stmt = $pdo->prepare("
    SELECT
        id,
        schedule_date,
        period,
        activity,
        activity_override,
        calendar_event_id,
        point_value,
        point_type
    FROM schedule_split_events
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$eventId]);
$oldRow = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    $result = $schedule->deleteSplitEvent(
        $eventId,
        current_user_id()
    );

    $activityLogger->log(
        current_user_id(),
        'split_event_deleted',
        'split_event',
        $eventId,
        'Split event deleted',
        [
            'activity' => $oldRow['activity_override']
                ?? $oldRow['activity']
                ?? '',
            'calendar_event_id' => $oldRow['calendar_event_id'] ?? null,
            'point_value' => $oldRow['point_value'] ?? null,
            'point_type' => $oldRow['point_type'] ?? null,
        ],
        null,
        null,
        $oldRow['schedule_date'] ?? null,
        $oldRow['period'] ?? null
    );

    json_response([
        'success' => true,
        ...$result,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
