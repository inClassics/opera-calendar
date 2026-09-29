<?php

final class PointCalculator
{
    public function calculate(
        array $members,
        DateTime $seasonStartDate,
        DateTime $endDate,
        array $availability,
        array $splitEvents,
        array $splitAvailability,
        array $activityPointItems
    ): array {
        $runningRehearsal = [];
        $runningPerformance = [];

        foreach ($members as $member) {
            $userId = (int)$member['id'];
            $runningRehearsal[$userId] = (float)($member['morning_starting_points'] ?? 0);
            $runningPerformance[$userId] = (float)($member['evening_starting_points'] ?? 0);
        }

        $weeklyRehearsal = [];
        $weeklyPerformance = [];

        /*
        |--------------------------------------------------------------------------
        | Per-call point multipliers
        |--------------------------------------------------------------------------
        |
        | These are separate from the permanent user multiplier.
        | final earned points =
        | activity points × user multiplier × per-call multiplier.
        |
        | Keeping this in separate tables means the existing availability schema
        | and old data remain untouched.
        */
        $normalMultipliers = [];
        $splitMultipliers = [];

        global $pdo;

        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("
                    SELECT user_id, schedule_date, period, point_multiplier
                    FROM availability_point_multipliers
                    WHERE schedule_date BETWEEN ? AND ?
                ");
                $stmt->execute([
                    $seasonStartDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $normalMultipliers[$row['schedule_date']][$row['period']][(int)$row['user_id']]
                        = (float)$row['point_multiplier'];
                }

                $stmt = $pdo->prepare("
                    SELECT spm.split_event_id, spm.user_id, spm.point_multiplier
                    FROM split_point_multipliers spm
                    INNER JOIN schedule_split_events se
                        ON se.id = spm.split_event_id
                    WHERE se.schedule_date BETWEEN ? AND ?
                ");
                $stmt->execute([
                    $seasonStartDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $splitMultipliers[(int)$row['split_event_id']][(int)$row['user_id']]
                        = (float)$row['point_multiplier'];
                }
            } catch (Throwable) {
                // Migration not installed yet: preserve the old 1× behaviour.
                $normalMultipliers = [];
                $splitMultipliers = [];
            }
        }

        $addPoints = static function (
            float $pointValue,
            ?string $pointType,
            array $eventAvailability,
            array $callMultipliers = []
        ) use (
            $members,
            &$runningRehearsal,
            &$runningPerformance
        ): void {
            if ($pointValue <= 0) {
                return;
            }

            if (!in_array($pointType, ['rehearsal', 'performance'], true)) {
                return;
            }

            foreach ($members as $member) {
                $userId = (int)$member['id'];
                $item = $eventAvailability[$userId] ?? null;

                if (!is_array($item) || ($item['status'] ?? '') !== 'available') {
                    continue;
                }

                if (($item['counts_for_points'] ?? true) === false) {
                    continue;
                }

                $userMultiplier = (float)($member['multiplier'] ?? 1);
                if ($userMultiplier <= 0) {
                    $userMultiplier = 1;
                }

                $callMultiplier = (float)($callMultipliers[$userId] ?? 1);
                if ($callMultiplier < 0) {
                    $callMultiplier = 1;
                }

                $earnedPoints = $pointValue * $userMultiplier * $callMultiplier;

                if ($pointType === 'rehearsal') {
                    $runningRehearsal[$userId] += $earnedPoints;
                } else {
                    $runningPerformance[$userId] += $earnedPoints;
                }
            }
        };

        $date = clone $seasonStartDate;

        while ($date <= $endDate) {
            $ymd = $date->format('Y-m-d');
            $weekday = (int)$date->format('N');

            if ($weekday === 1) {
                $weeklyRehearsal[$ymd] = $runningRehearsal;
                $weeklyPerformance[$ymd] = $runningPerformance;
            }

            foreach (['morning', 'evening'] as $period) {
                $splitForSlot = $splitEvents[$ymd][$period] ?? [];

                if (!empty($splitForSlot)) {
                    foreach ($splitForSlot as $event) {
                        $eventId = (int)($event['id'] ?? 0);

                        if ($eventId <= 0) {
                            continue;
                        }

                        $addPoints(
                            (float)($event['point_value'] ?? 0),
                            $event['point_type'] ?? null,
                            $splitAvailability[$eventId] ?? [],
                            $splitMultipliers[$eventId] ?? []
                        );
                    }

                    continue;
                }

                $items = $activityPointItems[$ymd][$period] ?? [];

                if (empty($items)) {
                    continue;
                }

                $slotAvailability = $availability[$ymd][$period] ?? [];
                $callMultipliers = $normalMultipliers[$ymd][$period] ?? [];

                foreach ($items as $item) {
                    $addPoints(
                        (float)($item['point_value'] ?? 0),
                        $item['point_type'] ?? null,
                        $slotAvailability,
                        $callMultipliers
                    );
                }
            }

            $date->modify('+1 day');
        }

        return [
            'weekly_rehearsal' => $weeklyRehearsal,
            'weekly_performance' => $weeklyPerformance,
            'running_rehearsal' => $runningRehearsal,
            'running_performance' => $runningPerformance,
        ];
    }
}
