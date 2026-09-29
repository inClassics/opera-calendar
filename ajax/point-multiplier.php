<?php

require_once __DIR__ . '/_bootstrap.php';

$userId = (int)($_POST['user_id'] ?? 0);
$scope = trim((string)($_POST['scope'] ?? ''));
$mode = trim((string)($_POST['mode'] ?? 'get'));

ajax_require_admin();
ajax_require_active_user($pdo, $userId);

try {
    if ($scope === 'normal') {
        $date = ajax_date((string)($_POST['date'] ?? ''));
        $period = ajax_period((string)($_POST['period'] ?? ''));

        if ($mode === 'set') {
            $multiplier = (float)($_POST['point_multiplier'] ?? 1);

            if ($multiplier < 0 || $multiplier > 10) {
                throw new RuntimeException('Point multiplier must be between 0 and 10.');
            }

            if (abs($multiplier - 1.0) < 0.00001) {
                $stmt = $pdo->prepare("
                    DELETE FROM availability_point_multipliers
                    WHERE user_id = ? AND schedule_date = ? AND period = ?
                ");
                $stmt->execute([$userId, $date, $period]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO availability_point_multipliers
                        (user_id, schedule_date, period, point_multiplier, updated_by)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        point_multiplier = VALUES(point_multiplier),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $userId,
                    $date,
                    $period,
                    $multiplier,
                    current_user_id()
                ]);
            }
        }

        $stmt = $pdo->prepare("
            SELECT point_multiplier
            FROM availability_point_multipliers
            WHERE user_id = ? AND schedule_date = ? AND period = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $date, $period]);

        $value = $stmt->fetchColumn();

        json_response([
            'success' => true,
            'point_multiplier' => $value === false ? 1 : (float)$value,
        ]);
    }

    if ($scope === 'split') {
        $eventId = (int)($_POST['split_event_id'] ?? 0);
        ajax_require_split_event($pdo, $eventId);

        if ($mode === 'set') {
            $multiplier = (float)($_POST['point_multiplier'] ?? 1);

            if ($multiplier < 0 || $multiplier > 10) {
                throw new RuntimeException('Point multiplier must be between 0 and 10.');
            }

            if (abs($multiplier - 1.0) < 0.00001) {
                $stmt = $pdo->prepare("
                    DELETE FROM split_point_multipliers
                    WHERE split_event_id = ? AND user_id = ?
                ");
                $stmt->execute([$eventId, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO split_point_multipliers
                        (split_event_id, user_id, point_multiplier, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        point_multiplier = VALUES(point_multiplier),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $eventId,
                    $userId,
                    $multiplier,
                    current_user_id()
                ]);
            }
        }

        $stmt = $pdo->prepare("
            SELECT point_multiplier
            FROM split_point_multipliers
            WHERE split_event_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId, $userId]);

        $value = $stmt->fetchColumn();

        json_response([
            'success' => true,
            'point_multiplier' => $value === false ? 1 : (float)$value,
        ]);
    }

    throw new RuntimeException('Invalid multiplier scope.');
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
