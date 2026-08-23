<?php

final class PointCounting
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function applyNormalFlags(
        array $availability,
        DateTime $firstDay,
        DateTime $lastDay
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT
                user_id,
                schedule_date,
                period,
                counts_for_points
             FROM availability
             WHERE schedule_date BETWEEN ? AND ?"
        );

        $stmt->execute([
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d'),
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = $row['schedule_date'];
            $period = $row['period'];
            $userId = (int) $row['user_id'];

            if (
                isset(
                    $availability[$date][$period][$userId]
                )
            ) {
                $availability[$date][$period][$userId]['counts_for_points'] =
                    (bool) $row['counts_for_points'];
            }
        }

        return $availability;
    }

    public function applySplitFlags(
        array $splitAvailability,
        DateTime $firstDay,
        DateTime $lastDay
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT
                sa.split_event_id,
                sa.user_id,
                sa.counts_for_points
             FROM split_availability sa
             INNER JOIN schedule_split_events se
                ON se.id = sa.split_event_id
             WHERE se.schedule_date BETWEEN ? AND ?"
        );

        $stmt->execute([
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d'),
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventId = (int) $row['split_event_id'];
            $userId = (int) $row['user_id'];

            if (
                isset(
                    $splitAvailability[$eventId][$userId]
                )
            ) {
                $splitAvailability[$eventId][$userId]['counts_for_points'] =
                    (bool) $row['counts_for_points'];
            }
        }

        return $splitAvailability;
    }

    public function setNormal(
        int $userId,
        string $date,
        string $period,
        bool $countsForPoints,
        int $updatedBy
    ): void {
        $stmt = $this->pdo->prepare(
            "SELECT status
             FROM availability
             WHERE user_id = ?
               AND schedule_date = ?
               AND period = ?
             LIMIT 1"
        );

        $stmt->execute([
            $userId,
            $date,
            $period,
        ]);

        $status = $stmt->fetchColumn();

        if ($status !== 'available') {
            throw new RuntimeException(
                'Only an available cross can be excluded from points.'
            );
        }

        $stmt = $this->pdo->prepare(
            "UPDATE availability
             SET
                counts_for_points = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?
               AND schedule_date = ?
               AND period = ?"
        );

        $stmt->execute([
            $countsForPoints ? 1 : 0,
            $updatedBy,
            $userId,
            $date,
            $period,
        ]);
    }

    public function setSplit(
        int $splitEventId,
        int $userId,
        bool $countsForPoints,
        int $updatedBy
    ): void {
        $stmt = $this->pdo->prepare(
            "SELECT status
             FROM split_availability
             WHERE split_event_id = ?
               AND user_id = ?
             LIMIT 1"
        );

        $stmt->execute([
            $splitEventId,
            $userId,
        ]);

        $status = $stmt->fetchColumn();

        if ($status !== 'available') {
            throw new RuntimeException(
                'Only an available cross can be excluded from points.'
            );
        }

        $stmt = $this->pdo->prepare(
            "UPDATE split_availability
             SET
                counts_for_points = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE split_event_id = ?
               AND user_id = ?"
        );

        $stmt->execute([
            $countsForPoints ? 1 : 0,
            $updatedBy,
            $splitEventId,
            $userId,
        ]);
    }
}
