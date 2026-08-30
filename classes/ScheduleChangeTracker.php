<?php

final class ScheduleChangeTracker
{
    public function __construct(private PDO $pdo) {}

    public function changesForMonth(
        int $userId,
        bool $isAdmin,
        DateTime $firstDay,
        DateTime $lastDay
    ): array {
        $monthKey = $firstDay->format('Y-m');
        $currentMaxId = $this->currentMaxActivityId();
        $seenId = $this->seenId($userId, $monthKey);

        if ($seenId === null) {
            $this->saveSeenId($userId, $monthKey, $currentMaxId);

            return [
                'month' => $monthKey,
                'last_seen_activity_id' => $currentMaxId,
                'current_activity_id' => $currentMaxId,
                'count' => 0,
                'changes' => [],
            ];
        }

        $sql = "
            SELECT
                l.id,
                l.actor_user_id,
                l.affected_user_id,
                l.action,
                l.entity_type,
                l.entity_id,
                l.schedule_date,
                l.period,
                l.description,
                l.old_value,
                l.new_value,
                l.created_at,
                actor.name AS actor_name,
                affected.name AS affected_name
            FROM activity_log l
            LEFT JOIN users actor
                ON actor.id = l.actor_user_id
            LEFT JOIN users affected
                ON affected.id = l.affected_user_id
            WHERE
                l.id > ?
                AND l.schedule_date BETWEEN ? AND ?
                AND l.period IN ('morning', 'evening')
                AND (
                    l.actor_user_id IS NULL
                    OR l.actor_user_id <> ?
                )
        ";

        $params = [
            $seenId,
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d'),
            $userId,
        ];

        if (!$isAdmin) {
            $sql .= "
                AND (
                    l.affected_user_id IS NULL
                    OR l.affected_user_id = ?
                )
            ";
            $params[] = $userId;
        }

        $sql .= " ORDER BY l.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'month' => $monthKey,
            'last_seen_activity_id' => $seenId,
            'current_activity_id' => $currentMaxId,
            'count' => count($changes),
            'changes' => $changes,
        ];
    }

    public function markMonthSeen(
        int $userId,
        string $monthKey
    ): int {
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            throw new InvalidArgumentException('Invalid month.');
        }

        $firstDay = DateTime::createFromFormat(
            '!Y-m-d',
            $monthKey . '-01'
        );

        if (!$firstDay || $firstDay->format('Y-m') !== $monthKey) {
            throw new InvalidArgumentException('Invalid month.');
        }

        $activityId = $this->currentMaxActivityId();

        $this->saveSeenId(
            $userId,
            $monthKey,
            $activityId
        );

        return $activityId;
    }

    private function currentMaxActivityId(): int
    {
        return (int) $this->pdo
            ->query(
                'SELECT COALESCE(MAX(id), 0) FROM activity_log'
            )
            ->fetchColumn();
    }

    private function seenId(
        int $userId,
        string $monthKey
    ): ?int {
        $stmt = $this->pdo->prepare("
            SELECT last_seen_activity_id
            FROM user_schedule_seen
            WHERE user_id = ?
              AND month_key = ?
            LIMIT 1
        ");

        $stmt->execute([
            $userId,
            $monthKey,
        ]);

        $value = $stmt->fetchColumn();

        return $value === false
            ? null
            : (int) $value;
    }

    private function saveSeenId(
        int $userId,
        string $monthKey,
        int $activityId
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_schedule_seen
            (
                user_id,
                month_key,
                last_seen_activity_id,
                last_seen_at
            )
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                last_seen_activity_id =
                    VALUES(last_seen_activity_id),
                last_seen_at =
                    VALUES(last_seen_at)
        ");

        $stmt->execute([
            $userId,
            $monthKey,
            $activityId,
        ]);
    }
}
