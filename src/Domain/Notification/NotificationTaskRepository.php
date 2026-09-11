<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use PDO;

final class NotificationTaskRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{id:int,title:string,deadline:string,priority:int}> */
    public function dueBetween(int $userId, string $start, string $end, ?int $priority = null): array
    {
        $sql = 'SELECT t.id, t.title, t.deadline, t.priority
                FROM tasks t
                LEFT JOIN statuses s ON s.id = t.status_id AND s.user_id = t.created_by
                WHERE t.created_by = :user_id
                  AND t.deadline IS NOT NULL
                  AND t.deadline >= :start_at AND t.deadline < :end_at
                  AND (s.is_completion = 0 OR s.id IS NULL)';
        $params = ['user_id' => $userId, 'start_at' => $start, 'end_at' => $end];
        if ($priority !== null) {
            $sql .= ' AND t.priority = :priority';
            $params['priority'] = $priority;
        }
        $sql .= ' ORDER BY t.deadline ASC, t.priority DESC, t.id ASC';
        return $this->fetch($sql, $params);
    }

    /** @return list<array{id:int,title:string,deadline:string,priority:int}> */
    public function overdue(int $userId, string $now): array
    {
        return $this->fetch(
            'SELECT t.id, t.title, t.deadline, t.priority
             FROM tasks t
             LEFT JOIN statuses s ON s.id = t.status_id AND s.user_id = t.created_by
             WHERE t.created_by = :user_id
               AND t.deadline IS NOT NULL AND t.deadline < :now_at
               AND (s.is_completion = 0 OR s.id IS NULL)
             ORDER BY t.deadline ASC, t.priority DESC, t.id ASC',
            ['user_id' => $userId, 'now_at' => $now],
        );
    }

    /** @param array<string, mixed> $params
     * @return list<array{id:int,title:string,deadline:string,priority:int}>
     */
    private function fetch(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            if (!is_array($row) || $row['deadline'] === null) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'deadline' => (string) $row['deadline'],
                'priority' => (int) $row['priority'],
            ];
        }
        return $rows;
    }
}
