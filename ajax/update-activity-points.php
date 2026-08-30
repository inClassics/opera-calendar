<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$sourceType = trim((string) ($_POST['source_type'] ?? ''));
$sourceId = (int) ($_POST['source_id'] ?? 0);
$pointValueRaw = trim((string) ($_POST['point_value'] ?? ''));
$pointType = trim((string) ($_POST['point_type'] ?? ''));

if ($pointType === '') {
    $pointType = null;
}

$tableMap = [
    'calendar' => [
        'table' => 'calendar_events',
        'entity_type' => 'calendar_event',
    ],
    'split' => [
        'table' => 'schedule_split_events',
        'entity_type' => 'split_event',
    ],
    'slot' => [
        'table' => 'schedule_slots',
        'entity_type' => 'schedule_slot',
    ],
];

if (
    $sourceId <= 0
    ||
    !array_key_exists(
        $sourceType,
        $tableMap
    )
) {
    json_response([
        'success' => false,
        'message' => 'Invalid activity source.',
    ], 400);
}

if (
    $pointValueRaw === ''
    ||
    filter_var(
        $pointValueRaw,
        FILTER_VALIDATE_INT
    ) === false
) {
    json_response([
        'success' => false,
        'message' => 'Point value must be a whole number.',
    ], 400);
}

$pointValue = (int) $pointValueRaw;

if ($pointValue < 0 || $pointValue > 9999) {
    json_response([
        'success' => false,
        'message' => 'Point value must be between 0 and 9999.',
    ], 400);
}

if (
    $pointType !== null
    &&
    !in_array(
        $pointType,
        ['rehearsal', 'performance'],
        true
    )
) {
    json_response([
        'success' => false,
        'message' => 'Invalid point type.',
    ], 400);
}

$table = $tableMap[$sourceType]['table'];
$entityType = $tableMap[$sourceType]['entity_type'];

$check =
    $pdo->prepare("
        SELECT
            id,
            schedule_date,
            period,
            point_value,
            point_type
        FROM {$table}
        WHERE id = ?
        LIMIT 1
    ");

$check->execute([
    $sourceId
]);

$oldRow =
    $check->fetch(
        PDO::FETCH_ASSOC
    );

if (!$oldRow) {
    json_response([
        'success' => false,
        'message' => 'Activity not found.',
    ], 404);
}

$stmt =
    $pdo->prepare("
        UPDATE {$table}
        SET
            point_value = ?,
            point_type = ?
        WHERE id = ?
    ");

$stmt->execute([
    $pointValue,
    $pointType,
    $sourceId,
]);

$oldPointValue =
    (float) (
        $oldRow['point_value']
        ?? 0
    );

$oldPointType =
    $oldRow['point_type']
    ?? null;

if (
    $oldPointValue !== (float) $pointValue
    ||
    $oldPointType !== $pointType
) {
    $activityLogger->log(
        current_user_id(),
        'activity_points_changed',
        $entityType,
        $sourceId,
        'Activity points changed',
        [
            'point_value' =>
                $oldPointValue,
            'point_type' =>
                $oldPointType,
        ],
        [
            'point_value' =>
                $pointValue,
            'point_type' =>
                $pointType,
        ],
        null,
        $oldRow['schedule_date']
            ?? null,
        $oldRow['period']
            ?? null
    );
}

json_response([
    'success' => true,
    'point_value' => $pointValue,
    'point_type' => $pointType,
]);
