<?php

class Planning
{
    public function __construct(private PDO $pdo) {}

    public function activities(DateTime $from, DateTime $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        $rows = [];

        // Keep the three source queries separate. This avoids UNION coercion /
        // collation issues on older MySQL 5.7 installations and preserves the
        // exact identity of each source row.
        $stmt = $this->pdo->prepare("
            SELECT
                'calendar' AS source_type,
                ce.id AS source_id,
                ce.schedule_date,
                ce.period,
                ce.summary AS activity_title,
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
        ");
        $stmt->execute([$f, $t]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $this->pdo->prepare("
            SELECT
                'slot' AS source_type,
                ss.id AS source_id,
                ss.schedule_date,
                ss.period,
                ss.activity AS activity_title,
                NULL AS start_local,
                NULL AS end_local,
                ss.piece_id,
                ss.required_basses_override,
                ss.point_value,
                ss.point_type,
                'manual' AS sync_status,
                p.title AS piece_title,
                p.default_basses
            FROM schedule_slots ss
            LEFT JOIN pieces p ON p.id = ss.piece_id
            WHERE ss.schedule_date BETWEEN ? AND ?
        ");
        $stmt->execute([$f, $t]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // A split row linked to a Lydian calendar event is a display/split
        // representation of that same imported event, so do not duplicate it
        // as a second Planning activity.
        $stmt = $this->pdo->prepare("
            SELECT
                'split' AS source_type,
                se.id AS source_id,
                se.schedule_date,
                se.period,
                COALESCE(NULLIF(se.activity_override, ''), se.activity) AS activity_title,
                NULL AS start_local,
                NULL AS end_local,
                se.piece_id,
                se.required_basses_override,
                se.point_value,
                se.point_type,
                'manual' AS sync_status,
                p.title AS piece_title,
                p.default_basses
            FROM schedule_split_events se
            LEFT JOIN pieces p ON p.id = se.piece_id
            WHERE se.calendar_event_id IS NULL
              AND se.schedule_date BETWEEN ? AND ?
        ");
        $stmt->execute([$f, $t]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));

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

            $av = $source === 'split'
                ? ($split[$id] ?? [])
                : ($normal[$r['schedule_date']][$r['period']] ?? []);

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

            // Backwards compatibility with the first Planning page.
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

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $assigned = $this->assignedUserIds($type, $id);

        foreach ($rows as &$r) {
            $r['status'] = !empty($r['status']) ? $r['status'] : 'unanswered';
            $r['uncertain'] = (bool)($r['uncertain'] ?? 0);
            $r['assigned'] = in_array((int)$r['id'], $assigned, true);
        }
        unset($r);

        return ['meta' => $meta, 'members' => $rows];
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
        // Calculate from current real assignments. Keep the SQL deliberately
        // simple for MySQL 5.7 and avoid a derived UNION joined to assignments.
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

        if (!$row) throw new RuntimeException('Activity not found.');
        return $row;
    }

    private function preference(
        string $type,
        int $id,
        int $userId,
        array $meta
    ): array {
        if ($type === 'split') {
            $stmt = $this->pdo->prepare("
                SELECT status, uncertain
                FROM split_availability
                WHERE split_event_id = ? AND user_id = ?
            ");
            $stmt->execute([$id, $userId]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT status, uncertain
                FROM availability
                WHERE schedule_date = ? AND period = ? AND user_id = ?
            ");
            $stmt->execute([$meta['schedule_date'], $meta['period'], $userId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status' => !empty($row['status']) ? $row['status'] : 'unanswered',
            'uncertain' => (int)($row['uncertain'] ?? 0),
        ];
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
}
