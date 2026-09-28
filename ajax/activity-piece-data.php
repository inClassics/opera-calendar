<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$sourceType = trim((string) ($_POST['source_type'] ?? ''));
$sourceId = (int) ($_POST['source_id'] ?? 0);

$tableMap = [
    'calendar' => 'calendar_events',
    'split' => 'schedule_split_events',
    'slot' => 'schedule_slots',
];

if ($sourceId <= 0 || !isset($tableMap[$sourceType])) {
    json_response([
        'success' => false,
        'message' => 'Invalid activity source.',
    ], 400);
}

$table = $tableMap[$sourceType];

$stmt = $pdo->prepare("
    SELECT piece_id, required_basses_override
    FROM {$table}
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$sourceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_response([
        'success' => false,
        'message' => 'Activity not found.',
    ], 404);
}

$pieces = $pdo->query("
    SELECT id, title, default_basses, type
    FROM pieces
    WHERE status = 1
    ORDER BY title ASC
")->fetchAll(PDO::FETCH_ASSOC);

json_response([
    'success' => true,
    'piece_id' => $row['piece_id'] !== null ? (int) $row['piece_id'] : null,
    'required_basses_override' =>
    $row['required_basses_override'] !== null
        ? (int) $row['required_basses_override']
        : null,
    'pieces' => array_map(
        static fn(array $piece): array => [
            'id' => (int) $piece['id'],
            'title' => (string) $piece['title'],
            'default_basses' => (int) $piece['default_basses'],
            'type' => (string) $piece['type'],
        ],
        $pieces
    ),
]);
