<?php

class User
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function activeUsers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                id,
                name,
                username,
                role,
                status,
                sort_order,
                evening_starting_points,
                morning_starting_points,
                birthday,
                name_day,
                position,
                multiplier
             FROM users
             WHERE status = 1
             ORDER BY sort_order ASC, name ASC"
        );

        return $stmt->fetchAll();
    }

    public function allUsers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                id,
                name,
                username,
                role,
                status,
                sort_order,
                evening_starting_points,
                morning_starting_points,
                birthday,
                name_day,
                position,
                multiplier
             FROM users
             ORDER BY sort_order ASC, name ASC"
        );

        return $stmt->fetchAll();
    }

    public function findByUsername(
        string $username
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                name,
                username,
                password_hash,
                role,
                status,
                position,
                multiplier
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        $stmt->execute([
            $username
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function create(
        string $name,
        string $username,
        string $password,
        string $role,
        int $sortOrder
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users
            (
                name,
                username,
                password_hash,
                role,
                status,
                sort_order
            )
            VALUES (?, ?, ?, ?, 1, ?)"
        );

        $stmt->execute([
            $name,
            $username,
            password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            $role,
            $sortOrder,
        ]);
    }

    public function update(
        int $id,
        string $name,
        string $username,
        string $role,
        int $status,
        int $sortOrder,
        ?string $password = null
    ): void {
        if (
            $password !== null
            &&
            $password !== ''
        ) {
            $stmt = $this->pdo->prepare(
                "UPDATE users
                 SET
                    name = ?,
                    username = ?,
                    role = ?,
                    status = ?,
                    sort_order = ?,
                    password_hash = ?
                 WHERE id = ?"
            );

            $stmt->execute([
                $name,
                $username,
                $role,
                $status,
                $sortOrder,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $id
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE users
             SET
                name = ?,
                username = ?,
                role = ?,
                status = ?,
                sort_order = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $name,
            $username,
            $role,
            $status,
            $sortOrder,
            $id
        ]);
    }
}
