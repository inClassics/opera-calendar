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

    public function findById(
        int $id
    ): ?array {
        $stmt = $this->pdo->prepare(
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
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->execute([$id]);

        $user = $stmt->fetch();

        return $user ?: null;
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
        int $sortOrder,
        string $position = 'Tutti',
        float $multiplier = 1.0
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users
            (
                name,
                username,
                password_hash,
                role,
                status,
                sort_order,
                position,
                multiplier
            )
            VALUES (?, ?, ?, ?, 1, ?, ?, ?)"
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
            $position,
            $multiplier,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $username,
        string $role,
        int $status,
        int $sortOrder,
        string $position,
        float $multiplier,
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
                    position = ?,
                    multiplier = ?,
                    password_hash = ?
                 WHERE id = ?"
            );

            $stmt->execute([
                $name,
                $username,
                $role,
                $status,
                $sortOrder,
                $position,
                $multiplier,
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
                sort_order = ?,
                position = ?,
                multiplier = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $name,
            $username,
            $role,
            $status,
            $sortOrder,
            $position,
            $multiplier,
            $id
        ]);
    }
}
