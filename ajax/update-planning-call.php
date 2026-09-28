<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$sourceType = trim((string)($_POST['source_type'] ?? ''));
$sourceId = (int)($_POST['source_id'] ?? 0);
$period = trim((string)($_POST['period'] ?? ''));
$pointValueRaw = trim((string)($_POST['point_value'] ?? ''));
$pointType = trim((string)($_POST['point_type'] ?? ''));

$tableMap = [
    'calendar' => [
        'table' => 'calendar_events',
        'entity' => 'calendar_event',
    ],
    'split' => [
        'table' => 'schedule_split_events',
        'entity' => 'split_event',
    ],
    'slot' => [
        'table' => 'schedule_slots',
        'entity' => 'schedule_slot',
    ],
];

if ($sourceId <= 0 || !isset($tableMap[$sourceType])) {
    json_response([
        'success' => false,
        'message' => 'Invalid activity source.',
    ], 400);
}

if (!in_array($period, ['morning', 'evening'], true)) {
    json_response([
        'success' => false,
        'message' => 'Invalid morning/evening choice.',
    ], 400);
}

if (
    $pointValueRaw === ''
    || filter_var($pointValueRaw, FILTER_VALIDATE_INT) === false
) {
    json_response([
        'success' => false,
        'message' => 'Point value must be a whole number.',
    ], 400);
}

$pointValue = (int)$pointValueRaw;

if ($pointValue < 0 || $pointValue > 9999) {
    json_response([
        'success' => false,
        'message' => 'Point value must be between 0 and 9999.',
    ], 400);
}

if ($pointType === '') {
    $pointType = null;
}

if (
    $pointType !== null
    && !in_array($pointType, ['rehearsal', 'performance'], true)
) {
    json_response([
        'success' => false,
        'message' => 'Invalid point type.',
    ], 400);
}

$table = $tableMap[$sourceType]['table'];

$check = $pdo->prepare("
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
$check->execute([$sourceId]);

$old = $check->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    json_response([
        'success' => false,
        'message' => 'Activity not found.',
    ], 404);
}

$update = $pdo->prepare("
    UPDATE {$table}
    SET
        period = ?,
        point_value = ?,
        point_type = ?
    WHERE id = ?
");

$update->execute([
    $period,
    $pointValue,
    $pointType,
    $sourceId,
]);

if (
    ($old['period'] ?? null) !== $period
    || (float)($old['point_value'] ?? 0) !== (float)$pointValue
    || ($old['point_type'] ?? null) !== $pointType
) {
    $activityLogger->log(
        current_user_id(),
        'activity_planning_changed',
        $tableMap[$sourceType]['entity'],
        $sourceId,
        'Activity planning settings changed',
        [
            'period' => $old['period'] ?? null,
            'point_value' => (float)($old['point_value'] ?? 0),
            'point_type' => $old['point_type'] ?? null,
        ],
        [
            'period' => $period,
            'point_value' => $pointValue,
            'point_type' => $pointType,
        ],
        null,
        $old['schedule_date'] ?? null,
        $period
    );
}

json_response([
    'success' => true,
    'period' => $period,
    'point_value' => $pointValue,
    'point_type' => $pointType,
]);
