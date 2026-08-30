<?php

final class ActivityLogger
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function log(
        ?int $actorUserId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $description,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?int $affectedUserId = null,
        ?string $scheduleDate = null,
        ?string $period = null,
        ?string $createdAt = null
    ): int {
        $stmt =
            $this->pdo->prepare("
                INSERT INTO activity_log
                (
                    actor_user_id,
                    affected_user_id,
                    action,
                    entity_type,
                    entity_id,
                    schedule_date,
                    period,
                    description,
                    old_value,
                    new_value,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    COALESCE(?, CURRENT_TIMESTAMP)
                )
            ");

        $stmt->execute([
            $actorUserId,
            $affectedUserId,
            $action,
            $entityType,
            $entityId,
            $scheduleDate,
            $period,
            $description,
            $this->encodeValue($oldValue),
            $this->encodeValue($newValue),
            $createdAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mirror the existing detailed calendar sync audit table into activity_log.
     *
     * IcsImporter already writes one row per calendar event change to
     * calendar_event_changes. This method makes those changes available to the
     * common audit trail and to ScheduleChangeTracker without duplicating the
     * calendar import logic.
     */
    public function mirrorCalendarChanges(): int
    {
        try {
            $stmt =
                $this->pdo->query("
                    SELECT
                        c.id AS calendar_change_id,
                        c.calendar_event_id,
                        c.change_type,
                        c.old_value,
                        c.new_value,
                        r.started_at AS sync_created_at,
                        e.schedule_date AS current_schedule_date,
                        e.period AS current_period,
                        e.summary AS current_summary
                    FROM calendar_event_changes c
                    LEFT JOIN calendar_activity_log_map m
                        ON m.calendar_change_id = c.id
                    LEFT JOIN calendar_sync_runs r
                        ON r.id = c.sync_run_id
                    LEFT JOIN calendar_events e
                        ON e.id = c.calendar_event_id
                    WHERE m.calendar_change_id IS NULL
                    ORDER BY c.id ASC
                ");
        } catch (Throwable) {
            /*
            | The migration may not have been run yet. Do not break the app.
            */
            return 0;
        }

        $rows =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mirrored = 0;

        foreach ($rows as $row) {
            $oldData =
                $this->decodeValue($row['old_value'] ?? null);

            $newData =
                $this->decodeValue($row['new_value'] ?? null);

            $type =
                (string) ($row['change_type'] ?? 'changed');

            $locationData =
                in_array($type, ['missing'], true)
                ? $oldData
                : $newData;

            if (!is_array($locationData)) {
                $locationData = [];
            }

            $scheduleDate =
                $locationData['schedule_date']
                ?? $row['current_schedule_date']
                ?? null;

            $period =
                $locationData['period']
                ?? $row['current_period']
                ?? null;

            if (!in_array($period, ['morning', 'evening'], true)) {
                $period = null;
            }

            $descriptionMap = [
                'created' => 'Calendar event added',
                'changed' => 'Calendar event changed',
                'restored' => 'Calendar event restored',
                'missing' => 'Calendar event removed from source',
            ];

            $actionMap = [
                'created' => 'calendar_event_created',
                'changed' => 'calendar_event_changed',
                'restored' => 'calendar_event_restored',
                'missing' => 'calendar_event_missing',
            ];

            $createdActivityIds = [];

            if (
                $type === 'moved'
                &&
                is_array($oldData)
                &&
                is_array($newData)
            ) {
                $oldPeriod =
                    $oldData['period']
                    ?? null;

                if (!in_array($oldPeriod, ['morning', 'evening'], true)) {
                    $oldPeriod = null;
                }

                $newPeriod =
                    $newData['period']
                    ?? null;

                if (!in_array($newPeriod, ['morning', 'evening'], true)) {
                    $newPeriod = null;
                }

                /*
                | A move changes two visible places: where the event disappeared
                | and where it appeared. Log both so both months/days can be marked.
                */
                $createdActivityIds[] =
                    $this->log(
                        null,
                        'calendar_event_moved_from',
                        'calendar_event',
                        (int) ($row['calendar_event_id'] ?? 0),
                        'Calendar event moved from this slot',
                        $oldData,
                        $newData,
                        null,
                        $oldData['schedule_date'] ?? null,
                        $oldPeriod,
                        $row['sync_created_at'] ?? null
                    );

                $createdActivityIds[] =
                    $this->log(
                        null,
                        'calendar_event_moved_to',
                        'calendar_event',
                        (int) ($row['calendar_event_id'] ?? 0),
                        'Calendar event moved to this slot',
                        $oldData,
                        $newData,
                        null,
                        $newData['schedule_date'] ?? null,
                        $newPeriod,
                        $row['sync_created_at'] ?? null
                    );
            } else {
                $createdActivityIds[] =
                    $this->log(
                        null,
                        $actionMap[$type] ?? 'calendar_event_changed',
                        'calendar_event',
                        (int) ($row['calendar_event_id'] ?? 0),
                        $descriptionMap[$type] ?? 'Calendar event changed',
                        $oldData,
                        $newData,
                        null,
                        $scheduleDate,
                        $period,
                        $row['sync_created_at'] ?? null
                    );
            }

            $activityId =
                (int) end($createdActivityIds);

            try {
                $mapStmt =
                    $this->pdo->prepare("
                        INSERT INTO calendar_activity_log_map
                        (
                            calendar_change_id,
                            activity_log_id,
                            mirrored_at
                        )
                        VALUES (?, ?, CURRENT_TIMESTAMP)
                    ");

                $mapStmt->execute([
                    (int) $row['calendar_change_id'],
                    $activityId,
                ]);

                $mirrored++;
            } catch (Throwable $e) {
                /*
                | A concurrent request may have mirrored the same row first.
                | Remove any duplicate activity rows created by this request.
                */
                try {
                    $delete =
                        $this->pdo->prepare(
                            'DELETE FROM activity_log WHERE id = ?'
                        );

                    foreach ($createdActivityIds as $createdActivityId) {
                        $delete->execute([
                            (int) $createdActivityId
                        ]);
                    }
                } catch (Throwable) {
                    // Do not hide the original mapping issue.
                }
            }
        }

        return $mirrored;
    }

    public function recent(
        int $limit = 200
    ): array {
        $this->mirrorCalendarChanges();

        $limit =
            max(
                1,
                min(
                    1000,
                    $limit
                )
            );

        $sql = "
            SELECT
                l.*,
                actor.name AS actor_name,
                actor.role AS actor_role,
                affected.name AS affected_name
            FROM activity_log l
            LEFT JOIN users actor
                ON actor.id = l.actor_user_id
            LEFT JOIN users affected
                ON affected.id = l.affected_user_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";

        return
            $this->pdo
            ->query($sql)
            ->fetchAll();
    }

    private function encodeValue(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
        );
    }

    private function decodeValue(
        ?string $value
    ): mixed {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded =
            json_decode(
                $value,
                true
            );

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }
}
