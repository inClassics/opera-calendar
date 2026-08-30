<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$date = ajax_date((string) ($_POST['date'] ?? ''));
$period = ajax_period((string) ($_POST['period'] ?? ''));
$force = (string) ($_POST['force'] ?? '0') === '1';

$stmt = $pdo->prepare("
    SELECT
        id,
        activity,
        activity_override,
        calendar_event_id,
        sort_order,
        point_value,
        point_type
    FROM schedule_split_events
    WHERE schedule_date = ?
      AND period = ?
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute([
    $date,
    $period,
]);
$oldEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $activityLogger->log(
        current_user_id(),
        'schedule_slot_merged',
        'schedule_slot',
        null,
        'Split events merged into one schedule slot',
        [
            'events' => array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'activity' => $row['activity_override']
                        ?? $row['activity']
                        ?? '',
                    'calendar_event_id' => $row['calendar_event_id'] ?? null,
                    'point_value' => $row['point_value'] ?? null,
                    'point_type' => $row['point_type'] ?? null,
                ],
                $oldEvents
            ),
            'forced' => $force,
        ],
        [
            'result' => $result,
        ],
        null,
        $date,
        $period
    );

    json_response($result);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
