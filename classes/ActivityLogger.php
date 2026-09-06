<?php

final class ActivityLogger
{
    private const AVAILABILITY_COALESCE_SECONDS = 30;

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
     * Log the FINAL logical availability state, not every UI click.
     *
     * Repeated edits by the same actor to the same member/date/period within
     * 30 seconds are folded into one row:
     *
     *     blank -> available -> unavailable -> uncertain
     *
     * becomes:
     *
     *     blank -> uncertain
     *
     * If the final state equals the original state, the row is removed.
     */
    public function logAvailabilityState(
        int $actorUserId,
        int $affectedUserId,
        string $scheduleDate,
        string $period,
        array $before,
        array $after,
        ?int $entityId = null
    ): ?int {
        $before =
            $this->normalizeAvailabilityState(
                $before
            );

        $after =
            $this->normalizeAvailabilityState(
                $after
            );

        if (
            $this->availabilityStatesEqual(
                $before,
                $after
            )
        ) {
            return null;
        }

        $stmt =
            $this->pdo->prepare("
                SELECT
                    id,
                    old_value,
                    new_value
                FROM activity_log
                WHERE actor_user_id = ?
                  AND affected_user_id = ?
                  AND action = 'availability_changed'
                  AND entity_type = 'availability'
                  AND schedule_date = ?
                  AND period = ?
                  AND created_at >=
                      DATE_SUB(
                          NOW(),
                          INTERVAL " .
                self::AVAILABILITY_COALESCE_SECONDS .
                " SECOND
                      )
                ORDER BY id DESC
                LIMIT 1
            ");

        $stmt->execute([
            $actorUserId,
            $affectedUserId,
            $scheduleDate,
            $period,
        ]);

        $existing =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($existing) {
            $originalBefore =
                $this->normalizeAvailabilityState(
                    $this->decodeValue(
                        $existing['old_value']
                            ?? null
                    )
                );

            if (
                $this->availabilityStatesEqual(
                    $originalBefore,
                    $after
                )
            ) {
                /*
                | The user cycled back to the state that existed before the
                | editing sequence. There is no meaningful change to retain.
                */
                $delete =
                    $this->pdo->prepare(
                        'DELETE FROM activity_log WHERE id = ?'
                    );

                $delete->execute([
                    (int) $existing['id']
                ]);

                return null;
            }

            $update =
                $this->pdo->prepare("
                    UPDATE activity_log
                    SET
                        entity_id = ?,
                        description = ?,
                        new_value = ?,
                        created_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

            $update->execute([
                $entityId,
                'Availability changed',
                $this->encodeValue(
                    $after
                ),
                (int) $existing['id'],
            ]);

            return
                (int) $existing['id'];
        }

        return $this->log(
            $actorUserId,
            'availability_changed',
            'availability',
            $entityId,
            'Availability changed',
            $before,
            $after,
            $affectedUserId,
            $scheduleDate,
            $period
        );
    }

    /**
     * Same behavior for one member on one split event.
     */
    public function logSplitAvailabilityState(
        int $actorUserId,
        int $affectedUserId,
        int $splitEventId,
        string $scheduleDate,
        string $period,
        string $activity,
        array $before,
        array $after
    ): ?int {
        $before =
            $this->normalizeAvailabilityState(
                $before
            );

        $after =
            $this->normalizeAvailabilityState(
                $after
            );

        $before['activity'] =
            $activity;

        $after['activity'] =
            $activity;

        if (
            $this->availabilityStatesEqual(
                $before,
                $after,
                true
            )
        ) {
            return null;
        }

        $stmt =
            $this->pdo->prepare("
                SELECT
                    id,
                    old_value,
                    new_value
                FROM activity_log
                WHERE actor_user_id = ?
                  AND affected_user_id = ?
                  AND action = 'split_availability_changed'
                  AND entity_type = 'split_event'
                  AND entity_id = ?
                  AND created_at >=
                      DATE_SUB(
                          NOW(),
                          INTERVAL " .
                self::AVAILABILITY_COALESCE_SECONDS .
                " SECOND
                      )
                ORDER BY id DESC
                LIMIT 1
            ");

        $stmt->execute([
            $actorUserId,
            $affectedUserId,
            $splitEventId,
        ]);

        $existing =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($existing) {
            $originalBefore =
                $this->normalizeAvailabilityState(
                    $this->decodeValue(
                        $existing['old_value']
                            ?? null
                    )
                );

            $originalBefore['activity'] =
                $activity;

            if (
                $this->availabilityStatesEqual(
                    $originalBefore,
                    $after,
                    true
                )
            ) {
                $delete =
                    $this->pdo->prepare(
                        'DELETE FROM activity_log WHERE id = ?'
                    );

                $delete->execute([
                    (int) $existing['id']
                ]);

                return null;
            }

            $update =
                $this->pdo->prepare("
                    UPDATE activity_log
                    SET
                        schedule_date = ?,
                        period = ?,
                        description = ?,
                        new_value = ?,
                        created_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

            $update->execute([
                $scheduleDate,
                $period,
                'Split-event availability changed',
                $this->encodeValue(
                    $after
                ),
                (int) $existing['id'],
            ]);

            return
                (int) $existing['id'];
        }

        return $this->log(
            $actorUserId,
            'split_availability_changed',
            'split_event',
            $splitEventId,
            'Split-event availability changed',
            $before,
            $after,
            $affectedUserId,
            $scheduleDate,
            $period
        );
    }

    public function mirrorCalendarChangesForRun(
        int $syncRunId
    ): int {
        if ($syncRunId <= 0) {
            return 0;
        }

        try {
            $stmt =
                $this->pdo->prepare("
                    SELECT
                        c.id AS calendar_change_id,
                        c.calendar_event_id,
                        c.change_type,
                        c.old_value,
                        c.new_value,
                        r.started_at AS sync_created_at,
                        e.schedule_date AS current_schedule_date,
                        e.period AS current_period
                    FROM calendar_event_changes c
                    LEFT JOIN calendar_activity_log_map m
                        ON m.calendar_change_id = c.id
                    LEFT JOIN calendar_sync_runs r
                        ON r.id = c.sync_run_id
                    LEFT JOIN calendar_events e
                        ON e.id = c.calendar_event_id
                    WHERE c.sync_run_id = ?
                      AND m.calendar_change_id IS NULL
                    ORDER BY c.id ASC
                ");

            $stmt->execute([
                $syncRunId
            ]);
        } catch (Throwable) {
            return 0;
        }

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $mirrored = 0;

        foreach ($rows as $row) {
            $oldData =
                $this->decodeValue(
                    $row['old_value']
                        ?? null
                );

            $newData =
                $this->decodeValue(
                    $row['new_value']
                        ?? null
                );

            $type =
                (string) (
                    $row['change_type']
                    ?? 'changed'
                );

            $createdActivityIds = [];

            if (
                $type === 'moved'
                &&
                is_array($oldData)
                &&
                is_array($newData)
            ) {
                $oldPeriod =
                    $this->validPeriod(
                        $oldData['period']
                            ?? null
                    );

                $newPeriod =
                    $this->validPeriod(
                        $newData['period']
                            ?? null
                    );

                $createdActivityIds[] =
                    $this->log(
                        null,
                        'calendar_event_moved_from',
                        'calendar_event',
                        (int) (
                            $row['calendar_event_id']
                            ?? 0
                        ),
                        'Calendar event moved from this slot',
                        $oldData,
                        $newData,
                        null,
                        $oldData['schedule_date']
                            ?? null,
                        $oldPeriod,
                        $row['sync_created_at']
                            ?? null
                    );

                $createdActivityIds[] =
                    $this->log(
                        null,
                        'calendar_event_moved_to',
                        'calendar_event',
                        (int) (
                            $row['calendar_event_id']
                            ?? 0
                        ),
                        'Calendar event moved to this slot',
                        $oldData,
                        $newData,
                        null,
                        $newData['schedule_date']
                            ?? null,
                        $newPeriod,
                        $row['sync_created_at']
                            ?? null
                    );
            } else {
                $descriptionMap = [
                    'created' =>
                    'Calendar event added',
                    'changed' =>
                    'Calendar event changed',
                    'restored' =>
                    'Calendar event restored',
                    'missing' =>
                    'Calendar event removed from source',
                ];

                $actionMap = [
                    'created' =>
                    'calendar_event_created',
                    'changed' =>
                    'calendar_event_changed',
                    'restored' =>
                    'calendar_event_restored',
                    'missing' =>
                    'calendar_event_missing',
                ];

                $locationData =
                    $type === 'missing'
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
                    $this->validPeriod(
                        $locationData['period']
                            ?? $row['current_period']
                            ?? null
                    );

                $createdActivityIds[] =
                    $this->log(
                        null,
                        $actionMap[$type]
                            ?? 'calendar_event_changed',
                        'calendar_event',
                        (int) (
                            $row['calendar_event_id']
                            ?? 0
                        ),
                        $descriptionMap[$type]
                            ?? 'Calendar event changed',
                        $oldData,
                        $newData,
                        null,
                        $scheduleDate,
                        $period,
                        $row['sync_created_at']
                            ?? null
                    );
            }

            if (!$createdActivityIds) {
                continue;
            }

            $activityId =
                (int) end(
                    $createdActivityIds
                );

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
            } catch (Throwable) {
                try {
                    $delete =
                        $this->pdo->prepare(
                            'DELETE FROM activity_log WHERE id = ?'
                        );

                    foreach (
                        $createdActivityIds
                        as $createdActivityId
                    ) {
                        $delete->execute([
                            (int) $createdActivityId
                        ]);
                    }
                } catch (Throwable) {
                    // Do not break calendar sync.
                }
            }
        }

        return $mirrored;
    }

    public function recent(
        int $limit = 200
    ): array {
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

    private function normalizeAvailabilityState(
        mixed $state
    ): array {
        if (!is_array($state)) {
            $state = [];
        }

        $status =
            (string) (
                $state['status']
                ?? ''
            );

        if (
            !in_array(
                $status,
                [
                    '',
                    'available',
                    'unavailable'
                ],
                true
            )
        ) {
            $status = '';
        }

        $uncertain =
            !empty($state['uncertain']);

        /*
        | Blank has no question-mark state.
        */
        if ($status === '') {
            $uncertain = false;
        }

        return [
            'status' =>
            $status,
            'uncertain' =>
            $uncertain,
        ];
    }

    private function availabilityStatesEqual(
        array $a,
        array $b,
        bool $includeActivity = false
    ): bool {
        if (
            ($a['status'] ?? '')
            !==
            ($b['status'] ?? '')
        ) {
            return false;
        }

        if (
            !empty($a['uncertain'])
            !==
            !empty($b['uncertain'])
        ) {
            return false;
        }

        if (
            $includeActivity
            &&
            (string) ($a['activity'] ?? '')
            !==
            (string) ($b['activity'] ?? '')
        ) {
            return false;
        }

        return true;
    }

    private function validPeriod(
        mixed $period
    ): ?string {
        $period =
            is_string($period)
            ? $period
            : null;

        return in_array(
            $period,
            [
                'morning',
                'evening'
            ],
            true
        )
            ? $period
            : null;
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
        if (
            $value === null
            ||
            $value === ''
        ) {
            return null;
        }

        $decoded =
            json_decode(
                $value,
                true
            );

        return
            json_last_error()
            === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }
}
