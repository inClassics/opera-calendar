<?php

function format_points(float|int $value): string
{
    $formatted = number_format((float) $value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function getSpecialDayClass(array $member, string $date): string
{
    $classes = [];
    $monthDay = date('m-d', strtotime($date));

    if (
        !empty($member['birthday'])
        && date('m-d', strtotime($member['birthday'])) === $monthDay
    ) {
        $classes[] = 'birthday-cell';
    }

    if (
        !empty($member['name_day'])
        && date('m-d', strtotime($member['name_day'])) === $monthDay
    ) {
        $classes[] = 'name-day-cell';
    }

    return implode(' ', $classes);
}

function getSpecialDayEmoji(array $member, string $date): string
{
    $class = getSpecialDayClass($member, $date);
    $emoji = '';

    if (str_contains($class, 'birthday-cell')) {
        $emoji .= '🎂';
    }

    if (str_contains($class, 'name-day-cell')) {
        $emoji .= '🌼';
    }

    return $emoji;
}

function slotEvents(
    array $day,
    string $period,
    array $splitEvents,
    array $activityPointItems
): array {
    $events = $splitEvents[$day['date']][$period] ?? [];

    if (!empty($events)) {
        return $events;
    }

    return [[
        'id' => null,
        'activity' => $day[$period] ?? '',
        'sort_order' => 0,
        'point_items' => $activityPointItems[$day['date']][$period] ?? [],
    ]];
}

function renderActivityMarkup(string $text): string
{
    $text = htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $text = preg_replace(
        '/\*\*(.+?)\*\*/su',
        '<strong>$1</strong>',
        $text
    );

    $text = preg_replace(
        '/(?<!\*)\*([^*\r\n]+?)\*(?!\*)/u',
        '<em>$1</em>',
        $text
    );

    return nl2br($text, false);
}

function desktopPaperActivityParts(string $activity): array
{
    $activity = trim($activity);

    if ($activity === '') {
        return [
            'time' => '',
            'title' => '',
            'details' => '',
        ];
    }

    $time = '';

    if (
        preg_match(
            '/^(\d{1,2}:\d{2}(?:\s*[–—-]\s*\d{1,2}:\d{2})?)/u',
            $activity,
            $match
        )
    ) {
        $time = trim($match[1]);
        $activity = trim(
            mb_substr(
                $activity,
                mb_strlen($match[0])
            )
        );
    }

    $details = '';

    if (
        preg_match(
            '/\s*(\([^()]+\))\s*$/u',
            $activity,
            $match
        )
    ) {
        $details = trim($match[1]);

        $activity = trim(
            mb_substr(
                $activity,
                0,
                mb_strlen($activity) - mb_strlen($match[0])
            )
        );
    }

    return [
        'time' => $time,
        'title' => $activity,
        'details' => $details,
    ];
}
