<?php

final class CalendarAnnotations
{
    public function __construct(private PDO $pdo) {}

    public function forRange(DateTime $from, DateTime $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        $result = [
            'normal_multipliers' => [],
            'split_multipliers' => [],
            'day_statuses' => [],
        ];

        try {
            $stmt = $this->pdo->prepare("
                SELECT user_id, schedule_date, period, point_multiplier
                FROM availability_point_multipliers
                WHERE schedule_date BETWEEN ? AND ?
            ");
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['normal_multipliers'][$row['schedule_date']][$row['period']][(int)$row['user_id']]
                    = (float)$row['point_multiplier'];
            }

            $stmt = $this->pdo->prepare("
                SELECT spm.split_event_id, spm.user_id, spm.point_multiplier
                FROM split_point_multipliers spm
                INNER JOIN schedule_split_events se ON se.id = spm.split_event_id
                WHERE se.schedule_date BETWEEN ? AND ?
            ");
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['split_multipliers'][(int)$row['split_event_id']][(int)$row['user_id']]
                    = (float)$row['point_multiplier'];
            }

            $stmt = $this->pdo->prepare("
                SELECT user_id, schedule_date, status
                FROM user_day_status
                WHERE schedule_date BETWEEN ? AND ?
            ");
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['day_statuses'][$row['schedule_date']][(int)$row['user_id']]
                    = $row['status'];
            }
        } catch (Throwable) {
            // Keep Calendar usable before/while migration is installed.
        }

        return $result;
    }
}
