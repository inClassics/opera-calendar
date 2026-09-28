<?php

class Assignment
{
    public function __construct(private PDO $pdo) {}

    public function forRange(DateTime $from, DateTime $to): array
    {
        $result = [];
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        $queries = [
            'calendar' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN calendar_events ce ON ce.id = aa.source_id
                WHERE aa.source_type = 'calendar'
                  AND ce.schedule_date BETWEEN ? AND ?
            ",
            'slot' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN schedule_slots ss ON ss.id = aa.source_id
                WHERE aa.source_type = 'slot'
                  AND ss.schedule_date BETWEEN ? AND ?
            ",
            'split' => "
                SELECT aa.source_id, aa.user_id
                FROM activity_assignments aa
                INNER JOIN schedule_split_events se ON se.id = aa.source_id
                WHERE aa.source_type = 'split'
                  AND se.schedule_date BETWEEN ? AND ?
            ",
        ];

        foreach ($queries as $sourceType => $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$f, $t]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$sourceType][(int)$row['source_id']][(int)$row['user_id']] = true;
            }
        }

        return $result;
    }
}
