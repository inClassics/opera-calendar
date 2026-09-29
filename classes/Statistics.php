<?php

final class Statistics
{
    public function __construct(private PDO $pdo) {}

    public function report(DateTime $from, DateTime $to): array
    {
        $users = $this->users();
        $rows = [];
        $byUser = [];

        foreach ($users as $user) {
            $uid = (int)$user['id'];

            $byUser[$uid] = [
                'id' => $uid,
                'name' => $user['name'],
                'position' => $user['position'],
                'multiplier' => (float)($user['multiplier'] ?? 1),
                'calls' => 0,
                'rehearsal_calls' => 0,
                'performance_calls' => 0,
                'untyped_calls' => 0,
                'raw_points' => 0.0,
                'weighted_points' => 0.0,
                'against_preference' => 0,
                'uncertain_calls' => 0,
                'unanswered_calls' => 0,
                'available_calls' => 0,
                'weekday_periods' => $this->emptyWeekdayPeriods(),
            ];
        }

        $assignments = $this->assignmentRows($from, $to);

        foreach ($assignments as $row) {
            $uid = (int)$row['user_id'];

            if (!isset($byUser[$uid])) {
                continue;
            }

            $weekday = (int)(new DateTime($row['schedule_date']))->format('N');
            $period = in_array($row['period'], ['morning', 'evening'], true)
                ? $row['period']
                : 'morning';

            $pointValue = (float)($row['point_value'] ?? 0);
            $multiplier = (float)$byUser[$uid]['multiplier'];

            if ($multiplier <= 0) {
                $multiplier = 1;
            }

            $byUser[$uid]['calls']++;
            $byUser[$uid]['weekday_periods'][$weekday][$period]++;

            if ($row['point_type'] === 'rehearsal') {
                $byUser[$uid]['rehearsal_calls']++;
            } elseif ($row['point_type'] === 'performance') {
                $byUser[$uid]['performance_calls']++;
            } else {
                $byUser[$uid]['untyped_calls']++;
            }

            $byUser[$uid]['raw_points'] += $pointValue;
            $byUser[$uid]['weighted_points'] += $pointValue * $multiplier;

            /*
            |--------------------------------------------------------------------------
            | Preference statistics
            |--------------------------------------------------------------------------
            |
            | This uses the snapshot captured when the assignment was made.
            | Therefore later availability edits do not rewrite history.
            |
            | unavailable = worked against dot
            | available   = worked on cross
            | unanswered  = assigned without an answer
            | uncertain_at_assignment is tracked independently
            |
            */
            $preference = (string)($row['preference_at_assignment'] ?? 'unanswered');

            if ($preference === 'unavailable') {
                $byUser[$uid]['against_preference']++;
            } elseif ($preference === 'available') {
                $byUser[$uid]['available_calls']++;
            } else {
                $byUser[$uid]['unanswered_calls']++;
            }

            if ((int)($row['uncertain_at_assignment'] ?? 0) === 1) {
                $byUser[$uid]['uncertain_calls']++;
            }

            $rows[] = $row;
        }

        $totals = [
            'calls' => 0,
            'rehearsal_calls' => 0,
            'performance_calls' => 0,
            'untyped_calls' => 0,
            'raw_points' => 0.0,
            'weighted_points' => 0.0,
            'against_preference' => 0,
            'uncertain_calls' => 0,
            'unanswered_calls' => 0,
            'available_calls' => 0,
            'weekday_periods' => $this->emptyWeekdayPeriods(),
        ];

        foreach ($byUser as $user) {
            foreach (
                [
                    'calls',
                    'rehearsal_calls',
                    'performance_calls',
                    'untyped_calls',
                    'against_preference',
                    'uncertain_calls',
                    'unanswered_calls',
                    'available_calls',
                ] as $key
            ) {
                $totals[$key] += $user[$key];
            }

            $totals['raw_points'] += $user['raw_points'];
            $totals['weighted_points'] += $user['weighted_points'];

            for ($day = 1; $day <= 7; $day++) {
                foreach (['morning', 'evening'] as $period) {
                    $totals['weekday_periods'][$day][$period] +=
                        $user['weekday_periods'][$day][$period];
                }
            }
        }

        return [
            'users' => array_values($byUser),
            'totals' => $totals,
            'assignments' => $rows,
        ];
    }

    private function users(): array
    {
        return $this->pdo->query("
            SELECT id, name, position, multiplier, sort_order
            FROM users
            WHERE status = 1
            ORDER BY sort_order, name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assignmentRows(DateTime $from, DateTime $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        $rows = [];

        $queries = [
            'calendar' => "
                SELECT
                    aa.source_type,
                    aa.source_id,
                    aa.user_id,
                    aa.preference_at_assignment,
                    aa.uncertain_at_assignment,
                    ce.schedule_date,
                    ce.period,
                    ce.point_value,
                    ce.point_type
                FROM activity_assignments aa
                INNER JOIN calendar_events ce
                    ON ce.id = aa.source_id
                WHERE aa.source_type = 'calendar'
                  AND ce.schedule_date BETWEEN ? AND ?
            ",
            'slot' => "
                SELECT
                    aa.source_type,
                    aa.source_id,
                    aa.user_id,
                    aa.preference_at_assignment,
                    aa.uncertain_at_assignment,
                    ss.schedule_date,
                    ss.period,
                    ss.point_value,
                    ss.point_type
                FROM activity_assignments aa
                INNER JOIN schedule_slots ss
                    ON ss.id = aa.source_id
                WHERE aa.source_type = 'slot'
                  AND ss.schedule_date BETWEEN ? AND ?
            ",
            'split' => "
                SELECT
                    aa.source_type,
                    aa.source_id,
                    aa.user_id,
                    aa.preference_at_assignment,
                    aa.uncertain_at_assignment,
                    se.schedule_date,
                    se.period,
                    se.point_value,
                    se.point_type
                FROM activity_assignments aa
                INNER JOIN schedule_split_events se
                    ON se.id = aa.source_id
                WHERE aa.source_type = 'split'
                  AND se.schedule_date BETWEEN ? AND ?
            ",
        ];

        foreach ($queries as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function emptyWeekdayPeriods(): array
    {
        $result = [];

        for ($day = 1; $day <= 7; $day++) {
            $result[$day] = [
                'morning' => 0,
                'evening' => 0,
            ];
        }

        return $result;
    }
}
