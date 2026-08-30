<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/PointCounting.php';

$userId =
    (int) (
        $_POST['user_id']
        ?? 0
    );

$scope =
    trim(
        (string) (
            $_POST['scope']
            ?? ''
        )
    );

$countsForPoints =
    (string) (
        $_POST['counts_for_points']
        ?? '1'
    )
    === '1';

ajax_require_member_access(
    $userId
);

ajax_require_active_user(
    $pdo,
    $userId
);

$pointCounting =
    new PointCounting(
        $pdo
    );

try {

    /*
    |--------------------------------------------------------------------------
    | Normal availability
    |--------------------------------------------------------------------------
    */

    if (
        $scope === 'normal'
    ) {
        $date =
            ajax_date(
                (string) (
                    $_POST['date']
                    ?? ''
                )
            );

        $period =
            ajax_period(
                (string) (
                    $_POST['period']
                    ?? ''
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Old value
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT counts_for_points
                FROM availability
                WHERE user_id = ?
                  AND schedule_date = ?
                  AND period = ?
                LIMIT 1
            ");

        $stmt->execute([
            $userId,
            $date,
            $period
        ]);

        $oldValue =
            $stmt->fetchColumn();

        $oldValue =
            $oldValue === false
            ? true
            : (bool) $oldValue;

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $pointCounting->setNormal(
            $userId,
            $date,
            $period,
            $countsForPoints,
            current_user_id()
        );

        /*
        |--------------------------------------------------------------------------
        | Log
        |--------------------------------------------------------------------------
        */

        if (
            $oldValue
            !== $countsForPoints
        ) {
            $activityLogger->log(
                current_user_id(),
                'point_counting_changed',
                'availability',
                null,
                'Point counting changed',
                [
                    'counts_for_points' =>
                    $oldValue,
                ],
                [
                    'counts_for_points' =>
                    $countsForPoints,
                ],
                $userId,
                $date,
                $period
            );
        }

        json_response([
            'success' => true,

            'counts_for_points' =>
            $countsForPoints,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Split availability
    |--------------------------------------------------------------------------
    */

    if (
        $scope === 'split'
    ) {
        $eventId =
            (int) (
                $_POST['split_event_id']
                ?? 0
            );

        ajax_require_split_event(
            $pdo,
            $eventId
        );

        /*
        |--------------------------------------------------------------------------
        | Split event info
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT
                    schedule_date,
                    period,
                    activity
                FROM schedule_split_events
                WHERE id = ?
                LIMIT 1
            ");

        $stmt->execute([
            $eventId
        ]);

        $event =
            $stmt->fetch();

        /*
        |--------------------------------------------------------------------------
        | Old value
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT counts_for_points
                FROM split_availability
                WHERE split_event_id = ?
                  AND user_id = ?
                LIMIT 1
            ");

        $stmt->execute([
            $eventId,
            $userId
        ]);

        $oldValue =
            $stmt->fetchColumn();

        $oldValue =
            $oldValue === false
            ? true
            : (bool) $oldValue;

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $pointCounting->setSplit(
            $eventId,
            $userId,
            $countsForPoints,
            current_user_id()
        );

        /*
        |--------------------------------------------------------------------------
        | Log
        |--------------------------------------------------------------------------
        */

        if (
            $oldValue
            !== $countsForPoints
        ) {
            $activityLogger->log(
                current_user_id(),
                'split_point_counting_changed',
                'split_event',
                $eventId,
                'Split-event point counting changed',
                [
                    'counts_for_points' =>
                    $oldValue,

                    'activity' =>
                    $event['activity']
                        ?? '',
                ],
                [
                    'counts_for_points' =>
                    $countsForPoints,

                    'activity' =>
                    $event['activity']
                        ?? '',
                ],
                $userId,
                $event['schedule_date']
                    ?? null,
                $event['period']
                    ?? null
            );
        }

        json_response([
            'success' => true,

            'counts_for_points' =>
            $countsForPoints,
        ]);
    }

    json_response([
        'success' => false,
        'message' => 'Invalid availability scope.',
    ], 400);
} catch (
    Throwable $e
) {
    json_response([
        'success' => false,

        'message' =>
        $e->getMessage(),
    ], 400);
}
