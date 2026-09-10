<?php

declare(strict_types=1);

namespace Tms\Domain\Task;

use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findForUser(int $userId, int $taskId): ?TaskRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, created_by, title, description, deadline, status_id, type_id, priority, customer_id, created_at, updated_at
             FROM tasks
             WHERE id = :task_id AND created_by = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return list<TaskRecord>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, created_by, title, description, deadline, status_id, type_id, priority, customer_id, created_at, updated_at
             FROM tasks
             WHERE created_by = :user_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $tasks = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $tasks[] = $this->hydrate($row);
            }
        }

        return $tasks;
    }

    public function updateStatusForUser(int $userId, int $taskId, int $statusId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET status_id = :status_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :task_id
               AND created_by = :user_id
               AND EXISTS (
                   SELECT 1
                   FROM statuses
                   WHERE id = :owned_status_id AND user_id = :status_user_id
               )'
        );
        $stmt->execute([
            'status_id' => $statusId,
            'task_id' => $taskId,
            'user_id' => $userId,
            'owned_status_id' => $statusId,
            'status_user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function deleteForUser(int $userId, int $taskId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tasks WHERE id = :task_id AND created_by = :user_id'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TaskRecord
    {
        return new TaskRecord(
            id: (int) $row['id'],
            ownerId: (int) $row['created_by'],
            title: (string) $row['title'],
            description: (string) ($row['description'] ?? ''),
            deadline: $row['deadline'] !== null ? (string) $row['deadline'] : null,
            statusId: $row['status_id'] !== null ? (int) $row['status_id'] : null,
            typeId: $row['type_id'] !== null ? (int) $row['type_id'] : null,
            priority: (int) ($row['priority'] ?? 0),
            customerId: $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
