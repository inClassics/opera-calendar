<?php

final class WeeklyPointBalance
{
    public function __construct(private PDO $pdo) {}

    public function forRange(DateTime $from, DateTime $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT week_start, user_id, point_type, opening_points
             FROM weekly_point_balances
             WHERE week_start BETWEEN ? AND ?"
        );

        $stmt->execute([
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['week_start']][$row['point_type']][(int) $row['user_id']] =
                (float) $row['opening_points'];
        }

        return $result;
    }

    public function save(
        string $weekStart,
        int $userId,
        string $pointType,
        ?float $openingPoints,
        int $updatedBy
    ): void {
        $date = DateTime::createFromFormat('!Y-m-d', $weekStart);

        if (
            !$date
            || $date->format('Y-m-d') !== $weekStart
            || (int) $date->format('N') !== 1
        ) {
            throw new InvalidArgumentException('Week start must be a Monday.');
        }

        if (!in_array($pointType, ['rehearsal', 'performance'], true)) {
            throw new InvalidArgumentException('Invalid point type.');
        }

        if ($openingPoints === null) {
            $stmt = $this->pdo->prepare(
                "DELETE FROM weekly_point_balances
                 WHERE week_start = ? AND user_id = ? AND point_type = ?"
            );
            $stmt->execute([$weekStart, $userId, $pointType]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO weekly_point_balances
                (week_start, user_id, point_type, opening_points, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                opening_points = VALUES(opening_points),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            $weekStart,
            $userId,
            $pointType,
            $openingPoints,
            $updatedBy,
        ]);
    }
}
