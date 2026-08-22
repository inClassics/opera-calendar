<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Schedule.php';

if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Not logged in.',
    ], 401);
}

verify_csrf_or_fail($_POST['csrf_token'] ?? null);

$schedule = new Schedule($pdo);

function ajax_require_admin(): void
{
    if (!is_admin()) {
        json_response([
            'success' => false,
            'message' => 'Admin access required.',
        ], 403);
    }
}

function ajax_period(string $period): string
{
    if (!in_array($period, ['morning', 'evening'], true)) {
        json_response([
            'success' => false,
            'message' => 'Invalid period.',
        ], 400);
    }

    return $period;
}

function ajax_date(string $date): string
{
    if (!is_valid_date($date)) {
        json_response([
            'success' => false,
            'message' => 'Invalid date.',
        ], 400);
    }

    return $date;
}

function ajax_require_member_access(int $userId): void
{
    if ($userId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Invalid user.',
        ], 400);
    }

    if (!is_admin() && $userId !== current_user_id()) {
        json_response([
            'success' => false,
            'message' => 'You cannot edit this member.',
        ], 403);
    }
}

function ajax_require_active_user(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE id = ? AND status = 1 LIMIT 1'
    );

    $stmt->execute([$userId]);

    if (!$stmt->fetchColumn()) {
        json_response([
            'success' => false,
            'message' => 'User not found.',
        ], 404);
    }
}

function ajax_require_split_event(PDO $pdo, int $eventId): void
{
    if ($eventId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Invalid split event.',
        ], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM schedule_split_events WHERE id = ? LIMIT 1'
    );

    $stmt->execute([$eventId]);

    if (!$stmt->fetchColumn()) {
        json_response([
            'success' => false,
            'message' => 'Split event not found.',
        ], 404);
    }
}
