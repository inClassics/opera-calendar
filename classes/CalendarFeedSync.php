<?php

class CalendarFeedSync
{
    public function __construct(
        private PDO $pdo,
        private string $timezone = 'Europe/Riga'
    ) {}

    public function sourceByKey(string $sourceKey): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM calendar_sources
            WHERE source_key = ?
            LIMIT 1
        ");
        $stmt->execute([$sourceKey]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function saveFeedUrl(
        string $sourceKey,
        string $sourceName,
        string $feedUrl
    ): void {
        $feedUrl = $this->normalizeFeedUrl($feedUrl);

        $stmt = $this->pdo->prepare("
            INSERT INTO calendar_sources
            (
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
    }

    public function sync(
        string $sourceKey,
        string $sourceName
    ): array {
        $source = $this->sourceByKey($sourceKey);

        if (
            !$source
            ||
            empty($source['feed_url'])
        ) {
            throw new RuntimeException(
                'No calendar feed URL has been saved yet.'
            );
        }

        $url = $this->normalizeFeedUrl(
            $source['feed_url']
        );

        $icsText = $this->download($url);

        if (
            !str_contains(
                $icsText,
                'BEGIN:VCALENDAR'
            )
        ) {
            throw new RuntimeException(
                'The downloaded response is not a valid iCalendar feed.'
            );
        }

        require_once __DIR__ . '/IcsImporter.php';

        $importer = new IcsImporter(
            $this->pdo,
            $this->timezone
        );

        return $importer->import(
            $icsText,
            $sourceKey,
            $sourceName,
            $url,
            true
        );
    }

    private function normalizeFeedUrl(
        string $url
    ): string {
        $url = trim($url);

        if (str_starts_with($url, 'webcal://')) {
            $url =
                'https://' .
                substr(
                    $url,
                    strlen('webcal://')
                );
        }

        $parts = parse_url($url);

        if (
            !$parts
            ||
            empty($parts['scheme'])
            ||
            empty($parts['host'])
            ||
            !in_array(
                strtolower($parts['scheme']),
                ['https', 'http'],
                true
            )
        ) {
            throw new RuntimeException(
                'The calendar feed URL must be a valid webcal, https, or http URL.'
            );
        }

        return $url;
    }

    private function download(
        string $url
    ): string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_USERAGENT => 'SectionSchedule/1.0',
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/calendar, text/plain;q=0.9, */*;q=0.5'
                    ],
                ]
            );

            $body = curl_exec($ch);

            if ($body === false) {
                $message =
                    curl_error($ch)
                    ?: 'Unknown network error.';

                curl_close($ch);

                throw new RuntimeException(
                    'Could not download calendar: ' .
                        $message
                );
            }

            $status =
                (int) curl_getinfo(
                    $ch,
                    CURLINFO_RESPONSE_CODE
                );

            curl_close($ch);

            if (
                $status < 200
                ||
                $status >= 300
            ) {
                throw new RuntimeException(
                    'Calendar server returned HTTP ' .
                        $status . '.'
                );
            }

            return $body;
        }

        $context =
            stream_context_create(
                [
                    'http' => [
                        'timeout' => 30,
                        'follow_location' => 1,
                        'header' =>
                        "Accept: text/calendar\r\n" .
                            "User-Agent: SectionSchedule/1.0\r\n",
                    ],
                ]
            );

        $body =
            @file_get_contents(
                $url,
                false,
                $context
            );

        if ($body === false) {
            throw new RuntimeException(
                'Could not download the calendar feed.'
            );
        }

        return $body;
    }
}
