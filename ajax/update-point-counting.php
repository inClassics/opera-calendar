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

        $pointCounting->setNormal(
            $userId,
            $date,
            $period,
            $countsForPoints,
            current_user_id()
        );

        json_response([
            'success' => true,
            'counts_for_points' =>
                $countsForPoints,
        ]);
    }

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

        $pointCounting->setSplit(
            $eventId,
            $userId,
            $countsForPoints,
            current_user_id()
        );

        json_response([
            'success' => true,
            'counts_for_points' =>
                $countsForPoints,
        ]);
    }

    json_response([
        'success' => false,
        'message' =>
            'Invalid availability scope.',
    ], 400);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' =>
            $e->getMessage(),
    ], 400);
}
