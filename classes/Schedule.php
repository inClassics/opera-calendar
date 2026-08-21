<?php

class Schedule
{
    public function __construct(private PDO $pdo) {}

    public function monthContext(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        if ($year < 2020 || $year > 2100) {
            $year = (int) date('Y');
        }

        /*
    |--------------------------------------------------------------------------
    | Actual selected month
    |--------------------------------------------------------------------------
    */

        $monthFirstDay = new DateTime(
            sprintf('%04d-%02d-01', $year, $month)
        );

        $monthLastDay = (clone $monthFirstDay)
            ->modify('last day of this month');

        /*
    |--------------------------------------------------------------------------
    | Calendar display range
    |--------------------------------------------------------------------------
    |
    | Always start Monday and finish Sunday.
    |
    */

        $firstDay = clone $monthFirstDay;

        if ((int) $firstDay->format('N') !== 1) {
            $firstDay->modify('previous monday');
        }

        $lastDay = clone $monthLastDay;

        if ((int) $lastDay->format('N') !== 7) {
            $lastDay->modify('next sunday');
        }

        /*
    |--------------------------------------------------------------------------
    | Month navigation
    |--------------------------------------------------------------------------
    */

        $previousMonth = (clone $monthFirstDay)
            ->modify('-1 month');

        $nextMonth = (clone $monthFirstDay)
            ->modify('+1 month');

        return [
            'year' => $year,
            'month' => $month,

            'monthFirstDay' => $monthFirstDay,
            'monthLastDay' => $monthLastDay,

            'firstDay' => $firstDay,
            'lastDay' => $lastDay,

            'previousMonth' => $previousMonth,
            'nextMonth' => $nextMonth,

            'monthTitle' => $monthFirstDay->format('F Y'),
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
            "SELECT user_id, schedule_date, period, status, uncertain
             FROM availability
             WHERE schedule_date BETWEEN ? AND ?"
        );
        $stmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);

        $availability = [];
        foreach ($stmt->fetchAll() as $row) {
            $availability[$row['schedule_date']][$row['period']][(int) $row['user_id']] = [

                'status' => $row['status'],

                'uncertain' => (bool) $row['uncertain'],

            ];
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

    private function loadSlots(
        DateTime $firstDay,
        DateTime $lastDay
    ): array {
        /*
    |--------------------------------------------------------------------------
    | Manual schedule items
    |--------------------------------------------------------------------------
    */

        $stmt = $this->pdo->prepare("
        SELECT
            schedule_date,
            period,
            activity
        FROM schedule_slots
        WHERE schedule_date BETWEEN ? AND ?
    ");

        $stmt->execute([
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d')
        ]);

        $manual = [];

        foreach ($stmt->fetchAll() as $row) {
            $manual[$row['schedule_date']][$row['period']] = $row['activity'];
        }

        /*
    |--------------------------------------------------------------------------
    | Imported calendar events
    |--------------------------------------------------------------------------
    */

        $stmt = $this->pdo->prepare("
        SELECT
            schedule_date,
            period,
            summary,
            start_local,
            end_local
        FROM calendar_events
        WHERE schedule_date BETWEEN ? AND ?
        ORDER BY start_local ASC
    ");

        $stmt->execute([
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d')
        ]);

        $imported = [];

        foreach ($stmt->fetchAll() as $row) {

            $start =
                new DateTime(
                    $row['start_local']
                );

            $end =
                !empty($row['end_local'])
                ? new DateTime($row['end_local'])
                : null;

            $text =
                $start->format('H:i');

            if ($end) {
                $text .=
                    '–' .
                    $end->format('H:i');
            }

            $text .=
                ' ' .
                $row['summary'];

            $imported[$row['schedule_date']][$row['period']][] = $text;
        }

        /*
    |--------------------------------------------------------------------------
    | Merge
    |--------------------------------------------------------------------------
    |
    | Manual text overrides imported calendar text.
    |
    */

        $slots = [];

        $current =
            clone $firstDay;

        while ($current <= $lastDay) {

            $date =
                $current->format('Y-m-d');

            foreach (
                ['morning', 'evening']
                as $period
            ) {

                if (
                    array_key_exists(
                        $period,
                        $manual[$date] ?? []
                    )
                ) {

                    $slots[$date][$period] =
                        $manual[$date][$period];
                } elseif (
                    !empty($imported[$date][$period])
                ) {

                    $slots[$date][$period] =
                        implode(
                            "\n",
                            $imported[$date][$period]
                        );
                }
            }

            $current->modify('+1 day');
        }

        return $slots;
    }

    public function setUncertain(
        int $userId,
        string $date,
        string $period,
        bool $uncertain,
        int $updatedBy
    ): void {
        $stmt = $this->pdo->prepare("
        UPDATE availability
        SET uncertain = ?,
            updated_by = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_id = ?
          AND schedule_date = ?
          AND period = ?
          AND status IS NOT NULL
    ");

        $stmt->execute([
            $uncertain ? 1 : 0,
            $updatedBy,
            $userId,
            $date,
            $period
        ]);
    }
}
