<?php

class Schedule
{
    public function __construct(private PDO $pdo)
    {
    }

    public function monthContext(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        if ($year < 2020 || $year > 2100) {
            $year = (int) date('Y');
        }

        $firstDay = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $lastDay = (clone $firstDay)->modify('last day of this month');
        $previousMonth = (clone $firstDay)->modify('-1 month');
        $nextMonth = (clone $firstDay)->modify('+1 month');

        return compact('year', 'month', 'firstDay', 'lastDay', 'previousMonth', 'nextMonth') + [
            'monthTitle' => $firstDay->format('F Y'),
        ];
    }

    public function daysForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $slots = $this->loadSlots($firstDay, $lastDay);
        $days = [];
        $current = clone $firstDay;

        while ($current <= $lastDay) {
            $date = $current->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'weekday' => $current->format('l'),
                'weekday_short' => $current->format('D'),
                'day' => (int) $current->format('j'),
                'evening' => $slots[$date]['evening'] ?? '',
                'morning' => $slots[$date]['morning'] ?? '',
            ];
            $current->modify('+1 day');
        }

        return $days;
    }

    public function availabilityForMonth(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id, schedule_date, period, status
             FROM availability
             WHERE schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $availability = [];
        foreach ($stmt->fetchAll() as $row) {
            $availability[$row['schedule_date']][$row['period']][(int) $row['user_id']] = $row['status'];
        }

        return $availability;
    }

    public function saveAvailability(int $userId, string $date, string $period, string $status, int $updatedBy): void
    {
        if ($status === '') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM availability
                 WHERE user_id = ? AND schedule_date = ? AND period = ?"
            );
            $stmt->execute([$userId, $date, $period]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO availability (user_id, schedule_date, period, status, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $date, $period, $status, $updatedBy]);
    }

    public function saveActivity(string $date, string $period, string $activity, int $updatedBy): void
    {
        if ($activity === '') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM schedule_slots
                 WHERE schedule_date = ? AND period = ?"
            );
            $stmt->execute([$date, $period]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO schedule_slots (schedule_date, period, activity, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                activity = VALUES(activity),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$date, $period, $activity, $updatedBy]);
    }

    private function loadSlots(DateTime $firstDay, DateTime $lastDay): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT schedule_date, period, activity
             FROM schedule_slots
             WHERE schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $slots = [];
        foreach ($stmt->fetchAll() as $row) {
            $slots[$row['schedule_date']][$row['period']] = $row['activity'];
        }

        return $slots;
    }
}
