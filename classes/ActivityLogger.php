<?php

final class ActivityLogger
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function log(
        ?int $actorUserId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $description,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?int $affectedUserId = null,
        ?string $scheduleDate = null,
        ?string $period = null
    ): void {
        $stmt =
            $this->pdo->prepare("
                INSERT INTO activity_log
                (
                    actor_user_id,
                    affected_user_id,
                    action,
                    entity_type,
                    entity_id,
                    schedule_date,
                    period,
                    description,
                    old_value,
                    new_value
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

        $stmt->execute([
            $actorUserId,
            $affectedUserId,
            $action,
            $entityType,
            $entityId,
            $scheduleDate,
            $period,
            $description,
            $this->encodeValue(
                $oldValue
            ),
            $this->encodeValue(
                $newValue
            ),
        ]);
    }

    public function recent(
        int $limit = 200
    ): array {
        $limit =
            max(
                1,
                min(
                    1000,
                    $limit
                )
            );

        $sql = "
            SELECT
                l.*,

                actor.name AS actor_name,
                actor.role AS actor_role,

                affected.name AS affected_name

            FROM activity_log l

            LEFT JOIN users actor
                ON actor.id = l.actor_user_id

            LEFT JOIN users affected
                ON affected.id = l.affected_user_id

            ORDER BY
                l.id DESC

            LIMIT {$limit}
        ";

        return
            $this->pdo
            ->query(
                $sql
            )
            ->fetchAll();
    }

    private function encodeValue(
        mixed $value
    ): ?string {
        if (
            $value === null
        ) {
            return null;
        }

        if (
            is_string(
                $value
            )
        ) {
            return $value;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
        );
    }
}
