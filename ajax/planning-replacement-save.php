<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$type = trim((string)($_POST['source_type'] ?? ''));
$id = (int)($_POST['source_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$name = trim((string)($_POST['replacement_name'] ?? ''));

if (!in_array($type, ['calendar', 'slot', 'split'], true) || $id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid activity source.'], 400);
}

ajax_require_active_user($pdo, $userId);

if (mb_strlen($name) > 255) {
    json_response(['success' => false, 'message' => 'Replacement name is too long.'], 400);
}

if ($name === '') {
    $stmt = $pdo->prepare("
        DELETE FROM assignment_replacements
        WHERE source_type = ? AND source_id = ? AND user_id = ?
    ");
    $stmt->execute([$type, $id, $userId]);

    json_response(['success' => true, 'replacement_name' => '']);
}

$stmt = $pdo->prepare("
    SELECT 1
    FROM activity_assignments
    WHERE source_type = ? AND source_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$type, $id, $userId]);

if (!$stmt->fetchColumn()) {
    json_response([
        'success' => false,
        'message' => 'Assign the section member before adding an external replacement.',
    ], 400);
}

$stmt = $pdo->prepare("
    INSERT INTO assignment_replacements
        (source_type, source_id, user_id, replacement_name, updated_by)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        replacement_name = VALUES(replacement_name),
        updated_by = VALUES(updated_by),
        updated_at = CURRENT_TIMESTAMP
");
$stmt->execute([$type, $id, $userId, $name, current_user_id()]);

json_response([
    'success' => true,
    'replacement_name' => $name,
]);
