<?php

class IcsImporter
{
    public function __construct(
        private PDO $pdo,
        private string $timezone = 'Europe/Riga'
    ) {}

    public function import(
        string $icsText,
        string $sourceKey,
        string $sourceName,
        ?string $feedUrl = null
    ): array {
        $sourceId = $this->ensureSource(
            $sourceKey,
            $sourceName,
            $feedUrl
        );

        $events = $this->parseEvents($icsText);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $checkStmt = $this->pdo->prepare("
            SELECT id
            FROM calendar_events
            WHERE source_id = ?
              AND source_uid = ?
            LIMIT 1
        ");

        $saveStmt = $this->pdo->prepare("
            INSERT INTO calendar_events (
                source_id,
                source_uid,
                summary,
                description,
                location,
                source_url,
                start_local,
                end_local,
                schedule_date,
                period
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                summary = VALUES(summary),
                description = VALUES(description),
                location = VALUES(location),
                source_url = VALUES(source_url),
                start_local = VALUES(start_local),
                end_local = VALUES(end_local),
                schedule_date = VALUES(schedule_date),
                period = VALUES(period),
                imported_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach ($events as $event) {

            if (
                empty($event['UID']['value']) ||
                empty($event['DTSTART']['value'])
            ) {
                $skipped++;
                continue;
            }

            $start = $this->parseDateTime(
                $event['DTSTART']
            );

            if (!$start) {
                $skipped++;
                continue;
            }

            $end = !empty($event['DTEND'])
                ? $this->parseDateTime($event['DTEND'])
                : null;

            $uid = trim(
                $event['UID']['value']
            );

            $summary = $this->unescapeText(
                $event['SUMMARY']['value'] ?? 'Untitled event'
            );

            $description = isset($event['DESCRIPTION'])
                ? $this->unescapeText($event['DESCRIPTION']['value'])
                : null;

            $location = isset($event['LOCATION'])
                ? $this->unescapeText($event['LOCATION']['value'])
                : null;

            $sourceUrl =
                $event['URL']['value'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Morning / evening rule
            |--------------------------------------------------------------------------
            |
            | Events beginning before 15:00 local Riga time = morning.
            | Events beginning from 15:00 onward = evening.
            |
            */

            $period =
                (int) $start->format('G') < 15
                ? 'morning'
                : 'evening';

            $checkStmt->execute([
                $sourceId,
                $uid
            ]);

            $exists =
                (bool) $checkStmt->fetchColumn();

            $saveStmt->execute([
                $sourceId,
                $uid,
                substr($summary, 0, 255),
                $description,
                $location
                    ? substr($location, 0, 255)
                    : null,
                $sourceUrl,
                $start->format('Y-m-d H:i:s'),
                $end
                    ? $end->format('Y-m-d H:i:s')
                    : null,
                $start->format('Y-m-d'),
                $period
            ]);

            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return [
            'total' => count($events),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function ensureSource(
        string $sourceKey,
        string $sourceName,
        ?string $feedUrl
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO calendar_sources (
                source_key,
                name,
                feed_url,
                timezone
            )
            VALUES (?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                feed_url = VALUES(feed_url),
                timezone = VALUES(timezone)
        ");

        $stmt->execute([
            $sourceKey,
            $sourceName,
            $feedUrl,
            $this->timezone
        ]);

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM calendar_sources
            WHERE source_key = ?
            LIMIT 1
        ");

        $stmt->execute([
            $sourceKey
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function parseEvents(
        string $icsText
    ): array {
        $icsText = str_replace(
            ["\r\n", "\r"],
            "\n",
            $icsText
        );

        /*
        |--------------------------------------------------------------------------
        | Unfold iCalendar lines
        |--------------------------------------------------------------------------
        */

        $icsText = preg_replace(
            "/\n[ \t]/",
            '',
            $icsText
        );

        $lines = explode(
            "\n",
            $icsText
        );

        $events = [];
        $current = null;

        foreach ($lines as $line) {

            $line = trim(
                $line,
                "\r\n"
            );

            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }

            if ($line === 'END:VEVENT') {

                if (is_array($current)) {
                    $events[] = $current;
                }

                $current = null;
                continue;
            }

            if (
                !is_array($current) ||
                !str_contains($line, ':')
            ) {
                continue;
            }

            [$left, $value] =
                explode(':', $line, 2);

            $parts =
                explode(';', $left);

            $name =
                strtoupper(array_shift($parts));

            $params = [];

            foreach ($parts as $part) {

                if (!str_contains($part, '=')) {
                    continue;
                }

                [$key, $paramValue] =
                    explode('=', $part, 2);

                $params[strtoupper($key)] =
                    trim($paramValue, '"');
            }

            $current[$name] = [
                'value' => $value,
                'params' => $params,
            ];
        }

        return $events;
    }

    private function parseDateTime(
        array $property
    ): ?DateTimeImmutable {
        $value =
            trim($property['value'] ?? '');

        if ($value === '') {
            return null;
        }

        $targetTimezone =
            new DateTimeZone($this->timezone);

        try {

            if (str_ends_with($value, 'Z')) {

                $date = DateTimeImmutable::createFromFormat(
                    '!Ymd\THis\Z',
                    $value,
                    new DateTimeZone('UTC')
                );
            } else {

                $timezoneName =
                    $property['params']['TZID']
                    ?? $this->timezone;

                $date = DateTimeImmutable::createFromFormat(
                    '!Ymd\THis',
                    $value,
                    new DateTimeZone($timezoneName)
                );
            }

            if (!$date) {
                return null;
            }

            return $date->setTimezone(
                $targetTimezone
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function unescapeText(
        string $text
    ): string {
        return str_replace(
            [
                '\\n',
                '\\N',
                '\\,',
                '\\;',
                '\\\\'
            ],
            [
                "\n",
                "\n",
                ',',
                ';',
                '\\'
            ],
            $text
        );
    }
}
