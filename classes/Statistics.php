<?php
final class Statistics
{
    public function __construct(private PDO $pdo) {}

    public function report(DateTime $from, DateTime $to): array
    {
        $users = $this->users();
        $by = [];
        $dayCounts = [];
        foreach ($users as $u) {
            $id = (int)$u['id'];
            $by[$id] = [
                'id' => $id,
                'name' => $u['name'],
                'position' => $u['position'],
                'multiplier' => (float)($u['multiplier'] ?? 1),
                'calls' => 0,
                'rehearsal_calls' => 0,
                'performance_calls' => 0,
                'untyped_calls' => 0,
                'raw_points' => 0.0,
                'weighted_points' => 0.0,
                'rehearsal_points' => 0.0,
                'performance_points' => 0.0,
                'against_preference' => 0,
                'uncertain_calls' => 0,
                'unanswered_calls' => 0,
                'available_calls' => 0,
                'weekday_periods' => $this->emptyWeekdayPeriods(),
                'double_days' => 0,
                'recent_7' => 0,
                'recent_14' => 0,
                'recent_28' => 0
            ];
        }
        $rows = $this->assignmentRows($from, $to);
        $today = new DateTime('today');
        foreach ($rows as &$r) {
            $id = (int)$r['user_id'];
            if (!isset($by[$id])) continue;
            $day = (int)(new DateTime($r['schedule_date']))->format('N');
            $period = in_array($r['period'], ['morning', 'evening'], true) ? $r['period'] : 'morning';
            $p = (float)($r['point_value'] ?? 0);
            $m = (float)$by[$id]['multiplier'];
            if ($m <= 0) $m = 1;
            $wp = $p * $m;
            $r['weighted_points'] = $wp;
            $by[$id]['calls']++;
            $by[$id]['weekday_periods'][$day][$period]++;
            $dayCounts[$id][$r['schedule_date']] = ($dayCounts[$id][$r['schedule_date']] ?? 0) + 1;
            if ($r['point_type'] === 'rehearsal') {
                $by[$id]['rehearsal_calls']++;
                $by[$id]['rehearsal_points'] += $wp;
            } elseif ($r['point_type'] === 'performance') {
                $by[$id]['performance_calls']++;
                $by[$id]['performance_points'] += $wp;
            } else $by[$id]['untyped_calls']++;
            $by[$id]['raw_points'] += $p;
            $by[$id]['weighted_points'] += $wp;
            $pref = (string)($r['preference_at_assignment'] ?? 'unanswered');
            if ($pref === 'unavailable') $by[$id]['against_preference']++;
            elseif ($pref === 'available') $by[$id]['available_calls']++;
            else $by[$id]['unanswered_calls']++;
            if ((int)($r['uncertain_at_assignment'] ?? 0) === 1) $by[$id]['uncertain_calls']++;
            $age = (int)(new DateTime($r['schedule_date']))->diff($today)->format('%r%a');
            if ($age >= 0 && $age < 7) $by[$id]['recent_7']++;
            if ($age >= 0 && $age < 14) $by[$id]['recent_14']++;
            if ($age >= 0 && $age < 28) $by[$id]['recent_28']++;
        }
        unset($r);
        foreach ($dayCounts as $id => $dates) foreach ($dates as $n) if ($n >= 2) $by[$id]['double_days']++;
        $tot = ['calls' => 0, 'rehearsal_calls' => 0, 'performance_calls' => 0, 'untyped_calls' => 0, 'raw_points' => 0.0, 'weighted_points' => 0.0, 'rehearsal_points' => 0.0, 'performance_points' => 0.0, 'against_preference' => 0, 'uncertain_calls' => 0, 'unanswered_calls' => 0, 'available_calls' => 0, 'weekday_periods' => $this->emptyWeekdayPeriods()];
        foreach ($by as $u) {
            foreach (['calls', 'rehearsal_calls', 'performance_calls', 'untyped_calls', 'against_preference', 'uncertain_calls', 'unanswered_calls', 'available_calls'] as $k) $tot[$k] += $u[$k];
            foreach (['raw_points', 'weighted_points', 'rehearsal_points', 'performance_points'] as $k) $tot[$k] += $u[$k];
            for ($d = 1; $d <= 7; $d++) foreach (['morning', 'evening'] as $p) $tot['weekday_periods'][$d][$p] += $u['weekday_periods'][$d][$p];
        }
        return ['users' => array_values($by), 'totals' => $tot, 'assignments' => $rows];
    }

    public function repertoire(DateTime $from, DateTime $to): array
    {
        $pieces = [];
        foreach ($this->assignmentRows($from, $to) as $r) {
            $title = trim((string)($r['piece_title'] ?? ''));
            if ($title === '') $title = trim((string)($r['activity_title'] ?? 'Unassigned repertoire'));
            $key = (string)($r['piece_id'] ?? '') . '|' . $title;
            $id = (int)$r['user_id'];
            if (!isset($pieces[$key])) $pieces[$key] = ['title' => $title, 'users' => [], 'rehearsals' => 0, 'performances' => 0, 'calls' => 0, 'last_played' => null];
            if (!isset($pieces[$key]['users'][$id])) $pieces[$key]['users'][$id] = ['name' => $r['user_name'], 'rehearsals' => 0, 'performances' => 0, 'calls' => 0, 'last_played' => null];
            $pieces[$key]['calls']++;
            $pieces[$key]['users'][$id]['calls']++;
            if ($r['point_type'] === 'rehearsal') {
                $pieces[$key]['rehearsals']++;
                $pieces[$key]['users'][$id]['rehearsals']++;
            }
            if ($r['point_type'] === 'performance') {
                $pieces[$key]['performances']++;
                $pieces[$key]['users'][$id]['performances']++;
            }
            if (!$pieces[$key]['last_played'] || $r['schedule_date'] > $pieces[$key]['last_played']) $pieces[$key]['last_played'] = $r['schedule_date'];
            if (!$pieces[$key]['users'][$id]['last_played'] || $r['schedule_date'] > $pieces[$key]['users'][$id]['last_played']) $pieces[$key]['users'][$id]['last_played'] = $r['schedule_date'];
        }
        usort($pieces, fn($a, $b) => strcasecmp($a['title'], $b['title']));
        return array_values($pieces);
    }

    public function weeklyBalances(DateTime $from, DateTime $to): array
    {
        $users = $this->users();
        $start = clone $from;
        if ((int)$start->format('N') !== 1) $start->modify('monday this week');
        $end = clone $to;
        if ((int)$end->format('N') !== 1) $end->modify('monday this week');
        $s = $this->pdo->prepare("SELECT week_start,user_id,point_type,opening_points FROM weekly_point_balances WHERE week_start BETWEEN ? AND ?");
        $s->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $data = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $data[$r['week_start']][$r['point_type']][(int)$r['user_id']] = (float)$r['opening_points'];
        $out = [];
        for ($d = clone $start; $d <= $end; $d->modify('+7 days')) {
            $wk = $d->format('Y-m-d');
            $row = ['week_start' => $wk, 'rehearsal' => [], 'performance' => []];
            foreach (['rehearsal', 'performance'] as $type) {
                $vals = [];
                foreach ($users as $u) {
                    $id = (int)$u['id'];
                    if (isset($data[$wk][$type][$id])) $vals[$id] = $data[$wk][$type][$id];
                }
                $avg = $vals ? array_sum($vals) / count($vals) : null;
                foreach ($users as $u) {
                    $id = (int)$u['id'];
                    $v = $vals[$id] ?? null;
                    $row[$type][] = ['name' => $u['name'], 'points' => $v, 'average' => $avg, 'difference' => $v !== null && $avg !== null ? $v - $avg : null];
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    private function users(): array
    {
        return $this->pdo->query("SELECT id,name,position,multiplier,sort_order FROM users WHERE status=1 ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
    }
    private function assignmentRows(DateTime $from, DateTime $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        $rows = [];
        $q = [
            "SELECT aa.source_type,aa.source_id,aa.user_id,u.name user_name,aa.preference_at_assignment,aa.uncertain_at_assignment,ce.schedule_date,ce.period,ce.point_value,ce.point_type,ce.piece_id,COALESCE(p.title,ce.summary) piece_title,ce.summary activity_title FROM activity_assignments aa JOIN calendar_events ce ON ce.id=aa.source_id JOIN users u ON u.id=aa.user_id LEFT JOIN pieces p ON p.id=ce.piece_id WHERE aa.source_type='calendar' AND ce.schedule_date BETWEEN ? AND ?",
            "SELECT aa.source_type,aa.source_id,aa.user_id,u.name user_name,aa.preference_at_assignment,aa.uncertain_at_assignment,ss.schedule_date,ss.period,ss.point_value,ss.point_type,ss.piece_id,COALESCE(p.title,ss.activity) piece_title,ss.activity activity_title FROM activity_assignments aa JOIN schedule_slots ss ON ss.id=aa.source_id JOIN users u ON u.id=aa.user_id LEFT JOIN pieces p ON p.id=ss.piece_id WHERE aa.source_type='slot' AND ss.schedule_date BETWEEN ? AND ?",
            "SELECT aa.source_type,aa.source_id,aa.user_id,u.name user_name,aa.preference_at_assignment,aa.uncertain_at_assignment,se.schedule_date,se.period,COALESCE(se.point_value,ce.point_value,0) point_value,COALESCE(se.point_type,ce.point_type) point_type,COALESCE(se.piece_id,ce.piece_id) piece_id,COALESCE(p.title,se.activity,ce.summary) piece_title,COALESCE(se.activity,ce.summary) activity_title FROM activity_assignments aa JOIN schedule_split_events se ON se.id=aa.source_id JOIN users u ON u.id=aa.user_id LEFT JOIN calendar_events ce ON ce.id=se.calendar_event_id LEFT JOIN pieces p ON p.id=COALESCE(se.piece_id,ce.piece_id) WHERE aa.source_type='split' AND se.schedule_date BETWEEN ? AND ?"
        ];
        foreach ($q as $sql) {
            $s = $this->pdo->prepare($sql);
            $s->execute([$f, $t]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
        }
        return $rows;
    }
    private function emptyWeekdayPeriods(): array
    {
        $r = [];
        for ($d = 1; $d <= 7; $d++) $r[$d] = ['morning' => 0, 'evening' => 0];
        return $r;
    }
}
