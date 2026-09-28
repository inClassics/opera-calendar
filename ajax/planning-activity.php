<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/Planning.php';
ajax_require_admin();
$type = trim((string)($_POST['source_type'] ?? ''));
$id = (int)($_POST['source_id'] ?? 0);
try {
    $planning = new Planning($pdo);
    json_response(['success' => true] + $planning->availabilityForActivity($type, $id));
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 400);
}
