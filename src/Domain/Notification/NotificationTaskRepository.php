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
        return $this->between($userId, 'deadline', $start, $end, $priority);
    }

    /** @return list<array{id:int,title:string,deadline:string,priority:int}> */
    public function scheduledBetween(int $userId, string $start, string $end, ?int $priority = null): array
    {
        return $this->between($userId, 'scheduled_at', $start, $end, $priority);
    }

    /** @return list<array{id:int,title:string,deadline:string,priority:int}> */
    public function overdue(int $userId, string $now): array
    {
        $sql = $this->selectSql('deadline')
            . ' AND t.deadline IS NOT NULL AND t.deadline < :now_at'
            . ' ORDER BY t.deadline ASC, t.priority DESC, t.id ASC';

        return $this->fetch($sql, $this->accessParams($userId) + ['now_at' => $now]);
    }

    /** @return list<array{id:int,title:string,deadline:string,priority:int}> */
    private function between(
        int $userId,
        string $column,
        string $start,
        string $end,
        ?int $priority,
    ): array {
        if (!in_array($column, ['deadline', 'scheduled_at'], true)) {
            return [];
        }

        $sql = $this->selectSql($column)
            . ' AND t.' . $column . ' IS NOT NULL'
            . ' AND t.' . $column . ' >= :start_at AND t.' . $column . ' < :end_at';
        $params = $this->accessParams($userId) + ['start_at' => $start, 'end_at' => $end];
        if ($priority !== null) {
            $sql .= ' AND t.priority = :priority';
            $params['priority'] = $priority;
        }
        $sql .= ' ORDER BY t.' . $column . ' ASC, t.priority DESC, t.id ASC';

        return $this->fetch($sql, $params);
    }

    private function selectSql(string $column): string
    {
        return 'SELECT t.id, t.title, t.' . $column . ' AS deadline, t.priority
                FROM tasks t
                LEFT JOIN statuses s ON s.id = t.status_id
                LEFT JOIN projects p ON p.id = t.project_id
                LEFT JOIN team_members tm
                  ON tm.team_id = p.owner_team_id
                 AND tm.user_id = :access_team_user
                WHERE (t.created_by = :interest_owner OR t.assignee_user_id = :interest_assignee)
                  AND (
                       (t.project_id IS NULL AND t.created_by = :access_personal_task_user)
                       OR
                       (p.owner_user_id = :access_personal_project_user AND p.owner_team_id IS NULL)
                       OR
                       (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
                  )
                  AND (s.is_completion = 0 OR s.id IS NULL)';
    }

    /** @return array<string, int> */
    private function accessParams(int $userId): array
    {
        return [
            'interest_owner' => $userId,
            'interest_assignee' => $userId,
            'access_team_user' => $userId,
            'access_personal_task_user' => $userId,
            'access_personal_project_user' => $userId,
        ];
    }

    /**
     * @param array<string, mixed> $params
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
