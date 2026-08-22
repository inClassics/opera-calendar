<?php

require_once __DIR__ . '/../classes/PointCalculator.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL: {$message}\nExpected: "
            . var_export($expected, true)
            . "\nActual: "
            . var_export($actual, true)
            . "\n"
        );

        exit(1);
    }
}

$members = [
    [
        'id' => 1,
        'morning_starting_points' => 10,
        'evening_starting_points' => 20,
    ],
];

$availability = [
    '2026-08-03' => [
        'evening' => [
            1 => [
                'status' => 'available',
                'uncertain' => false,
            ],
        ],
    ],
    '2026-08-04' => [
        'morning' => [
            1 => [
                'status' => 'available',
                'uncertain' => false,
            ],
        ],
    ],
];

$activityItems = [
    '2026-08-03' => [
        'evening' => [
            [
                'point_value' => 3,
                'point_type' => 'rehearsal',
            ],
        ],
    ],
    '2026-08-04' => [
        'morning' => [
            [
                'point_value' => 2,
                'point_type' => 'performance',
            ],
        ],
    ],
];

$calculator = new PointCalculator();

$result = $calculator->calculate(
    $members,
    new DateTime('2026-08-03'),
    new DateTime('2026-08-10'),
    $availability,
    [],
    [],
    $activityItems
);

/*
| Monday 3 Aug snapshot is before the week's events.
*/
assert_same(
    10.0,
    $result['weekly_rehearsal']['2026-08-03'][1],
    'Initial rehearsal points'
);

assert_same(
    20.0,
    $result['weekly_performance']['2026-08-03'][1],
    'Initial performance points'
);

/*
| Evening rehearsal must route to rehearsal total.
| Morning performance must route to performance total.
*/
assert_same(
    13.0,
    $result['weekly_rehearsal']['2026-08-10'][1],
    'Evening rehearsal goes to rehearsal total'
);

assert_same(
    22.0,
    $result['weekly_performance']['2026-08-10'][1],
    'Morning performance goes to performance total'
);

echo "PointCalculator tests passed.\n";
