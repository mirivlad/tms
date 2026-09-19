<?php

declare(strict_types=1);

namespace Tms\Domain\Discussion;

use PDO;

final class DiscussionReadRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, array{count:int,unread:int}>
     */
    public function statsForTasks(int $userId, array $taskIds): array
    {
        $taskIds = $this->positiveIds($taskIds);
        if ($taskIds === []) {
            return [];
        }

        [$in, $params] = $this->inParams('task', $taskIds);
        $params['member_user_id'] = $userId;
        $params['read_user_id'] = $userId;
        $params['actor_user_id'] = $userId;

        $stmt = $this->db->prepare(
            "SELECT c.task_id AS context_id,
                    COUNT(*) AS comment_count,
                    SUM(
                        CASE
                            WHEN c.author_user_id <> :actor_user_id
                             AND c.id > COALESCE(r.last_read_comment_id, 0)
                            THEN 1 ELSE 0
                        END
                    ) AS unread_count
             FROM discussion_comments c
             INNER JOIN tasks t ON t.id = c.task_id
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :member_user_id
             LEFT JOIN discussion_read_markers r
               ON r.user_id = :read_user_id
              AND r.team_id = p.owner_team_id
              AND r.context_type = 'task'
              AND r.context_id = c.task_id
             WHERE c.task_id IN ($in)
               AND c.project_id IS NULL
               AND c.team_id = p.owner_team_id
               AND c.deleted_at IS NULL
             GROUP BY c.task_id"
        );
        $stmt->execute($params);

        $stats = [];
        while (($row = $stmt->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $stats[(int) $row['context_id']] = [
                'count' => (int) $row['comment_count'],
                'unread' => (int) $row['unread_count'],
            ];
        }

        foreach ($taskIds as $taskId) {
            $stats[$taskId] ??= ['count' => 0, 'unread' => 0];
        }

        return $stats;
    }

    /**
     * Project stats include both project-level comments and comments from every task in the project.
     *
     * @param list<int> $projectIds
     * @return array<int, array{count:int,unread:int}>
     */
    public function statsForProjects(int $userId, array $projectIds): array
    {
        $projectIds = $this->positiveIds($projectIds);
        if ($projectIds === []) {
            return [];
        }

        [$in, $params] = $this->inParams('project', $projectIds);
        $params['user_id'] = $userId;

        $stmt = $this->db->prepare(
            "SELECT p.id AS project_id,
                    COUNT(*) AS comment_count,
                    SUM(
                        CASE
                            WHEN c.author_user_id <> :user_id
                             AND c.id > COALESCE(r.last_read_comment_id, 0)
                            THEN 1 ELSE 0
                        END
                    ) AS unread_count
             FROM discussion_comments c
             LEFT JOIN tasks t ON t.id = c.task_id
             INNER JOIN projects p
               ON p.id = CASE WHEN c.project_id IS NOT NULL THEN c.project_id ELSE t.project_id END
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :user_id
             LEFT JOIN discussion_read_markers r
               ON r.user_id = :read_user_id
              AND r.team_id = p.owner_team_id
              AND r.context_type = CASE WHEN c.task_id IS NULL THEN 'project' ELSE 'task' END
              AND r.context_id = CASE WHEN c.task_id IS NULL THEN c.project_id ELSE c.task_id END
             WHERE p.id IN ($in)
               AND c.team_id = p.owner_team_id
               AND c.deleted_at IS NULL
             GROUP BY p.id"
        );
        $stmt->execute($params);

        $stats = [];
        while (($row = $stmt->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $stats[(int) $row['project_id']] = [
                'count' => (int) $row['comment_count'],
                'unread' => (int) $row['unread_count'],
            ];
        }

        foreach ($projectIds as $projectId) {
            $stats[$projectId] ??= ['count' => 0, 'unread' => 0];
        }

        return $stats;
    }

    public function markTaskRead(int $userId, int $taskId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO discussion_read_markers (
                user_id, team_id, context_type, context_id, last_read_comment_id, updated_at
             )
             SELECT :insert_user_id, p.owner_team_id, 'task', t.id, COALESCE(MAX(c.id), 0), CURRENT_TIMESTAMP
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :member_user_id
             LEFT JOIN discussion_comments c
               ON c.task_id = t.id
              AND c.project_id IS NULL
              AND c.team_id = p.owner_team_id
             WHERE t.id = :task_id
             GROUP BY t.id
             ON DUPLICATE KEY UPDATE
                last_read_comment_id = GREATEST(last_read_comment_id, VALUES(last_read_comment_id)),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'insert_user_id' => $userId,
            'member_user_id' => $userId,
            'task_id' => $taskId,
        ]);
    }

    public function markProjectRead(int $userId, int $projectId): void
    {
        $this->db->beginTransaction();
        try {
            $project = $this->db->prepare(
                "INSERT INTO discussion_read_markers (
                    user_id, team_id, context_type, context_id, last_read_comment_id, updated_at
                 )
                 SELECT :insert_user_id, p.owner_team_id, 'project', p.id, COALESCE(MAX(c.id), 0), CURRENT_TIMESTAMP
                 FROM projects p
                 INNER JOIN team_members tm
                   ON tm.team_id = p.owner_team_id
                  AND tm.user_id = :member_user_id
                 LEFT JOIN discussion_comments c
                   ON c.project_id = p.id
                  AND c.task_id IS NULL
                  AND c.team_id = p.owner_team_id
                 WHERE p.id = :project_id
                 GROUP BY p.id
                 ON DUPLICATE KEY UPDATE
                    last_read_comment_id = GREATEST(last_read_comment_id, VALUES(last_read_comment_id)),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $project->execute([
                'insert_user_id' => $userId,
                'member_user_id' => $userId,
                'project_id' => $projectId,
            ]);

            $tasks = $this->db->prepare(
                "INSERT INTO discussion_read_markers (
                    user_id, team_id, context_type, context_id, last_read_comment_id, updated_at
                 )
                 SELECT :insert_user_id, p.owner_team_id, 'task', t.id, COALESCE(MAX(c.id), 0), CURRENT_TIMESTAMP
                 FROM tasks t
                 INNER JOIN projects p ON p.id = t.project_id
                 INNER JOIN team_members tm
                   ON tm.team_id = p.owner_team_id
                  AND tm.user_id = :user_id
                 LEFT JOIN discussion_comments c
                   ON c.task_id = t.id
                  AND c.project_id IS NULL
                  AND c.team_id = p.owner_team_id
                 WHERE p.id = :project_id
                 GROUP BY t.id
                 ON DUPLICATE KEY UPDATE
                    last_read_comment_id = GREATEST(last_read_comment_id, VALUES(last_read_comment_id)),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $tasks->execute([
                'insert_user_id' => $userId,
                'member_user_id' => $userId,
                'project_id' => $projectId,
            ]);

            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function positiveIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        return array_values($clean);
    }

    /**
     * @param list<int> $ids
     * @return array{0:string,1:array<string,int>}
     */
    private function inParams(string $prefix, array $ids): array
    {
        $parts = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $parts[] = ':' . $key;
            $params[$key] = $id;
        }
        return [implode(', ', $parts), $params];
    }
}
