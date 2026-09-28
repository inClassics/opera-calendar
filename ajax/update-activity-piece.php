<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$sourceType = trim((string) ($_POST['source_type'] ?? ''));
$sourceId = (int) ($_POST['source_id'] ?? 0);
$pieceIdRaw = trim((string) ($_POST['piece_id'] ?? ''));
$requiredRaw = trim((string) ($_POST['required_basses_override'] ?? ''));

$tableMap = [
    'calendar' => ['table' => 'calendar_events', 'entity' => 'calendar_event'],
    'split' => ['table' => 'schedule_split_events', 'entity' => 'split_event'],
    'slot' => ['table' => 'schedule_slots', 'entity' => 'schedule_slot'],
];

if ($sourceId <= 0 || !isset($tableMap[$sourceType])) {
    json_response(['success' => false, 'message' => 'Invalid activity source.'], 400);
}

$pieceId = null;

if ($pieceIdRaw !== '') {
    if (
        filter_var($pieceIdRaw, FILTER_VALIDATE_INT) === false
        || (int) $pieceIdRaw <= 0
    ) {
        json_response(['success' => false, 'message' => 'Invalid piece.'], 400);
    }

    $pieceId = (int) $pieceIdRaw;

    $pieceCheck = $pdo->prepare(
        'SELECT id FROM pieces WHERE id = ? AND status = 1 LIMIT 1'
    );
    $pieceCheck->execute([$pieceId]);

    if (!$pieceCheck->fetchColumn()) {
        json_response([
            'success' => false,
            'message' => 'Piece not found or inactive.',
        ], 404);
    }
}

$required = null;

if ($requiredRaw !== '') {
    if (
        filter_var($requiredRaw, FILTER_VALIDATE_INT) === false
        || (int) $requiredRaw < 1
        || (int) $requiredRaw > 7
    ) {
        json_response([
            'success' => false,
            'message' => 'Required basses must be between 1 and 7.',
        ], 400);
    }

    if ($pieceId === null) {
        json_response([
            'success' => false,
            'message' => 'Assign a piece before setting an override.',
        ], 400);
    }

    $required = (int) $requiredRaw;
}

$table = $tableMap[$sourceType]['table'];

$check = $pdo->prepare("
    SELECT id, schedule_date, period, piece_id, required_basses_override
    FROM {$table}
    WHERE id = ?
    LIMIT 1
");
$check->execute([$sourceId]);
$old = $check->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    json_response(['success' => false, 'message' => 'Activity not found.'], 404);
}

$update = $pdo->prepare("
    UPDATE {$table}
    SET piece_id = ?, required_basses_override = ?
    WHERE id = ?
");
$update->execute([$pieceId, $required, $sourceId]);

if (
    (int) ($old['piece_id'] ?? 0) !== (int) ($pieceId ?? 0)
    || (int) ($old['required_basses_override'] ?? 0) !== (int) ($required ?? 0)
) {
    $activityLogger->log(
        current_user_id(),
        'activity_piece_changed',
        $tableMap[$sourceType]['entity'],
        $sourceId,
        'Activity piece assignment changed',
        [
            'piece_id' =>
            $old['piece_id'] !== null
                ? (int) $old['piece_id']
                : null,
            'required_basses_override' =>
            $old['required_basses_override'] !== null
                ? (int) $old['required_basses_override']
                : null,
        ],
        [
            'piece_id' => $pieceId,
            'required_basses_override' => $required,
        ],
        null,
        $old['schedule_date'] ?? null,
        $old['period'] ?? null
    );
}

json_response([
    'success' => true,
    'piece_id' => $pieceId,
    'required_basses_override' => $required,
]);
