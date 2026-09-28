<?php

class Planning
{
    public function __construct(private PDO $pdo) {}

    public function activities(DateTime $from, DateTime $to): array
    {
        $sql = "
        SELECT * FROM (
            SELECT
                'calendar' source_type, ce.id source_id, ce.schedule_date, ce.period,
                ce.summary title, ce.start_local, ce.end_local,
                ce.piece_id, ce.required_basses_override,
                ce.point_value, ce.point_type, ce.sync_status
            FROM calendar_events ce
            WHERE ce.schedule_date BETWEEN ? AND ?

            UNION ALL

            SELECT
                'slot' source_type, ss.id source_id, ss.schedule_date, ss.period,
                ss.activity title, NULL start_local, NULL end_local,
                ss.piece_id, ss.required_basses_override,
                ss.point_value, ss.point_type, 'manual' sync_status
            FROM schedule_slots ss
            WHERE ss.schedule_date BETWEEN ? AND ?

            UNION ALL

            SELECT
                'split' source_type, se.id source_id, se.schedule_date, se.period,
                COALESCE(NULLIF(se.activity_override,''), se.activity) title,
                NULL start_local, NULL end_local,
                se.piece_id, se.required_basses_override,
                se.point_value, se.point_type, 'manual' sync_status
            FROM schedule_split_events se
            WHERE se.calendar_event_id IS NULL
              AND se.schedule_date BETWEEN ? AND ?
        ) a
        LEFT JOIN pieces p ON p.id = a.piece_id
        ORDER BY a.schedule_date, FIELD(a.period,'morning','evening'), a.start_local, a.source_id";

        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$f, $t, $f, $t, $f, $t]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $normal = $this->normalAvailability($from, $to);
        $split = $this->splitAvailability($from, $to);
        $assignments = $this->assignments($from, $to);

        foreach ($rows as &$r) {
            $source = $r['source_type'];
            $id = (int)$r['source_id'];
            $av = $source === 'split'
                ? ($split[$id] ?? [])
                : ($normal[$r['schedule_date']][$r['period']] ?? []);
            $counts = ['available' => 0, 'unavailable' => 0, 'unanswered' => 0];
            foreach ($av as $v) {
                $s = $v['status'] ?: 'unanswered';
                if (isset($counts[$s])) $counts[$s]++;
            }
            $r['available_count'] = $counts['available'];
            $r['unavailable_count'] = $counts['unavailable'];
            $r['assigned_user_ids'] = $assignments[$source][$id] ?? [];
            $r['assigned_count'] = count($r['assigned_user_ids']);
            $r['default_basses'] = $r['default_basses'] !== null ? (int)$r['default_basses'] : null;
            $r['effective_required'] = $r['required_basses_override'] !== null
                ? (int)$r['required_basses_override']
                : ($r['default_basses'] !== null ? (int)$r['default_basses'] : null);
        }
        unset($r);
        return $rows;
    }

    public function activePieces(): array
    {
        return $this->pdo->query(
            "SELECT id,title,default_basses,type FROM pieces WHERE status=1 ORDER BY title"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeUsers(): array
    {
        return $this->pdo->query(
            "SELECT id,name,position,multiplier FROM users WHERE status=1 ORDER BY sort_order,name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function availabilityForActivity(string $type, int $id): array
    {
        $meta = $this->activityMeta($type, $id);
        if ($type === 'split') {
            $stmt = $this->pdo->prepare(
                "SELECT u.id,u.name,sa.status,sa.uncertain
                 FROM users u
                 LEFT JOIN split_availability sa
                   ON sa.user_id=u.id AND sa.split_event_id=?
                 WHERE u.status=1 ORDER BY u.sort_order,u.name"
            );
            $stmt->execute([$id]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT u.id,u.name,a.status,a.uncertain
                 FROM users u
                 LEFT JOIN availability a
                   ON a.user_id=u.id AND a.schedule_date=? AND a.period=?
                 WHERE u.status=1 ORDER BY u.sort_order,u.name"
            );
            $stmt->execute([$meta['schedule_date'], $meta['period']]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $assigned = $this->assignedUserIds($type, $id);
        foreach ($rows as &$r) {
            $r['status'] = $r['status'] ?: 'unanswered';
            $r['uncertain'] = (bool)($r['uncertain'] ?? 0);
            $r['assigned'] = in_array((int)$r['id'], $assigned, true);
        }
        unset($r);
        return ['meta' => $meta, 'members' => $rows];
    }

    public function setAssignment(string $type, int $id, int $userId, bool $assigned, int $changedBy): void
    {
        $meta = $this->activityMeta($type, $id);
        $pref = $this->preference($type, $id, $userId, $meta);
        $this->pdo->beginTransaction();
        try {
            if ($assigned) {
                $s = $this->pdo->prepare(
                    "INSERT INTO activity_assignments
                    (source_type,source_id,user_id,assigned_by,preference_at_assignment,uncertain_at_assignment)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by),
                    preference_at_assignment=VALUES(preference_at_assignment),
                    uncertain_at_assignment=VALUES(uncertain_at_assignment),
                    updated_at=CURRENT_TIMESTAMP"
                );
                $s->execute([$type, $id, $userId, $changedBy, $pref['status'], $pref['uncertain']]);
            } else {
                $s = $this->pdo->prepare(
                    "DELETE FROM activity_assignments WHERE source_type=? AND source_id=? AND user_id=?"
                );
                $s->execute([$type, $id, $userId]);
            }
            $dt = new DateTime($meta['schedule_date']);
            $h = $this->pdo->prepare(
                "INSERT INTO assignment_history
                (source_type,source_id,user_id,action,preference_snapshot,uncertain_snapshot,
                 schedule_date,period,is_friday_evening,is_saturday,is_sunday,changed_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $h->execute([
                $type,
                $id,
                $userId,
                $assigned ? 'assigned' : 'unassigned',
                $pref['status'],
                $pref['uncertain'],
                $meta['schedule_date'],
                $meta['period'],
                ((int)$dt->format('N') === 5 && $meta['period'] === 'evening') ? 1 : 0,
                (int)$dt->format('N') === 6 ? 1 : 0,
                (int)$dt->format('N') === 7 ? 1 : 0,
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
        $stmt = $this->pdo->prepare(
            "SELECT u.id,u.name,u.position,u.multiplier,
                COUNT(aa.id) total_assigned,
                SUM(CASE WHEN DAYOFWEEK(m.schedule_date)=7 THEN 1 ELSE 0 END) saturdays,
                SUM(CASE WHEN DAYOFWEEK(m.schedule_date)=1 THEN 1 ELSE 0 END) sundays,
                SUM(CASE WHEN DAYOFWEEK(m.schedule_date)=6 AND m.period='evening' THEN 1 ELSE 0 END) friday_evenings,
                SUM(CASE WHEN aa.preference_at_assignment='unavailable' THEN 1 ELSE 0 END) forced_calls
             FROM users u
             LEFT JOIN activity_assignments aa ON aa.user_id=u.id
             LEFT JOIN (
                SELECT 'calendar' source_type,id source_id,schedule_date,period FROM calendar_events
                UNION ALL SELECT 'slot',id,schedule_date,period FROM schedule_slots
                UNION ALL SELECT 'split',id,schedule_date,period FROM schedule_split_events WHERE calendar_event_id IS NULL
             ) m ON m.source_type=aa.source_type AND m.source_id=aa.source_id
                AND m.schedule_date BETWEEN ? AND ?
             WHERE u.status=1
             GROUP BY u.id,u.name,u.position,u.multiplier
             ORDER BY u.sort_order,u.name"
        );
        $stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function favourBalances(): array
    {
        return $this->pdo->query(
            "SELECT f.name from_name,t.name to_name,
                    SUM(CASE WHEN s.counts_as_favour=1 THEN 1 ELSE 0 END) favour_count
             FROM assignment_swaps s
             JOIN users f ON f.id=s.from_user_id
             JOIN users t ON t.id=s.to_user_id
             GROUP BY s.from_user_id,s.to_user_id,f.name,t.name
             ORDER BY f.name,t.name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function activityMeta(string $type, int $id): array
    {
        $map = ['calendar' => 'calendar_events', 'slot' => 'schedule_slots', 'split' => 'schedule_split_events'];
        if (!isset($map[$type]) || $id <= 0) throw new RuntimeException('Invalid activity.');
        $s = $this->pdo->prepare("SELECT id,schedule_date,period FROM {$map[$type]} WHERE id=? LIMIT 1");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('Activity not found.');
        return $r;
    }

    private function preference(string $type, int $id, int $userId, array $meta): array
    {
        if ($type === 'split') {
            $s = $this->pdo->prepare("SELECT status,uncertain FROM split_availability WHERE split_event_id=? AND user_id=?");
            $s->execute([$id, $userId]);
        } else {
            $s = $this->pdo->prepare("SELECT status,uncertain FROM availability WHERE schedule_date=? AND period=? AND user_id=?");
            $s->execute([$meta['schedule_date'], $meta['period'], $userId]);
        }
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['status' => $r['status'] ?: 'unanswered', 'uncertain' => (int)($r['uncertain'] ?? 0)];
    }

    private function assignedUserIds(string $type, int $id): array
    {
        $s = $this->pdo->prepare("SELECT user_id FROM activity_assignments WHERE source_type=? AND source_id=?");
        $s->execute([$type, $id]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assignments(DateTime $from, DateTime $to): array
    {
        $r = [];
        foreach ($this->pdo->query("SELECT source_type,source_id,user_id FROM activity_assignments")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $r[$x['source_type']][(int)$x['source_id']][] = (int)$x['user_id'];
        }
        return $r;
    }

    private function normalAvailability(DateTime $from, DateTime $to): array
    {
        $s = $this->pdo->prepare("SELECT user_id,schedule_date,period,status,uncertain FROM availability WHERE schedule_date BETWEEN ? AND ?");
        $s->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $r = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) $r[$x['schedule_date']][$x['period']][(int)$x['user_id']] = $x;
        return $r;
    }

    private function splitAvailability(DateTime $from, DateTime $to): array
    {
        $s = $this->pdo->prepare("SELECT sa.split_event_id,sa.user_id,sa.status,sa.uncertain FROM split_availability sa JOIN schedule_split_events se ON se.id=sa.split_event_id WHERE se.schedule_date BETWEEN ? AND ?");
        $s->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $r = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) $r[(int)$x['split_event_id']][(int)$x['user_id']] = $x;
        return $r;
    }
}
