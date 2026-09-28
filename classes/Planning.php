<?php

class Planning
{
    public function __construct(private PDO $pdo) {}

    public function activities(DateTime $from, DateTime $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        $rows = [];

        /*
        |--------------------------------------------------------------------------
        | Effective schedule
        |--------------------------------------------------------------------------
        |
        | Planning must manage the same activities that Calendar displays.
        |
        | 1. A manual schedule_slot overrides imported calendar_events for the
        |    same date + period.
        | 2. Explicit split events replace the normal date/period representation.
        | 3. Lydian-linked split rows keep the canonical calendar source identity
        |    so assignments remain attached to the imported event.
        |
        | Nothing is deleted here. Hidden source rows stay in the database.
        |
        */

        $manualSlots = [];
        $splitPeriods = [];

        $stmt = $this->pdo->prepare("
            SELECT
                ss.id,
                ss.schedule_date,
                ss.period,
                ss.activity,
                ss.piece_id,
                ss.required_basses_override,
                ss.point_value,
                ss.point_type,
                p.title AS piece_title,
                p.default_basses
            FROM schedule_slots ss
            LEFT JOIN pieces p ON p.id = ss.piece_id
            WHERE ss.schedule_date BETWEEN ? AND ?
            ORDER BY ss.schedule_date, ss.period, ss.id
        ");
        $stmt->execute([$f, $t]);

        $slotRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($slotRows as $row) {
            $manualSlots[$row['schedule_date']][$row['period']] = true;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                se.id,
                se.calendar_event_id,
                se.schedule_date,
                se.period,
                se.activity,
                se.activity_override,
                se.sort_order,
                se.piece_id,
                se.required_basses_override,
                se.point_value,
                se.point_type,
                p.title AS piece_title,
                p.default_basses
            FROM schedule_split_events se
            LEFT JOIN pieces p ON p.id = se.piece_id
            WHERE se.schedule_date BETWEEN ? AND ?
            ORDER BY se.schedule_date, se.period, se.sort_order, se.id
        ");
        $stmt->execute([$f, $t]);

        $splitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($splitRows as $row) {
            $splitPeriods[$row['schedule_date']][$row['period']] = true;
        }

        /*
        | Split rows are the effective representation when a period is split.
        | If linked to Lydian, use calendar/id as the assignment identity because
        | that is the source already used by Calendar point metadata.
        */
        foreach ($splitRows as $row) {
            $linkedCalendarId = !empty($row['calendar_event_id'])
                ? (int)$row['calendar_event_id']
                : null;

            $pieceId = $row['piece_id'];
            $pieceTitle = $row['piece_title'];
            $defaultBasses = $row['default_basses'];
            $requiredOverride = $row['required_basses_override'];
            $pointValue = $row['point_value'];
            $pointType = $row['point_type'];
            $syncStatus = 'manual';

            if ($linkedCalendarId) {
                $calendar = $this->calendarEvent($linkedCalendarId);

                if ($calendar) {
                    // Piece/staffing metadata follows the canonical Lydian event
                    // unless the split row already has an explicit value.
                    if ($pieceId === null) {
                        $pieceId = $calendar['piece_id'];
                        $pieceTitle = $calendar['piece_title'];
                        $defaultBasses = $calendar['default_basses'];
                    }

                    if ($requiredOverride === null) {
                        $requiredOverride = $calendar['required_basses_override'];
                    }

                    if ((float)$pointValue === 0.0 && $pointType === null) {
                        $pointValue = $calendar['point_value'];
                        $pointType = $calendar['point_type'];
                    }

                    $syncStatus = $calendar['sync_status'];
                }
            }

            $rows[] = [
                'source_type' => $linkedCalendarId ? 'calendar' : 'split',
                'source_id' => $linkedCalendarId ?: (int)$row['id'],
                'split_event_id' => (int)$row['id'],
                'schedule_date' => $row['schedule_date'],
                'period' => $row['period'],
                'activity_title' => trim((string)(
                    $row['activity_override'] !== null && $row['activity_override'] !== ''
                    ? $row['activity_override']
                    : $row['activity']
                )),
                'start_local' => null,
                'end_local' => null,
                'piece_id' => $pieceId,
                'required_basses_override' => $requiredOverride,
                'point_value' => $pointValue,
                'point_type' => $pointType,
                'sync_status' => $syncStatus,
                'piece_title' => $pieceTitle,
                'default_basses' => $defaultBasses,
                'availability_source' => 'split',
                'availability_source_id' => (int)$row['id'],
            ];
        }

        /*
        | Normal manual slots only appear when that date/period is not split.
        */
        foreach ($slotRows as $row) {
            if (!empty($splitPeriods[$row['schedule_date']][$row['period']])) {
                continue;
            }

            $rows[] = [
                'source_type' => 'slot',
                'source_id' => (int)$row['id'],
                'split_event_id' => null,
                'schedule_date' => $row['schedule_date'],
                'period' => $row['period'],
                'activity_title' => $row['activity'],
                'start_local' => null,
                'end_local' => null,
                'piece_id' => $row['piece_id'],
                'required_basses_override' => $row['required_basses_override'],
                'point_value' => $row['point_value'],
                'point_type' => $row['point_type'],
                'sync_status' => 'manual',
                'piece_title' => $row['piece_title'],
                'default_basses' => $row['default_basses'],
                'availability_source' => 'normal',
                'availability_source_id' => null,
            ];
        }

        /*
        | Imported Lydian events appear only if Calendar would also use the
        | imported source for that date/period: no manual override and no split.
        */
        $stmt = $this->pdo->prepare("
            SELECT
                ce.id,
                ce.schedule_date,
                ce.period,
                ce.summary,
                ce.start_local,
                ce.end_local,
                ce.piece_id,
                ce.required_basses_override,
                ce.point_value,
                ce.point_type,
                ce.sync_status,
                p.title AS piece_title,
                p.default_basses
            FROM calendar_events ce
            LEFT JOIN pieces p ON p.id = ce.piece_id
            WHERE ce.schedule_date BETWEEN ? AND ?
            ORDER BY ce.schedule_date, ce.period, ce.start_local, ce.id
        ");
        $stmt->execute([$f, $t]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($manualSlots[$row['schedule_date']][$row['period']])) {
                continue;
            }

            if (!empty($splitPeriods[$row['schedule_date']][$row['period']])) {
                continue;
            }

            $rows[] = [
                'source_type' => 'calendar',
                'source_id' => (int)$row['id'],
                'split_event_id' => null,
                'schedule_date' => $row['schedule_date'],
                'period' => $row['period'],
                'activity_title' => $this->formatCalendarTitle($row),
                'start_local' => $row['start_local'],
                'end_local' => $row['end_local'],
                'piece_id' => $row['piece_id'],
                'required_basses_override' => $row['required_basses_override'],
                'point_value' => $row['point_value'],
                'point_type' => $row['point_type'],
                'sync_status' => $row['sync_status'],
                'piece_title' => $row['piece_title'],
                'default_basses' => $row['default_basses'],
                'availability_source' => 'normal',
                'availability_source_id' => null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $date = strcmp((string)$a['schedule_date'], (string)$b['schedule_date']);
            if ($date !== 0) return $date;

            $pa = $a['period'] === 'morning' ? 0 : 1;
            $pb = $b['period'] === 'morning' ? 0 : 1;
            if ($pa !== $pb) return $pa <=> $pb;

            $sa = (string)($a['start_local'] ?? '');
            $sb = (string)($b['start_local'] ?? '');
            $time = strcmp($sa, $sb);
            if ($time !== 0) return $time;

            return (int)$a['source_id'] <=> (int)$b['source_id'];
        });

        $normal = $this->normalAvailability($from, $to);
        $split = $this->splitAvailability($from, $to);
        $assignments = $this->assignments();

        foreach ($rows as &$r) {
            $source = (string)$r['source_type'];
            $id = (int)$r['source_id'];

            if ($r['availability_source'] === 'split') {
                $av = $split[(int)$r['availability_source_id']] ?? [];
            } else {
                $av = $normal[$r['schedule_date']][$r['period']] ?? [];
            }

            $available = 0;
            $unavailable = 0;

            foreach ($av as $v) {
                if (($v['status'] ?? '') === 'available') $available++;
                if (($v['status'] ?? '') === 'unavailable') $unavailable++;
            }

            $r['available_count'] = $available;
            $r['unavailable_count'] = $unavailable;
            $r['assigned_user_ids'] = $assignments[$source][$id] ?? [];
            $r['assigned_count'] = count($r['assigned_user_ids']);
            $r['default_basses'] = $r['default_basses'] !== null
                ? (int)$r['default_basses']
                : null;
            $r['effective_required'] = $r['required_basses_override'] !== null
                ? (int)$r['required_basses_override']
                : $r['default_basses'];

            // Existing planning.php uses title.
            $r['title'] = $r['activity_title'];
        }
        unset($r);

        return $rows;
    }

    public function activePieces(): array
    {
        return $this->pdo->query("
            SELECT id, title, default_basses, type
            FROM pieces
            WHERE status = 1
            ORDER BY title
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeUsers(): array
    {
        return $this->pdo->query("
            SELECT id, name, position, multiplier
            FROM users
            WHERE status = 1
            ORDER BY sort_order, name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function availabilityForActivity(string $type, int $id): array
    {
        $meta = $this->activityMeta($type, $id);

        /*
        | A canonical calendar event may currently be represented by a linked
        | split event. In that case Planning must show split availability.
        */
        if ($type === 'calendar') {
            $splitId = $this->linkedSplitId($id);

            if ($splitId !== null) {
                $stmt = $this->pdo->prepare("
                    SELECT u.id, u.name, sa.status, sa.uncertain
                    FROM users u
                    LEFT JOIN split_availability sa
                      ON sa.user_id = u.id
                     AND sa.split_event_id = ?
                    WHERE u.status = 1
                    ORDER BY u.sort_order, u.name
                ");
                $stmt->execute([$splitId]);

                return [
                    'meta' => $meta,
                    'members' => $this->decorateMembers(
                        $stmt->fetchAll(PDO::FETCH_ASSOC),
                        $type,
                        $id
                    ),
                ];
            }
        }

        if ($type === 'split') {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.name, sa.status, sa.uncertain
                FROM users u
                LEFT JOIN split_availability sa
                  ON sa.user_id = u.id
                 AND sa.split_event_id = ?
                WHERE u.status = 1
                ORDER BY u.sort_order, u.name
            ");
            $stmt->execute([$id]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.name, a.status, a.uncertain
                FROM users u
                LEFT JOIN availability a
                  ON a.user_id = u.id
                 AND a.schedule_date = ?
                 AND a.period = ?
                WHERE u.status = 1
                ORDER BY u.sort_order, u.name
            ");
            $stmt->execute([$meta['schedule_date'], $meta['period']]);
        }

        return [
            'meta' => $meta,
            'members' => $this->decorateMembers(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $type,
                $id
            ),
        ];
    }

    public function setAssignment(
        string $type,
        int $id,
        int $userId,
        bool $assigned,
        int $changedBy
    ): void {
        $meta = $this->activityMeta($type, $id);
        $pref = $this->preference($type, $id, $userId, $meta);

        $this->pdo->beginTransaction();

        try {
            if ($assigned) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO activity_assignments
                    (
                        source_type, source_id, user_id, assigned_by,
                        preference_at_assignment, uncertain_at_assignment
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        assigned_by = VALUES(assigned_by),
                        preference_at_assignment = VALUES(preference_at_assignment),
                        uncertain_at_assignment = VALUES(uncertain_at_assignment),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $type,
                    $id,
                    $userId,
                    $changedBy,
                    $pref['status'],
                    $pref['uncertain']
                ]);
            } else {
                $stmt = $this->pdo->prepare("
                    DELETE FROM activity_assignments
                    WHERE source_type = ? AND source_id = ? AND user_id = ?
                ");
                $stmt->execute([$type, $id, $userId]);
            }

            $dt = new DateTime($meta['schedule_date']);
            $weekday = (int)$dt->format('N');

            $stmt = $this->pdo->prepare("
                INSERT INTO assignment_history
                (
                    source_type, source_id, user_id, action,
                    preference_snapshot, uncertain_snapshot,
                    schedule_date, period,
                    is_friday_evening, is_saturday, is_sunday,
                    changed_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type,
                $id,
                $userId,
                $assigned ? 'assigned' : 'unassigned',
                $pref['status'],
                $pref['uncertain'],
                $meta['schedule_date'],
                $meta['period'],
                ($weekday === 5 && $meta['period'] === 'evening') ? 1 : 0,
                $weekday === 6 ? 1 : 0,
                $weekday === 7 ? 1 : 0,
                $changedBy
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function statistics(DateTime $from, DateTime $to): array
    {
        $users = $this->pdo->query("
            SELECT id, name, position, multiplier, sort_order
            FROM users
            WHERE status = 1
            ORDER BY sort_order, name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($users as $user) {
            $result[(int)$user['id']] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'position' => $user['position'],
                'multiplier' => $user['multiplier'],
                'total_assigned' => 0,
                'friday_evenings' => 0,
                'saturdays' => 0,
                'sundays' => 0,
                'forced_calls' => 0,
            ];
        }

        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        $sourceQueries = [
            'calendar' => "
                SELECT aa.user_id, aa.preference_at_assignment,
                       ce.schedule_date, ce.period
                FROM activity_assignments aa
                INNER JOIN calendar_events ce ON ce.id = aa.source_id
                WHERE aa.source_type = 'calendar'
                  AND ce.schedule_date BETWEEN ? AND ?
            ",
            'slot' => "
                SELECT aa.user_id, aa.preference_at_assignment,
                       ss.schedule_date, ss.period
                FROM activity_assignments aa
                INNER JOIN schedule_slots ss ON ss.id = aa.source_id
                WHERE aa.source_type = 'slot'
                  AND ss.schedule_date BETWEEN ? AND ?
            ",
            'split' => "
                SELECT aa.user_id, aa.preference_at_assignment,
                       se.schedule_date, se.period
                FROM activity_assignments aa
                INNER JOIN schedule_split_events se ON se.id = aa.source_id
                WHERE aa.source_type = 'split'
                  AND se.schedule_date BETWEEN ? AND ?
            ",
        ];

        foreach ($sourceQueries as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($result[$uid])) continue;

                $date = new DateTime($row['schedule_date']);
                $weekday = (int)$date->format('N');

                $result[$uid]['total_assigned']++;
                if ($weekday === 5 && $row['period'] === 'evening') {
                    $result[$uid]['friday_evenings']++;
                }
                if ($weekday === 6) $result[$uid]['saturdays']++;
                if ($weekday === 7) $result[$uid]['sundays']++;
                if ($row['preference_at_assignment'] === 'unavailable') {
                    $result[$uid]['forced_calls']++;
                }
            }
        }

        return array_values($result);
    }

    public function favourBalances(): array
    {
        return $this->pdo->query("
            SELECT
                f.name AS from_name,
                t.name AS to_name,
                SUM(CASE WHEN s.counts_as_favour = 1 THEN 1 ELSE 0 END) AS favour_count
            FROM assignment_swaps s
            INNER JOIN users f ON f.id = s.from_user_id
            INNER JOIN users t ON t.id = s.to_user_id
            GROUP BY s.from_user_id, s.to_user_id, f.name, t.name
            ORDER BY f.name, t.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function activityMeta(string $type, int $id): array
    {
        $tables = [
            'calendar' => 'calendar_events',
            'slot' => 'schedule_slots',
            'split' => 'schedule_split_events',
        ];

        if (!isset($tables[$type]) || $id <= 0) {
            throw new RuntimeException('Invalid activity.');
        }

        $table = $tables[$type];
        $stmt = $this->pdo->prepare("
            SELECT id, schedule_date, period
            FROM {$table}
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Activity not found.');
        }

        return $row;
    }

    private function preference(
        string $type,
        int $id,
        int $userId,
        array $meta
    ): array {
        if ($type === 'calendar') {
            $splitId = $this->linkedSplitId($id);

            if ($splitId !== null) {
                return $this->splitPreference($splitId, $userId);
            }
        }

        if ($type === 'split') {
            return $this->splitPreference($id, $userId);
        }

        $stmt = $this->pdo->prepare("
            SELECT status, uncertain
            FROM availability
            WHERE schedule_date = ? AND period = ? AND user_id = ?
        ");
        $stmt->execute([$meta['schedule_date'], $meta['period'], $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status' => !empty($row['status']) ? $row['status'] : 'unanswered',
            'uncertain' => (int)($row['uncertain'] ?? 0),
        ];
    }

    private function splitPreference(int $splitId, int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT status, uncertain
            FROM split_availability
            WHERE split_event_id = ? AND user_id = ?
        ");
        $stmt->execute([$splitId, $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status' => !empty($row['status']) ? $row['status'] : 'unanswered',
            'uncertain' => (int)($row['uncertain'] ?? 0),
        ];
    }

    private function linkedSplitId(int $calendarEventId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM schedule_split_events
            WHERE calendar_event_id = ?
            ORDER BY sort_order, id
            LIMIT 1
        ");
        $stmt->execute([$calendarEventId]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function decorateMembers(array $rows, string $type, int $id): array
    {
        $assigned = $this->assignedUserIds($type, $id);

        foreach ($rows as &$r) {
            $r['status'] = !empty($r['status']) ? $r['status'] : 'unanswered';
            $r['uncertain'] = (bool)($r['uncertain'] ?? 0);
            $r['assigned'] = in_array((int)$r['id'], $assigned, true);
        }
        unset($r);

        return $rows;
    }

    private function assignedUserIds(string $type, int $id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT user_id
            FROM activity_assignments
            WHERE source_type = ? AND source_id = ?
        ");
        $stmt->execute([$type, $id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assignments(): array
    {
        $result = [];
        $stmt = $this->pdo->query("
            SELECT source_type, source_id, user_id
            FROM activity_assignments
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['source_type']][(int)$row['source_id']][] =
                (int)$row['user_id'];
        }

        return $result;
    }

    private function normalAvailability(DateTime $from, DateTime $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT user_id, schedule_date, period, status, uncertain
            FROM availability
            WHERE schedule_date BETWEEN ? AND ?
        ");
        $stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['schedule_date']][$row['period']][(int)$row['user_id']] = $row;
        }

        return $result;
    }

    private function splitAvailability(DateTime $from, DateTime $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT sa.split_event_id, sa.user_id, sa.status, sa.uncertain
            FROM split_availability sa
            INNER JOIN schedule_split_events se ON se.id = sa.split_event_id
            WHERE se.schedule_date BETWEEN ? AND ?
        ");
        $stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['split_event_id']][(int)$row['user_id']] = $row;
        }

        return $result;
    }

    private function calendarEvent(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ce.id,
                ce.piece_id,
                ce.required_basses_override,
                ce.point_value,
                ce.point_type,
                ce.sync_status,
                p.title AS piece_title,
                p.default_basses
            FROM calendar_events ce
            LEFT JOIN pieces p ON p.id = ce.piece_id
            WHERE ce.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function formatCalendarTitle(array $row): string
    {
        $time = '';

        if (!empty($row['start_local']) && !empty($row['end_local'])) {
            $start = substr((string)$row['start_local'], 11, 5);
            $end = substr((string)$row['end_local'], 11, 5);

            if ($start !== '' && $end !== '') {
                $time = $start . '–' . $end . ' ';
            }
        }

        return trim($time . (string)$row['summary']);
    }
}
