<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/Planning.php';
ajax_require_admin();
$type = trim((string)($_POST['source_type'] ?? ''));
$id = (int)($_POST['source_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$assigned = (string)($_POST['assigned'] ?? '0') === '1';
ajax_require_active_user($pdo, $userId);
try {
    (new Planning($pdo))->setAssignment($type, $id, $userId, $assigned, current_user_id());
    json_response(['success' => true]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 400);
}
