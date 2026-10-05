<?php

final class Statistics
{
    public function __construct(private PDO $pdo) {}

    public function report(DateTime $from, DateTime $to, array $effectiveActivities): array
    {
        $users = $this->users();
        $rows = $this->effectiveAssignmentRows($effectiveActivities);
        $by = [];
        $dayCounts = [];
        $today = new DateTime('today');

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
                'rehearsal_points' => 0.0,
                'performance_points' => 0.0,
                'weighted_points' => 0.0,
                'against_preference' => 0,
                'available_calls' => 0,
                'unanswered_calls' => 0,
                'uncertain_calls' => 0,
                'weekday_periods' => $this->emptyWeekdayPeriods(),
                'double_days' => 0,
                'recent_7' => 0,
                'recent_14' => 0,
                'recent_28' => 0,
            ];
        }

        foreach ($rows as $r) {
            $id = (int)$r['user_id'];
            if (!isset($by[$id])) continue;
            $date = new DateTime($r['schedule_date']);
            $weekday = (int)$date->format('N');
            $period = in_array($r['period'], ['morning', 'evening'], true) ? $r['period'] : 'morning';
            $points = (float)$r['point_value'];
            $mult = (float)$by[$id]['multiplier'];
            if ($mult <= 0) $mult = 1;
            $weighted = $points * $mult;

            $by[$id]['calls']++;
            $by[$id]['weekday_periods'][$weekday][$period]++;
            $dayCounts[$id][$r['schedule_date']] = ($dayCounts[$id][$r['schedule_date']] ?? 0) + 1;

            if ($r['point_type'] === 'rehearsal') {
                $by[$id]['rehearsal_calls']++;
                $by[$id]['rehearsal_points'] += $weighted;
            } elseif ($r['point_type'] === 'performance') {
                $by[$id]['performance_calls']++;
                $by[$id]['performance_points'] += $weighted;
            } else {
                $by[$id]['untyped_calls']++;
            }
            $by[$id]['weighted_points'] += $weighted;

            $pref = (string)($r['preference_at_assignment'] ?? 'unanswered');
            if ($pref === 'unavailable') $by[$id]['against_preference']++;
            elseif ($pref === 'available') $by[$id]['available_calls']++;
            else $by[$id]['unanswered_calls']++;
            if ((int)($r['uncertain_at_assignment'] ?? 0) === 1) $by[$id]['uncertain_calls']++;

            $age = (int)$date->diff($today)->format('%r%a');
            if ($age >= 0 && $age < 7) $by[$id]['recent_7']++;
            if ($age >= 0 && $age < 14) $by[$id]['recent_14']++;
            if ($age >= 0 && $age < 28) $by[$id]['recent_28']++;
        }

        foreach ($dayCounts as $id => $dates) {
            foreach ($dates as $n) if ($n >= 2) $by[$id]['double_days']++;
        }

        return ['users' => array_values($by), 'assignments' => $rows];
    }

    public function currentBalance(DateTime $asOf, array $effectiveActivities): array
    {
        $users = $this->users();
        $weekStart = clone $asOf;
        if ((int)$weekStart->format('N') !== 1) $weekStart->modify('monday this week');
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');

        $balances = $this->balanceRows($weekStart, $weekStart);
        $potential = $this->weeklyAssignedPoints($effectiveActivities);
        $result = ['week_start' => $weekStart->format('Y-m-d'), 'week_end' => $weekEnd->format('Y-m-d'), 'rehearsal' => [], 'performance' => []];

        foreach (['rehearsal', 'performance'] as $type) {
            $projected = [];
            foreach ($users as $u) {
                $id = (int)$u['id'];
                $opening = $balances[$result['week_start']][$type][$id] ?? null;
                if ($opening !== null) $projected[$id] = $opening + (float)($potential[$result['week_start']][$type][$id] ?? 0);
            }
            $avg = $projected ? array_sum($projected) / count($projected) : null;
            foreach ($users as $u) {
                $id = (int)$u['id'];
                $opening = $balances[$result['week_start']][$type][$id] ?? null;
                $earned = (float)($potential[$result['week_start']][$type][$id] ?? 0);
                $end = $opening !== null ? $opening + $earned : null;
                $result[$type][] = [
                    'user_id' => $id,
                    'name' => $u['name'],
                    'opening' => $opening,
                    'assigned' => $earned,
                    'projected' => $end,
                    'average' => $avg,
                    'difference' => $end !== null && $avg !== null ? $end - $avg : null,
                    'catch_up' => $end !== null && $avg !== null ? max(0, $avg - $end) : null,
                ];
            }
        }
        return $result;
    }

    public function weeklyBalances(DateTime $from, DateTime $to, array $effectiveActivities): array
    {
        $users = $this->users();
        $start = clone $from;
        if ((int)$start->format('N') !== 1) $start->modify('monday this week');
        $end = clone $to;
        if ((int)$end->format('N') !== 1) $end->modify('monday this week');
        $balances = $this->balanceRows($start, $end);
        $potential = $this->weeklyAssignedPoints($effectiveActivities);
        $out = [];

        for ($d = clone $start; $d <= $end; $d->modify('+7 days')) {
            $wk = $d->format('Y-m-d');
            $row = ['week_start' => $wk, 'rehearsal' => [], 'performance' => []];
            foreach (['rehearsal', 'performance'] as $type) {
                $projected = [];
                foreach ($users as $u) {
                    $id = (int)$u['id'];
                    $opening = $balances[$wk][$type][$id] ?? null;
                    if ($opening !== null) $projected[$id] = $opening + (float)($potential[$wk][$type][$id] ?? 0);
                }
                $avg = $projected ? array_sum($projected) / count($projected) : null;
                foreach ($users as $u) {
                    $id = (int)$u['id'];
                    $opening = $balances[$wk][$type][$id] ?? null;
                    $earned = (float)($potential[$wk][$type][$id] ?? 0);
                    $proj = $opening !== null ? $opening + $earned : null;
                    $row[$type][] = ['name' => $u['name'], 'opening' => $opening, 'assigned' => $earned, 'projected' => $proj, 'average' => $avg, 'difference' => $proj !== null && $avg !== null ? $proj - $avg : null];
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function repertoire(array $effectiveActivities): array
    {
        $users = $this->users();
        $userNames = [];
        foreach ($users as $u) $userNames[(int)$u['id']] = $u['name'];
        $rows = $this->effectiveAssignmentRows($effectiveActivities);
        $pieces = [];

        foreach ($rows as $r) {
            $title = trim((string)($r['piece_title'] ?? ''));
            if ($title === '') $title = trim((string)($r['activity_title'] ?? 'Unassigned repertoire'));
            $key = (string)($r['piece_id'] ?? '') . '|' . $title;
            if (!isset($pieces[$key])) $pieces[$key] = ['title' => $title, 'users' => [], 'calls' => 0, 'rehearsals' => 0, 'performances' => 0, 'last_played' => null];
            $id = (int)$r['user_id'];
            if (!isset($pieces[$key]['users'][$id])) $pieces[$key]['users'][$id] = ['name' => $r['user_name'], 'calls' => 0, 'rehearsals' => 0, 'performances' => 0, 'last_played' => null];
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

    public function repertoireMatrix(array $repertoire): array
    {
        $users = $this->users();
        $rows = [];
        foreach ($repertoire as $p) {
            $row = ['title' => $p['title'], 'users' => []];
            foreach ($users as $u) {
                $id = (int)$u['id'];
                $x = $p['users'][$id] ?? null;
                $row['users'][$id] = [
                    'calls' => (int)($x['calls'] ?? 0),
                    'rehearsals' => (int)($x['rehearsals'] ?? 0),
                    'performances' => (int)($x['performances'] ?? 0)
                ];
            }
            $rows[] = $row;
        }
        return ['users' => $users, 'rows' => $rows];
    }

    private function effectiveAssignmentRows(array $activities): array
    {
        $assignments = $this->allAssignments();
        $userNames = [];
        foreach ($this->users() as $u) $userNames[(int)$u['id']] = $u['name'];
        $rows = [];

        foreach ($activities as $a) {
            $type = (string)($a['source_type'] ?? '');
            $id = (int)($a['source_id'] ?? 0);
            if ($id <= 0 || !isset($assignments[$type][$id])) continue;

            foreach ($assignments[$type][$id] as $assignment) {
                $uid = (int)$assignment['user_id'];
                $rows[] = [
                    'source_type' => $type,
                    'source_id' => $id,
                    'user_id' => $uid,
                    'user_name' => $userNames[$uid] ?? ('User ' . $uid),
                    'preference_at_assignment' => $assignment['preference_at_assignment'] ?? 'unanswered',
                    'uncertain_at_assignment' => (int)($assignment['uncertain_at_assignment'] ?? 0),
                    'schedule_date' => (string)$a['schedule_date'],
                    'period' => (string)($a['period'] ?? 'morning'),
                    'point_value' => (float)($a['point_value'] ?? 0),
                    'point_type' => $a['point_type'] ?? null,
                    'piece_id' => $a['piece_id'] ?? null,
                    'piece_title' => $a['piece_title'] ?? null,
                    'activity_title' => $a['activity_title'] ?? $a['title'] ?? '',
                ];
            }
        }
        return $rows;
    }

    private function weeklyAssignedPoints(array $activities): array
    {
        $rows = $this->effectiveAssignmentRows($activities);
        $multipliers = [];
        foreach ($this->users() as $u) {
            $m = (float)($u['multiplier'] ?? 1);
            $multipliers[(int)$u['id']] = $m > 0 ? $m : 1;
        }
        $out = [];
        foreach ($rows as $r) {
            if (!in_array($r['point_type'], ['rehearsal', 'performance'], true)) continue;
            $uid = (int)$r['user_id'];
            $week = (new DateTime($r['schedule_date']))->modify('monday this week')->format('Y-m-d');
            $out[$week][$r['point_type']][$uid] = (float)($out[$week][$r['point_type']][$uid] ?? 0) + (float)$r['point_value'] * ($multipliers[$uid] ?? 1);
        }
        return $out;
    }

    private function balanceRows(DateTime $from, DateTime $to): array
    {
        $s = $this->pdo->prepare("SELECT week_start,user_id,point_type,opening_points FROM weekly_point_balances WHERE week_start BETWEEN ? AND ?");
        $s->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['week_start']][$r['point_type']][(int)$r['user_id']] = (float)$r['opening_points'];
        return $out;
    }

    private function allAssignments(): array
    {
        $out = [];
        $s = $this->pdo->query("SELECT source_type,source_id,user_id,preference_at_assignment,uncertain_at_assignment FROM activity_assignments");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['source_type']][(int)$r['source_id']][] = $r;
        return $out;
    }

    private function users(): array
    {
        return $this->pdo->query("SELECT id,name,position,multiplier,sort_order FROM users WHERE status=1 ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function emptyWeekdayPeriods(): array
    {
        $r = [];
        for ($d = 1; $d <= 7; $d++) $r[$d] = ['morning' => 0, 'evening' => 0];
        return $r;
    }
}
