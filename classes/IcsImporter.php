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
        ?string $feedUrl = null,
        bool $fullSync = true
    ): array {
        $events =
            $this->parseEvents($icsText);

        $this->pdo->beginTransaction();

        $runId = null;

        try {
            $sourceId =
                $this->ensureSource(
                    $sourceKey,
                    $sourceName,
                    $feedUrl
                );

            $runId =
                $this->startSyncRun(
                    $sourceId
                );

            /*
            |--------------------------------------------------------------------------
            | Safe full-sync rule
            |--------------------------------------------------------------------------
            |
            | We never delete source events here. During a full feed sync we first
            | mark the old source rows "missing"; every event found in the new feed
            | is then restored to "active".
            |
            | If anything fails, the transaction rolls back.
            |
            */

            if ($fullSync) {
                $stmt =
                    $this->pdo->prepare("
                        UPDATE calendar_events
                        SET sync_status = 'missing',
                            missing_since =
                                COALESCE(
                                    missing_since,
                                    CURRENT_TIMESTAMP
                                )
                        WHERE source_id = ?
                    ");

                $stmt->execute([
                    $sourceId
                ]);
            }

            $inserted = 0;
            $changed = 0;
            $unchanged = 0;
            $moved = 0;
            $restored = 0;
            $skipped = 0;

            foreach ($events as $event) {

                if (
                    empty($event['UID']['value'])
                    ||
                    empty($event['DTSTART']['value'])
                ) {
                    $skipped++;
                    continue;
                }

                $start =
                    $this->parseDateTime(
                        $event['DTSTART']
                    );

                if (!$start) {
                    $skipped++;
                    continue;
                }

                $end =
                    !empty($event['DTEND'])
                    ? $this->parseDateTime(
                        $event['DTEND']
                    )
                    : null;

                $uid =
                    trim(
                        $event['UID']['value']
                    );

                $summary =
                    $this->unescapeText(
                        $event['SUMMARY']['value']
                        ?? 'Untitled event'
                    );

                $description =
                    isset($event['DESCRIPTION'])
                    ? $this->unescapeText(
                        $event['DESCRIPTION']['value']
                    )
                    : null;

                $location =
                    isset($event['LOCATION'])
                    ? $this->unescapeText(
                        $event['LOCATION']['value']
                    )
                    : null;

                $sourceUrl =
                    $event['URL']['value']
                    ?? null;

                $period =
                    (int) $start->format('G') < 15
                    ? 'morning'
                    : 'evening';

                $newData = [
                    'summary' =>
                        substr(
                            $summary,
                            0,
                            255
                        ),
                    'description' =>
                        $description,
                    'location' =>
                        $location
                        ? substr(
                            $location,
                            0,
                            255
                        )
                        : null,
                    'source_url' =>
                        $sourceUrl,
                    'start_local' =>
                        $start->format(
                            'Y-m-d H:i:s'
                        ),
                    'end_local' =>
                        $end
                        ? $end->format(
                            'Y-m-d H:i:s'
                        )
                        : null,
                    'schedule_date' =>
                        $start->format(
                            'Y-m-d'
                        ),
                    'period' =>
                        $period,
                ];

                $existing =
                    $this->findEvent(
                        $sourceId,
                        $uid
                    );

                if (!$existing) {

                    $eventId =
                        $this->insertEvent(
                            $sourceId,
                            $uid,
                            $newData
                        );

                    $inserted++;

                    $this->logChange(
                        $eventId,
                        $runId,
                        'created',
                        null,
                        $this->describeEvent(
                            $newData
                        )
                    );

                } else {

                    $eventId =
                        (int) $existing['id'];

                    $wasMissing =
                        ($existing['sync_status'] ?? 'active')
                        === 'missing';

                    $wasMoved =
                        $existing['schedule_date']
                            !== $newData['schedule_date']
                        ||
                        $existing['period']
                            !== $newData['period'];

                    $isChanged =
                        $this->eventChanged(
                            $existing,
                            $newData
                        );

                    if ($wasMissing) {
                        $restored++;

                        $this->logChange(
                            $eventId,
                            $runId,
                            'restored',
                            $this->describeEvent(
                                $existing
                            ),
                            $this->describeEvent(
                                $newData
                            )
                        );
                    }

                    if ($isChanged) {

                        if ($wasMoved) {
                            $moved++;

                            $this->logChange(
                                $eventId,
                                $runId,
                                'moved',
                                $this->describeEvent(
                                    $existing
                                ),
                                $this->describeEvent(
                                    $newData
                                )
                            );
                        } else {
                            $this->logChange(
                                $eventId,
                                $runId,
                                'changed',
                                $this->describeEvent(
                                    $existing
                                ),
                                $this->describeEvent(
                                    $newData
                                )
                            );
                        }

                        $changed++;

                    } else {
                        $unchanged++;
                    }

                    $this->updateEvent(
                        $eventId,
                        $newData
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Keep linked split events attached to the imported event
                |--------------------------------------------------------------------------
                |
                | Availability remains keyed by split_event_id, so it is untouched.
                |
                */

                $stmt =
                    $this->pdo->prepare("
                        UPDATE schedule_split_events
                        SET schedule_date = ?,
                            period = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE calendar_event_id = ?
                    ");

                $stmt->execute([
                    $newData['schedule_date'],
                    $newData['period'],
                    $eventId
                ]);
            }

            $missing =
                $this->countMissing(
                    $sourceId
                );

            if ($fullSync) {

                $stmt =
                    $this->pdo->prepare("
                        SELECT
                            id,
                            summary,
                            start_local,
                            end_local,
                            schedule_date,
                            period
                        FROM calendar_events
                        WHERE source_id = ?
                          AND sync_status = 'missing'
                          AND missing_since >=
                              DATE_SUB(
                                  CURRENT_TIMESTAMP,
                                  INTERVAL 1 MINUTE
                              )
                    ");

                $stmt->execute([
                    $sourceId
                ]);

                foreach (
                    $stmt->fetchAll()
                    as $missingEvent
                ) {
                    $this->logChange(
                        (int) $missingEvent['id'],
                        $runId,
                        'missing',
                        $this->describeEvent(
                            $missingEvent
                        ),
                        null
                    );
                }
            }

            $this->finishSyncRun(
                $runId,
                'ok',
                [
                    'total' => count($events),
                    'inserted' => $inserted,
                    'changed' => $changed,
                    'unchanged' => $unchanged,
                    'missing' => $missing,
                    'moved' => $moved,
                ],
                null
            );

            $stmt =
                $this->pdo->prepare("
                    UPDATE calendar_sources
                    SET last_synced_at = CURRENT_TIMESTAMP,
                        last_sync_status = 'ok',
                        last_sync_message = NULL
                    WHERE id = ?
                ");

            $stmt->execute([
                $sourceId
            ]);

            $this->pdo->commit();

            return [
                'total' =>
                    count($events),
                'inserted' =>
                    $inserted,
                'changed' =>
                    $changed,
                'updated' =>
                    $changed,
                'unchanged' =>
                    $unchanged,
                'moved' =>
                    $moved,
                'restored' =>
                    $restored,
                'missing' =>
                    $missing,
                'skipped' =>
                    $skipped,
                'sync_run_id' =>
                    $runId,
            ];

        } catch (Throwable $e) {

            if (
                $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            /*
            | Failure logging is deliberately outside the rolled-back transaction.
            */

            try {
                $sourceId =
                    $this->ensureSource(
                        $sourceKey,
                        $sourceName,
                        $feedUrl
                    );

                $stmt =
                    $this->pdo->prepare("
                        UPDATE calendar_sources
                        SET last_sync_status = 'error',
                            last_sync_message = ?
                        WHERE id = ?
                    ");

                $stmt->execute([
                    substr(
                        $e->getMessage(),
                        0,
                        2000
                    ),
                    $sourceId
                ]);
            } catch (Throwable) {
                // Do not hide the original sync error.
            }

            throw $e;
        }
    }

    private function findEvent(
        int $sourceId,
        string $uid
    ): ?array {
        $stmt =
            $this->pdo->prepare("
                SELECT *
                FROM calendar_events
                WHERE source_id = ?
                  AND source_uid = ?
                LIMIT 1
            ");

        $stmt->execute([
            $sourceId,
            $uid
        ]);

        $row =
            $stmt->fetch();

        return $row ?: null;
    }

    private function insertEvent(
        int $sourceId,
        string $uid,
        array $data
    ): int {
        $stmt =
            $this->pdo->prepare("
                INSERT INTO calendar_events
                (
                    source_id,
                    source_uid,
                    summary,
                    description,
                    location,
                    source_url,
                    start_local,
                    end_local,
                    schedule_date,
                    period,
                    sync_status,
                    last_seen_at,
                    missing_since
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'active',
                    CURRENT_TIMESTAMP,
                    NULL
                )
            ");

        $stmt->execute([
            $sourceId,
            $uid,
            $data['summary'],
            $data['description'],
            $data['location'],
            $data['source_url'],
            $data['start_local'],
            $data['end_local'],
            $data['schedule_date'],
            $data['period'],
        ]);

        return (int)
            $this->pdo->lastInsertId();
    }

    private function updateEvent(
        int $eventId,
        array $data
    ): void {
        $stmt =
            $this->pdo->prepare("
                UPDATE calendar_events
                SET summary = ?,
                    description = ?,
                    location = ?,
                    source_url = ?,
                    start_local = ?,
                    end_local = ?,
                    schedule_date = ?,
                    period = ?,
                    sync_status = 'active',
                    last_seen_at = CURRENT_TIMESTAMP,
                    missing_since = NULL,
                    imported_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

        $stmt->execute([
            $data['summary'],
            $data['description'],
            $data['location'],
            $data['source_url'],
            $data['start_local'],
            $data['end_local'],
            $data['schedule_date'],
            $data['period'],
            $eventId,
        ]);
    }

    private function eventChanged(
        array $existing,
        array $newData
    ): bool {
        foreach (
            [
                'summary',
                'description',
                'location',
                'source_url',
                'start_local',
                'end_local',
                'schedule_date',
                'period',
            ]
            as $field
        ) {
            if (
                (string) ($existing[$field] ?? '')
                !==
                (string) ($newData[$field] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    private function describeEvent(
        array $event
    ): string {
        return json_encode(
            [
                'summary' =>
                    $event['summary'] ?? null,
                'start_local' =>
                    $event['start_local'] ?? null,
                'end_local' =>
                    $event['end_local'] ?? null,
                'schedule_date' =>
                    $event['schedule_date'] ?? null,
                'period' =>
                    $event['period'] ?? null,
            ],
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
        );
    }

    private function countMissing(
        int $sourceId
    ): int {
        $stmt =
            $this->pdo->prepare("
                SELECT COUNT(*)
                FROM calendar_events
                WHERE source_id = ?
                  AND sync_status = 'missing'
            ");

        $stmt->execute([
            $sourceId
        ]);

        return (int)
            $stmt->fetchColumn();
    }

    private function startSyncRun(
        int $sourceId
    ): int {
        $stmt =
            $this->pdo->prepare("
                INSERT INTO calendar_sync_runs
                (
                    source_id,
                    status
                )
                VALUES (?, 'running')
            ");

        $stmt->execute([
            $sourceId
        ]);

        return (int)
            $this->pdo->lastInsertId();
    }

    private function finishSyncRun(
        int $runId,
        string $status,
        array $stats,
        ?string $message
    ): void {
        $stmt =
            $this->pdo->prepare("
                UPDATE calendar_sync_runs
                SET finished_at = CURRENT_TIMESTAMP,
                    status = ?,
                    total_events = ?,
                    inserted_events = ?,
                    changed_events = ?,
                    unchanged_events = ?,
                    missing_events = ?,
                    moved_events = ?,
                    message = ?
                WHERE id = ?
            ");

        $stmt->execute([
            $status,
            (int) ($stats['total'] ?? 0),
            (int) ($stats['inserted'] ?? 0),
            (int) ($stats['changed'] ?? 0),
            (int) ($stats['unchanged'] ?? 0),
            (int) ($stats['missing'] ?? 0),
            (int) ($stats['moved'] ?? 0),
            $message,
            $runId,
        ]);
    }

    private function logChange(
        int $eventId,
        ?int $runId,
        string $type,
        ?string $oldValue,
        ?string $newValue
    ): void {
        $stmt =
            $this->pdo->prepare("
                INSERT INTO calendar_event_changes
                (
                    calendar_event_id,
                    sync_run_id,
                    change_type,
                    old_value,
                    new_value
                )
                VALUES (?, ?, ?, ?, ?)
            ");

        $stmt->execute([
            $eventId,
            $runId,
            $type,
            $oldValue,
            $newValue
        ]);
    }

    private function ensureSource(
        string $sourceKey,
        string $sourceName,
        ?string $feedUrl
    ): int {
        $stmt =
            $this->pdo->prepare("
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
                    feed_url =
                        COALESCE(
                            VALUES(feed_url),
                            feed_url
                        ),
                    timezone = VALUES(timezone)
            ");

        $stmt->execute([
            $sourceKey,
            $sourceName,
            $feedUrl,
            $this->timezone
        ]);

        $stmt =
            $this->pdo->prepare("
                SELECT id
                FROM calendar_sources
                WHERE source_key = ?
                LIMIT 1
            ");

        $stmt->execute([
            $sourceKey
        ]);

        return (int)
            $stmt->fetchColumn();
    }

    private function parseEvents(
        string $icsText
    ): array {
        $icsText =
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $icsText
            );

        $icsText =
            preg_replace(
                "/\n[ \t]/",
                '',
                $icsText
            );

        $lines =
            explode(
                "\n",
                $icsText
            );

        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $line =
                trim(
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
                !is_array($current)
                ||
                !str_contains(
                    $line,
                    ':'
                )
            ) {
                continue;
            }

            [$left, $value] =
                explode(
                    ':',
                    $line,
                    2
                );

            $parts =
                explode(
                    ';',
                    $left
                );

            $name =
                strtoupper(
                    array_shift(
                        $parts
                    )
                );

            $params = [];

            foreach ($parts as $part) {
                if (
                    !str_contains(
                        $part,
                        '='
                    )
                ) {
                    continue;
                }

                [$key, $paramValue] =
                    explode(
                        '=',
                        $part,
                        2
                    );

                $params[
                    strtoupper($key)
                ] =
                    trim(
                        $paramValue,
                        '"'
                    );
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
            trim(
                $property['value']
                ?? ''
            );

        if ($value === '') {
            return null;
        }

        $targetTimezone =
            new DateTimeZone(
                $this->timezone
            );

        try {
            if (
                str_ends_with(
                    $value,
                    'Z'
                )
            ) {
                $date =
                    DateTimeImmutable::createFromFormat(
                        '!Ymd\THis\Z',
                        $value,
                        new DateTimeZone(
                            'UTC'
                        )
                    );
            } else {
                $timezoneName =
                    $property['params']['TZID']
                    ?? $this->timezone;

                $date =
                    DateTimeImmutable::createFromFormat(
                        '!Ymd\THis',
                        $value,
                        new DateTimeZone(
                            $timezoneName
                        )
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
