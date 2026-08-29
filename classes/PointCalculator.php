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

        foreach (
            $members
            as $member
        ) {
            $userId =
                (int) $member['id'];

            $runningRehearsal[$userId] =
                (float) (
                    $member['morning_starting_points']
                    ?? 0
                );

            $runningPerformance[$userId] =
                (float) (
                    $member['evening_starting_points']
                    ?? 0
                );
        }

        $weeklyRehearsal = [];
        $weeklyPerformance = [];

        /*
        |--------------------------------------------------------------------------
        | Add points for one event
        |--------------------------------------------------------------------------
        |
        | Each player's own multiplier is applied to the points earned from
        | the event.
        |
        | Example:
        |
        | event = 3 points
        | multiplier 1 = +3
        | multiplier 2 = +6
        |
        | Starting points are NOT multiplied.
        |
        */

        $addPoints =
            static function (
                float $pointValue,
                ?string $pointType,
                array $eventAvailability
            ) use (
                $members,
                &$runningRehearsal,
                &$runningPerformance
            ): void {

                if (
                    $pointValue <= 0
                ) {
                    return;
                }

                if (
                    !in_array(
                        $pointType,
                        [
                            'rehearsal',
                            'performance'
                        ],
                        true
                    )
                ) {
                    return;
                }

                foreach (
                    $members
                    as $member
                ) {
                    $userId =
                        (int) $member['id'];

                    $item =
                        $eventAvailability[$userId]
                        ?? null;

                    /*
                    |--------------------------------------------------------------------------
                    | Player must be available
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !is_array(
                            $item
                        )
                        ||
                        (
                            $item['status']
                            ?? ''
                        )
                        !== 'available'
                    ) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Availability can explicitly be excluded from points
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (
                            $item['counts_for_points']
                            ?? true
                        )
                        === false
                    ) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | User multiplier
                    |--------------------------------------------------------------------------
                    */

                    $multiplier =
                        (float) (
                            $member['multiplier']
                            ?? 1
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Defensive fallback
                    |--------------------------------------------------------------------------
                    |
                    | A missing, zero or invalid multiplier should not
                    | accidentally remove someone's points.
                    |
                    */

                    if (
                        $multiplier <= 0
                    ) {
                        $multiplier = 1;
                    }

                    $earnedPoints =
                        $pointValue
                        *
                        $multiplier;

                    /*
                    |--------------------------------------------------------------------------
                    | Add to rehearsal/performance balance
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $pointType
                        === 'rehearsal'
                    ) {
                        $runningRehearsal[$userId] +=
                            $earnedPoints;
                    } else {
                        $runningPerformance[$userId] +=
                            $earnedPoints;
                    }
                }
            };

        /*
        |--------------------------------------------------------------------------
        | Walk through season
        |--------------------------------------------------------------------------
        */

        $date =
            clone $seasonStartDate;

        while (
            $date <= $endDate
        ) {
            $ymd =
                $date->format(
                    'Y-m-d'
                );

            $weekday =
                (int) $date->format(
                    'N'
                );

            /*
            |--------------------------------------------------------------------------
            | Monday snapshot
            |--------------------------------------------------------------------------
            */

            if (
                $weekday === 1
            ) {
                $weeklyRehearsal[$ymd] =
                    $runningRehearsal;

                $weeklyPerformance[$ymd] =
                    $runningPerformance;
            }

            foreach (
                [
                    'morning',
                    'evening'
                ]
                as $period
            ) {
                /*
                |--------------------------------------------------------------------------
                | Split events
                |--------------------------------------------------------------------------
                */

                $splitForSlot =
                    $splitEvents[$ymd][$period]
                    ?? [];

                if (
                    !empty($splitForSlot)
                ) {
                    foreach (
                        $splitForSlot
                        as $event
                    ) {
                        $eventId =
                            (int) (
                                $event['id']
                                ?? 0
                            );

                        if (
                            $eventId <= 0
                        ) {
                            continue;
                        }

                        $addPoints(
                            (float) (
                                $event['point_value']
                                ?? 0
                            ),
                            $event['point_type']
                                ?? null,
                            $splitAvailability[$eventId]
                                ?? []
                        );
                    }

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Normal unsplit activity
                |--------------------------------------------------------------------------
                */

                $items =
                    $activityPointItems[$ymd][$period]
                    ?? [];

                if (
                    empty($items)
                ) {
                    continue;
                }

                $slotAvailability =
                    $availability[$ymd][$period]
                    ?? [];

                foreach (
                    $items
                    as $item
                ) {
                    $addPoints(
                        (float) (
                            $item['point_value']
                            ?? 0
                        ),
                        $item['point_type']
                            ?? null,
                        $slotAvailability
                    );
                }
            }

            $date->modify(
                '+1 day'
            );
        }

        return [
            'weekly_rehearsal' =>
            $weeklyRehearsal,

            'weekly_performance' =>
            $weeklyPerformance,

            'running_rehearsal' =>
            $runningRehearsal,

            'running_performance' =>
            $runningPerformance,
        ];
    }
}
