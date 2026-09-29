<?php

require_once __DIR__ . '/_bootstrap.php';

ajax_require_admin();

$type = trim((string)($_POST['source_type'] ?? ''));
$id = (int)($_POST['source_id'] ?? 0);

$tableMap = [
    'calendar' => 'calendar_events',
    'slot' => 'schedule_slots',
    'split' => 'schedule_split_events',
];

if ($id <= 0 || !isset($tableMap[$type])) {
    json_response(['success' => false, 'message' => 'Invalid activity source.'], 400);
}

$archiveDate = '1900-01-01';

try {
    $pdo->beginTransaction();

    if ($type === 'calendar') {
        $stmt = $pdo->prepare("
            SELECT id, source_id, source_uid, schedule_date, period, summary
            FROM calendar_events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            throw new RuntimeException('Calendar activity not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO planning_exclusions
                (source_type, source_id, calendar_source_id, calendar_source_uid,
                 original_date, original_period, title, excluded_by)
            VALUES ('calendar', ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                calendar_source_id = VALUES(calendar_source_id),
                calendar_source_uid = VALUES(calendar_source_uid),
                original_date = VALUES(original_date),
                original_period = VALUES(original_period),
                title = VALUES(title),
                excluded_by = VALUES(excluded_by),
                excluded_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $id,
            $event['source_id'],
            $event['source_uid'],
            $event['schedule_date'],
            $event['period'],
            $event['summary'],
            current_user_id(),
        ]);

        // Linked split representation must disappear from Calendar too.
        $stmt = $pdo->prepare("
            DELETE FROM schedule_split_events
            WHERE calendar_event_id = ?
        ");
        $stmt->execute([$id]);

        /*
        | Calendar events are retained so their source UID and history remain
        | available. Give every archived event its own harmless date to avoid
        | any date/period uniqueness assumptions.
        */
        $archiveDateForEvent = (new DateTimeImmutable('1900-01-01'))
            ->modify('+' . $id . ' days')
            ->format('Y-m-d');

        $stmt = $pdo->prepare("
            UPDATE calendar_events
            SET schedule_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$archiveDateForEvent, $id]);
    } elseif ($type === 'slot') {
        $stmt = $pdo->prepare("
            SELECT id, schedule_date, period, activity
            FROM schedule_slots
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            throw new RuntimeException('Manual activity not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO planning_exclusions
                (source_type, source_id, original_date, original_period, title, excluded_by)
            VALUES ('slot', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                original_date = VALUES(original_date),
                original_period = VALUES(original_period),
                title = VALUES(title),
                excluded_by = VALUES(excluded_by),
                excluded_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $id,
            $slot['schedule_date'],
            $slot['period'],
            $slot['activity'],
            current_user_id(),
        ]);

        /*
        | schedule_slots has a UNIQUE(schedule_date, period) key, so moving every
        | removed slot to one archive date can collide. The exclusion row above
        | preserves the audit metadata; the effective manual slot itself can be
        | deleted safely.
        */
        $stmt = $pdo->prepare("
            DELETE FROM schedule_slots
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, schedule_date, period, activity
            FROM schedule_split_events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $split = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$split) {
            throw new RuntimeException('Split activity not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO planning_exclusions
                (source_type, source_id, original_date, original_period, title, excluded_by)
            VALUES ('split', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                original_date = VALUES(original_date),
                original_period = VALUES(original_period),
                title = VALUES(title),
                excluded_by = VALUES(excluded_by),
                excluded_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $id,
            $split['schedule_date'],
            $split['period'],
            $split['activity'],
            current_user_id(),
        ]);

        /*
        | Manual split events are effective schedule rows. Their exclusion/audit
        | record is already stored above, so delete the live row instead of
        | parking many rows on the same fake date.
        */
        $stmt = $pdo->prepare("
            DELETE FROM schedule_split_events
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    $pdo->commit();

    json_response(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
