<?php

class Schedule
{
    public function __construct(private PDO $pdo) {}

    public function monthContext(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2020 || $year > 2100) $year = (int) date('Y');

        $monthFirstDay = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $monthLastDay = (clone $monthFirstDay)->modify('last day of this month');

        $firstDay = clone $monthFirstDay;
        if ((int) $firstDay->format('N') !== 1) $firstDay->modify('previous monday');

        $lastDay = clone $monthLastDay;
        if ((int) $lastDay->format('N') !== 7) $lastDay->modify('next sunday');

        return [
            'year' => $year,
            'month' => $month,
            'monthFirstDay' => $monthFirstDay,
            'monthLastDay' => $monthLastDay,
            'firstDay' => $firstDay,
            'lastDay' => $lastDay,
            'previousMonth' => (clone $monthFirstDay)->modify('-1 month'),
            'nextMonth' => (clone $monthFirstDay)->modify('+1 month'),
            'monthTitle' => $monthFirstDay->format('F Y'),
        ];
    }

    public function daysForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $slots = $this->loadSlots($firstDay, $lastDay);
        $days = [];
        $current = clone $firstDay;

        while ($current <= $lastDay) {
            $date = $current->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'weekday' => $current->format('l'),
                'weekday_short' => $current->format('D'),
                'day' => (int) $current->format('j'),
                'evening' => $slots[$date]['evening'] ?? '',
                'morning' => $slots[$date]['morning'] ?? '',
            ];
            $current->modify('+1 day');
        }

        return $days;
    }

    public function availabilityForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id, schedule_date, period, status, uncertain
             FROM availability
             WHERE schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $availability = [];
        foreach ($stmt->fetchAll() as $row) {
            $availability[$row['schedule_date']][$row['period']][(int) $row['user_id']] = [
                'status' => $row['status'],
                'uncertain' => (bool) $row['uncertain'],
            ];
        }
        return $availability;
    }

    public function saveAvailability(int $userId, string $date, string $period, string $status, int $updatedBy): void
    {
        if ($status === '') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM availability WHERE user_id = ? AND schedule_date = ? AND period = ?"
            );
            $stmt->execute([$userId, $date, $period]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO availability (user_id, schedule_date, period, status, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $date, $period, $status, $updatedBy]);
    }

    public function saveActivity(string $date, string $period, string $activity, int $updatedBy): void
    {
        if ($activity === '') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM schedule_slots WHERE schedule_date = ? AND period = ?"
            );
            $stmt->execute([$date, $period]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO schedule_slots (schedule_date, period, activity, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                activity = VALUES(activity),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$date, $period, $activity, $updatedBy]);
    }

    private function loadSlots(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT schedule_date, period, activity
             FROM schedule_slots
             WHERE schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $manual = [];
        foreach ($stmt->fetchAll() as $row) {
            $manual[$row['schedule_date']][$row['period']] = $row['activity'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT schedule_date, period, summary, start_local, end_local
             FROM calendar_events
             WHERE schedule_date BETWEEN ? AND ?
             ORDER BY start_local ASC"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $imported = [];
        foreach ($stmt->fetchAll() as $row) {
            $start = new DateTime($row['start_local']);
            $end = !empty($row['end_local']) ? new DateTime($row['end_local']) : null;
            $text = $start->format('H:i');
            if ($end) $text .= '–' . $end->format('H:i');
            $text .= ' ' . $row['summary'];
            $imported[$row['schedule_date']][$row['period']][] = $text;
        }

        $slots = [];
        $current = clone $firstDay;

        while ($current <= $lastDay) {
            $date = $current->format('Y-m-d');
            foreach (['morning', 'evening'] as $period) {
                if (array_key_exists($period, $manual[$date] ?? [])) {
                    $slots[$date][$period] = $manual[$date][$period];
                } elseif (!empty($imported[$date][$period])) {
                    $slots[$date][$period] = implode("\n", $imported[$date][$period]);
                }
            }
            $current->modify('+1 day');
        }

        return $slots;
    }

    public function setUncertain(int $userId, string $date, string $period, bool $uncertain, int $updatedBy): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE availability
             SET uncertain = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND schedule_date = ? AND period = ? AND status IS NOT NULL"
        );
        $stmt->execute([$uncertain ? 1 : 0, $updatedBy, $userId, $date, $period]);
    }

    public function splitEventsForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, schedule_date, period, activity, sort_order
             FROM schedule_split_events
             WHERE schedule_date BETWEEN ? AND ?
             ORDER BY schedule_date ASC, period ASC, sort_order ASC, id ASC"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['schedule_date']][$row['period']][] = [
                'id' => (int) $row['id'],
                'activity' => $row['activity'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        return $result;
    }

    public function splitAvailabilityForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.split_event_id, sa.user_id, sa.status, sa.uncertain
             FROM split_availability sa
             INNER JOIN schedule_split_events se ON se.id = sa.split_event_id
             WHERE se.schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['split_event_id']][(int) $row['user_id']] = [
                'status' => $row['status'],
                'uncertain' => (bool) $row['uncertain'],
            ];
        }
        return $result;
    }

    public function splitSlot(string $date, string $period, string $activity, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM schedule_split_events WHERE schedule_date = ? AND period = ? LIMIT 1"
        );
        $stmt->execute([$date, $period]);
        if ($stmt->fetch()) {
            throw new RuntimeException('This slot is already split.');
        }

        $lines = preg_split('/\R+/', trim($activity));
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
        if (count($lines) < 2) {
            throw new RuntimeException('At least two activities are required to split this slot.');
        }

        $this->pdo->beginTransaction();
        try {
            $createdIds = [];
            $insertEvent = $this->pdo->prepare(
                "INSERT INTO schedule_split_events
                 (schedule_date, period, activity, sort_order, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($lines as $index => $line) {
                $insertEvent->execute([$date, $period, $line, $index, $userId, $userId]);
                $createdIds[] = (int) $this->pdo->lastInsertId();
            }

            $stmt = $this->pdo->prepare(
                "SELECT user_id, status, uncertain, updated_by
                 FROM availability
                 WHERE schedule_date = ? AND period = ?"
            );
            $stmt->execute([$date, $period]);
            $existingAvailability = $stmt->fetchAll();

            $insertAvailability = $this->pdo->prepare(
                "INSERT INTO split_availability
                 (split_event_id, user_id, status, uncertain, updated_by)
                 VALUES (?, ?, ?, ?, ?)"
            );

            foreach ($createdIds as $eventId) {
                foreach ($existingAvailability as $row) {
                    $insertAvailability->execute([
                        $eventId,
                        (int) $row['user_id'],
                        $row['status'],
                        (int) $row['uncertain'],
                        $row['updated_by'] ? (int) $row['updated_by'] : null,
                    ]);
                }
            }

            $this->pdo->commit();
            return $createdIds;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveSplitAvailability(int $eventId, int $userId, string $status, int $updatedBy): void
    {
        if ($status === '') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM split_availability WHERE split_event_id = ? AND user_id = ?"
            );
            $stmt->execute([$eventId, $userId]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO split_availability (split_event_id, user_id, status, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$eventId, $userId, $status, $updatedBy]);
    }

    public function setSplitUncertain(int $eventId, int $userId, bool $uncertain, int $updatedBy): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE split_availability
             SET uncertain = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE split_event_id = ? AND user_id = ? AND status IS NOT NULL"
        );
        $stmt->execute([$uncertain ? 1 : 0, $updatedBy, $eventId, $userId]);
    }

    public function updateSplitEvent(
        int $eventId,
        string $activity,
        int $updatedBy
    ): void {
        $activity = trim($activity);

        if ($activity === '') {
            throw new RuntimeException('Activity cannot be empty.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE schedule_split_events
            SET activity = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            $activity,
            $updatedBy,
            $eventId
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare("
                SELECT id
                FROM schedule_split_events
                WHERE id = ?
                LIMIT 1
            ");

            $check->execute([$eventId]);

            if (!$check->fetch()) {
                throw new RuntimeException('Split event not found.');
            }
        }
    }

    public function addSplitEvent(
        int $eventId,
        string $activity,
        int $updatedBy
    ): int {
        $activity = trim($activity);

        if ($activity === '') {
            throw new RuntimeException('Activity cannot be empty.');
        }

        $stmt = $this->pdo->prepare("
            SELECT
                schedule_date,
                period
            FROM schedule_split_events
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$eventId]);

        $source = $stmt->fetch();

        if (!$source) {
            throw new RuntimeException('Split event not found.');
        }

        $stmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(sort_order), -1)
            FROM schedule_split_events
            WHERE schedule_date = ?
              AND period = ?
        ");

        $stmt->execute([
            $source['schedule_date'],
            $source['period']
        ]);

        $nextSortOrder =
            ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->pdo->prepare("
            INSERT INTO schedule_split_events
            (
                schedule_date,
                period,
                activity,
                sort_order,
                created_by,
                updated_by
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $source['schedule_date'],
            $source['period'],
            $activity,
            $nextSortOrder,
            $updatedBy,
            $updatedBy
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteSplitEvent(
        int $eventId,
        int $updatedBy
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                schedule_date,
                period
            FROM schedule_split_events
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$eventId]);

        $event = $stmt->fetch();

        if (!$event) {
            throw new RuntimeException('Split event not found.');
        }

        $date =
            $event['schedule_date'];

        $period =
            $event['period'];

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                DELETE FROM schedule_split_events
                WHERE id = ?
            ");

            $stmt->execute([$eventId]);

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    activity
                FROM schedule_split_events
                WHERE schedule_date = ?
                  AND period = ?
                ORDER BY sort_order ASC, id ASC
            ");

            $stmt->execute([
                $date,
                $period
            ]);

            $remaining =
                $stmt->fetchAll();

            /*
            |--------------------------------------------------------------------------
            | If only one split event remains, return to a normal slot automatically
            |--------------------------------------------------------------------------
            */

            if (count($remaining) === 1) {

                $remainingEventId =
                    (int) $remaining[0]['id'];

                $activity =
                    $remaining[0]['activity'];

                /*
                | Copy the remaining event's availability back to normal availability.
                */

                $stmt = $this->pdo->prepare("
                    DELETE FROM availability
                    WHERE schedule_date = ?
                      AND period = ?
                ");

                $stmt->execute([
                    $date,
                    $period
                ]);

                $stmt = $this->pdo->prepare("
                    INSERT INTO availability
                    (
                        user_id,
                        schedule_date,
                        period,
                        status,
                        uncertain,
                        updated_by
                    )
                    SELECT
                        user_id,
                        ?,
                        ?,
                        status,
                        uncertain,
                        ?
                    FROM split_availability
                    WHERE split_event_id = ?
                      AND status IS NOT NULL
                ");

                $stmt->execute([
                    $date,
                    $period,
                    $updatedBy,
                    $remainingEventId
                ]);

                $this->saveActivity(
                    $date,
                    $period,
                    $activity,
                    $updatedBy
                );

                $stmt = $this->pdo->prepare("
                    DELETE FROM schedule_split_events
                    WHERE id = ?
                ");

                $stmt->execute([
                    $remainingEventId
                ]);

                $this->pdo->commit();

                return [
                    'merged_to_normal' => true,
                    'remaining' => 0,
                ];
            }

            $this->pdo->commit();

            return [
                'merged_to_normal' => false,
                'remaining' => count($remaining),
            ];

        } catch (Throwable $e) {

            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function splitMergeConflicts(
        string $date,
        string $period
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                sa.user_id,
                COUNT(DISTINCT CONCAT(
                    COALESCE(sa.status, ''),
                    ':',
                    sa.uncertain
                )) AS variant_count
            FROM schedule_split_events se
            INNER JOIN split_availability sa
                ON sa.split_event_id = se.id
            WHERE se.schedule_date = ?
              AND se.period = ?
            GROUP BY sa.user_id
            HAVING variant_count > 1
        ");

        $stmt->execute([
            $date,
            $period
        ]);

        return array_map(
            'intval',
            array_column(
                $stmt->fetchAll(),
                'user_id'
            )
        );
    }

    public function mergeSplitSlot(
        string $date,
        string $period,
        int $updatedBy,
        bool $force = false
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                activity
            FROM schedule_split_events
            WHERE schedule_date = ?
              AND period = ?
            ORDER BY sort_order ASC, id ASC
        ");

        $stmt->execute([
            $date,
            $period
        ]);

        $events =
            $stmt->fetchAll();

        if (count($events) < 2) {
            throw new RuntimeException(
                'This slot is not currently split.'
            );
        }

        $conflicts =
            $this->splitMergeConflicts(
                $date,
                $period
            );

        if (
            !empty($conflicts)
            &&
            !$force
        ) {
            return [
                'success' => false,
                'conflicts' => $conflicts,
            ];
        }

        $activity =
            implode(
                "\n",
                array_column(
                    $events,
                    'activity'
                )
            );

        $eventIds =
            array_map(
                'intval',
                array_column(
                    $events,
                    'id'
                )
            );

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($eventIds),
                    '?'
                )
            );

        $this->pdo->beginTransaction();

        try {

            $stmt = $this->pdo->prepare("
                DELETE FROM availability
                WHERE schedule_date = ?
                  AND period = ?
            ");

            $stmt->execute([
                $date,
                $period
            ]);

            /*
            |--------------------------------------------------------------------------
            | Find all users who have any split availability
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT DISTINCT user_id
                FROM split_availability
                WHERE split_event_id IN ($placeholders)
            ");

            $stmt->execute(
                $eventIds
            );

            $userIds =
                array_map(
                    'intval',
                    array_column(
                        $stmt->fetchAll(),
                        'user_id'
                    )
                );

            foreach ($userIds as $userId) {

                $stmt = $this->pdo->prepare("
                    SELECT
                        status,
                        uncertain
                    FROM split_availability
                    WHERE split_event_id IN ($placeholders)
                      AND user_id = ?
                    ORDER BY split_event_id ASC
                ");

                $stmt->execute([
                    ...$eventIds,
                    $userId
                ]);

                $rows =
                    $stmt->fetchAll();

                if (empty($rows)) {
                    continue;
                }

                $variants = [];

                foreach ($rows as $row) {
                    $variants[] =
                        ($row['status'] ?? '')
                        . ':'
                        . (int) $row['uncertain'];
                }

                $variants =
                    array_values(
                        array_unique(
                            $variants
                        )
                    );

                /*
                | Conflicting answers are intentionally left blank on forced merge.
                */

                if (count($variants) !== 1) {
                    continue;
                }

                $status =
                    $rows[0]['status'] ?? '';

                if (
                    !in_array(
                        $status,
                        [
                            'available',
                            'unavailable'
                        ],
                        true
                    )
                ) {
                    continue;
                }

                $uncertain =
                    (int) $rows[0]['uncertain'];

                $stmt = $this->pdo->prepare("
                    INSERT INTO availability
                    (
                        user_id,
                        schedule_date,
                        period,
                        status,
                        uncertain,
                        updated_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    $date,
                    $period,
                    $status,
                    $uncertain,
                    $updatedBy
                ]);
            }

            $this->saveActivity(
                $date,
                $period,
                $activity,
                $updatedBy
            );

            $stmt = $this->pdo->prepare("
                DELETE FROM schedule_split_events
                WHERE schedule_date = ?
                  AND period = ?
            ");

            $stmt->execute([
                $date,
                $period
            ]);

            $this->pdo->commit();

            return [
                'success' => true,
                'conflicts_cleared' =>
                    count($conflicts),
            ];

        } catch (Throwable $e) {

            $this->pdo->rollBack();

            throw $e;
        }
    }

}
