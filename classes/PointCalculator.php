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
            $userId = (int) $member['id'];

            $runningRehearsal[$userId] =
                (float) ($member['morning_starting_points'] ?? 0);

            $runningPerformance[$userId] =
                (float) ($member['evening_starting_points'] ?? 0);
        }

        $weeklyRehearsal = [];
        $weeklyPerformance = [];

        $addPoints = static function (
            float $pointValue,
            ?string $pointType,
            array $eventAvailability
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
                $userId = (int) $member['id'];
                $item = $eventAvailability[$userId] ?? null;

                if (
                    !is_array($item)
                    || ($item['status'] ?? '') !== 'available'
                ) {
                    continue;
                }

                if ($pointType === 'rehearsal') {
                    $runningRehearsal[$userId] += $pointValue;
                } else {
                    $runningPerformance[$userId] += $pointValue;
                }
            }
        };

        $date = clone $seasonStartDate;

        while ($date <= $endDate) {
            $ymd = $date->format('Y-m-d');
            $weekday = (int) $date->format('N');

            /*
            | Weekly totals are displayed at the beginning of Monday,
            | before Monday's activities are counted.
            */
            if ($weekday === 1) {
                $weeklyRehearsal[$ymd] = $runningRehearsal;
                $weeklyPerformance[$ymd] = $runningPerformance;
            }

            foreach (['morning', 'evening'] as $period) {
                /*
                | Once a slot is split, split activities are the source of truth
                | for both availability and points. Do not also count the slot.
                */
                $splitForSlot = $splitEvents[$ymd][$period] ?? [];

                if (!empty($splitForSlot)) {
                    foreach ($splitForSlot as $event) {
                        $eventId = (int) ($event['id'] ?? 0);

                        if ($eventId <= 0) {
                            continue;
                        }

                        $addPoints(
                            (float) ($event['point_value'] ?? 0),
                            $event['point_type'] ?? null,
                            $splitAvailability[$eventId] ?? []
                        );
                    }

                    continue;
                }

                /*
                | Unsplit activities share the slot-level availability.
                | If two activities need different people, the slot must be split.
                */
                $items = $activityPointItems[$ymd][$period] ?? [];

                if (empty($items)) {
                    continue;
                }

                $slotAvailability = $availability[$ymd][$period] ?? [];

                foreach ($items as $item) {
                    $addPoints(
                        (float) ($item['point_value'] ?? 0),
                        $item['point_type'] ?? null,
                        $slotAvailability
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
