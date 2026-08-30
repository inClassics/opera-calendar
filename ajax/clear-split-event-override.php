<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$eventId = (int) ($_POST['split_event_id'] ?? 0);
ajax_require_split_event($pdo, $eventId);

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
    $schedule->clearSplitEventOverride(
        $eventId,
        current_user_id()
    );

    if (($oldRow['activity_override'] ?? null) !== null) {
        $activityLogger->log(
            current_user_id(),
            'split_event_override_cleared',
            'split_event',
            $eventId,
            'Split event manual override cleared',
            [
                'activity_override' => $oldRow['activity_override'],
            ],
            [
                'activity_override' => null,
            ],
            null,
            $oldRow['schedule_date'] ?? null,
            $oldRow['period'] ?? null
        );
    }

    json_response([
        'success' => true,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
