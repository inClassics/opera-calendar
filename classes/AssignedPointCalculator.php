<?php

final class AssignedPointCalculator
{
    public function __construct(private PDO $pdo) {}

    public function weeklyPotential(
        array $members,
        DateTime $from,
        DateTime $to,
        array $activities
    ): array {
        $userMultipliers = [];

        foreach ($members as $member) {
            $multiplier = (float)($member['multiplier'] ?? 1);
            $userMultipliers[(int)$member['id']] = $multiplier > 0 ? $multiplier : 1.0;
        }

        $assignments = $this->assignments($from, $to);
        $normalCallMultipliers = $this->normalCallMultipliers($from, $to);
        $splitCallMultipliers = $this->splitCallMultipliers($from, $to);

        $result = [];

        foreach ($activities as $activity) {
            $date = (string)($activity['schedule_date'] ?? '');
            $sourceType = (string)($activity['source_type'] ?? '');
            $sourceId = (int)($activity['source_id'] ?? 0);
            $pointType = $activity['point_type'] ?? null;
            $pointValue = (float)($activity['point_value'] ?? 0);

            if (
                $date === ''
                || $sourceId <= 0
                || $pointValue <= 0
                || !in_array($pointType, ['rehearsal', 'performance'], true)
            ) {
                continue;
            }

            $assignedUsers = $assignments[$sourceType][$sourceId] ?? [];

            foreach ($assignedUsers as $userId => $_assigned) {
                $userId = (int)$userId;

                if (!isset($userMultipliers[$userId])) {
                    continue;
                }

                $callMultiplier = 1.0;

                if (($activity['availability_source'] ?? '') === 'split') {
                    $splitId = (int)(
                        $activity['availability_source_id']
                        ?? $activity['split_event_id']
                        ?? 0
                    );

                    if ($splitId > 0) {
                        $callMultiplier = (float)(
                            $splitCallMultipliers[$splitId][$userId] ?? 1
                        );
                    }
                } else {
                    $period = (string)($activity['period'] ?? '');

                    $callMultiplier = (float)(
                        $normalCallMultipliers[$date][$period][$userId] ?? 1
                    );
                }

                if ($callMultiplier < 0) {
                    $callMultiplier = 1.0;
                }

                $earned =
                    $pointValue
                    * $userMultipliers[$userId]
                    * $callMultiplier;

                $weekStart = (new DateTime($date))
                    ->modify('monday this week')
                    ->format('Y-m-d');

                $result[$weekStart][$pointType][$userId] =
                    (float)($result[$weekStart][$pointType][$userId] ?? 0)
                    + $earned;
            }
        }

        return $result;
    }

    private function assignments(DateTime $from, DateTime $to): array
    {
        $result = [];
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        $queries = [
            'calendar' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN calendar_events ce ON ce.id = aa.source_id
                WHERE aa.source_type = 'calendar'
                  AND ce.schedule_date BETWEEN ? AND ?
            ",
            'slot' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN schedule_slots ss ON ss.id = aa.source_id
                WHERE aa.source_type = 'slot'
                  AND ss.schedule_date BETWEEN ? AND ?
            ",
            'split' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN schedule_split_events se ON se.id = aa.source_id
                WHERE aa.source_type = 'split'
                  AND se.schedule_date BETWEEN ? AND ?
            ",
        ];

        foreach ($queries as $sourceType => $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$sourceType][(int)$row['source_id']][(int)$row['user_id']] = true;
            }
        }

        return $result;
    }

    private function normalCallMultipliers(DateTime $from, DateTime $to): array
    {
        $result = [];

        try {
            $stmt = $this->pdo->prepare("
                SELECT user_id, schedule_date, period, point_multiplier
                FROM availability_point_multipliers
                WHERE schedule_date BETWEEN ? AND ?
            ");
            $stmt->execute([
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['schedule_date']][$row['period']][(int)$row['user_id']]
                    = (float)$row['point_multiplier'];
            }
        } catch (Throwable) {
            // Optional migration not present: default to 1x.
        }

        return $result;
    }

    private function splitCallMultipliers(DateTime $from, DateTime $to): array
    {
        $result = [];

        try {
            $stmt = $this->pdo->prepare("
                SELECT spm.split_event_id, spm.user_id, spm.point_multiplier
                FROM split_point_multipliers spm
                INNER JOIN schedule_split_events se
                    ON se.id = spm.split_event_id
                WHERE se.schedule_date BETWEEN ? AND ?
            ");
            $stmt->execute([
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int)$row['split_event_id']][(int)$row['user_id']]
                    = (float)$row['point_multiplier'];
            }
        } catch (Throwable) {
            // Optional migration not present: default to 1x.
        }

        return $result;
    }
}
