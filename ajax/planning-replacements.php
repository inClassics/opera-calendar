<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$type = trim((string)($_POST['source_type'] ?? ''));
$id = (int)($_POST['source_id'] ?? 0);

if (!in_array($type, ['calendar', 'slot', 'split'], true) || $id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid activity source.'], 400);
}

$stmt = $pdo->prepare("
    SELECT user_id, replacement_name
    FROM assignment_replacements
    WHERE source_type = ? AND source_id = ?
");
$stmt->execute([$type, $id]);

$result = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $result[(string)(int)$row['user_id']] = $row['replacement_name'];
}

json_response([
    'success' => true,
    'replacements' => $result,
]);
