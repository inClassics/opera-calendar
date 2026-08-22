<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Not logged in.'
    ], 401);
}

if (!is_admin()) {
    json_response([
        'success' => false,
        'message' => 'Admin access required.'
    ], 403);
}

verify_csrf_or_fail(
    $_POST['csrf_token'] ?? null
);

$sourceType = trim(
    (string) ($_POST['source_type'] ?? '')
);

$sourceId = (int) (
    $_POST['source_id'] ?? 0
);

$pointValueRaw = trim(
    (string) ($_POST['point_value'] ?? '')
);

$pointType = trim(
    (string) ($_POST['point_type'] ?? '')
);

if ($pointType === '') {
    $pointType = null;
}

if (
    $sourceId <= 0
    ||
    !in_array(
        $sourceType,
        ['calendar', 'split', 'slot'],
        true
    )
) {
    json_response([
        'success' => false,
        'message' => 'Invalid activity source.',
        'debug' => [
            'source_type' => $sourceType,
            'source_id' => $sourceId
        ]
    ], 400);
}

if (
    $pointValueRaw === ''
    ||
    !is_numeric($pointValueRaw)
) {
    json_response([
        'success' => false,
        'message' => 'Point value must be numeric.'
    ], 400);
}

$pointValue = (int) $pointValueRaw;

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
        'message' => 'Invalid point type.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| Determine table
|--------------------------------------------------------------------------
*/

switch ($sourceType) {

    case 'calendar':
        $table = 'calendar_events';
        break;

    case 'split':
        $table = 'schedule_split_events';
        break;

    case 'slot':
        $table = 'schedule_slots';
        break;

    default:
        json_response([
            'success' => false,
            'message' => 'Unknown source type.'
        ], 400);
}

/*
|--------------------------------------------------------------------------
| Debug which database PHP is actually connected to
|--------------------------------------------------------------------------
*/

$databaseName = $pdo
    ->query('SELECT DATABASE()')
    ->fetchColumn();

/*
|--------------------------------------------------------------------------
| Read BEFORE
|--------------------------------------------------------------------------
*/

$beforeStmt = $pdo->prepare("
    SELECT
        id,
        point_value,
        point_type
    FROM {$table}
    WHERE id = ?
    LIMIT 1
");

$beforeStmt->execute([
    $sourceId
]);

$before = $beforeStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$before) {

    json_response([
        'success' => false,
        'message' => 'The activity row does not exist.',
        'debug' => [
            'database' => $databaseName,
            'table' => $table,
            'source_type' => $sourceType,
            'source_id' => $sourceId
        ]
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

$updateStmt = $pdo->prepare("
    UPDATE {$table}
    SET
        point_value = ?,
        point_type = ?
    WHERE id = ?
");

$updateStmt->execute([
    $pointValue,
    $pointType,
    $sourceId
]);

/*
|--------------------------------------------------------------------------
| Read AFTER
|--------------------------------------------------------------------------
*/

$afterStmt = $pdo->prepare("
    SELECT
        id,
        point_value,
        point_type
    FROM {$table}
    WHERE id = ?
    LIMIT 1
");

$afterStmt->execute([
    $sourceId
]);

$after = $afterStmt->fetch(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Verify persistence
|--------------------------------------------------------------------------
*/

if (
    !$after
    ||
    (float) $after['point_value']
    !== (float) $pointValue
    ||
    ($after['point_type'] ?? null)
    !== $pointType
) {
    json_response([
        'success' => false,
        'message' => 'Database update did not persist.',
        'debug' => [
            'database' => $databaseName,
            'table' => $table,
            'before' => $before,
            'after' => $after,
            'requested_point_value' => $pointValue,
            'requested_point_type' => $pointType
        ]
    ], 500);
}

json_response([
    'success' => true,

    'point_value' =>
    (int) $after['point_value'],

    'point_type' =>
    $after['point_type'],

    /*
    | TEMPORARY DEBUG INFORMATION
    */
    'debug' => [
        'database' => $databaseName,
        'table' => $table,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'before' => $before,
        'after' => $after,
        'affected_rows' => $updateStmt->rowCount()
    ]
]);
