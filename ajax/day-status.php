<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$userId = (int)($_POST['user_id'] ?? 0);
$date = ajax_date((string)($_POST['date'] ?? ''));
$mode = trim((string)($_POST['mode'] ?? 'get'));

ajax_require_active_user($pdo, $userId);

$allowed = [
    '',
    'sick',
    'unpaid_leave',
];

try {
    if ($mode === 'set') {
        $status = trim((string)($_POST['day_status'] ?? ''));

        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Invalid day status.');
        }

        if ($status === '') {
            $stmt = $pdo->prepare("
                DELETE FROM user_day_status
                WHERE user_id = ? AND schedule_date = ?
            ");
            $stmt->execute([$userId, $date]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO user_day_status
                    (user_id, schedule_date, status, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $userId,
                $date,
                $status,
                current_user_id(),
            ]);
        }
    }

    $stmt = $pdo->prepare("
        SELECT status
        FROM user_day_status
        WHERE user_id = ? AND schedule_date = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $date]);

    $status = $stmt->fetchColumn();

    json_response([
        'success' => true,
        'day_status' => $status === false ? '' : $status,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
