<?php

declare(strict_types=1);

namespace Tms\Domain\Task;

use DomainException;
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
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<TaskRecord> */
    public function listForUser(int $userId): array
    {
        return $this->listFilteredForUser($userId);
    }

    /** @return list<TaskRecord> */
    public function listFilteredForUser(
        int $userId,
        ?int $statusId = null,
        ?int $typeId = null,
        ?int $customerId = null,
        ?int $priority = null,
        string $query = '',
        bool $overdue = false,
    ): array {
        $sql = 'SELECT t.id, t.created_by, t.title, t.description, t.deadline, t.status_id, t.type_id,
                       t.priority, t.customer_id, t.created_at, t.updated_at
                FROM tasks t';
        if ($overdue) {
            $sql .= ' LEFT JOIN statuses s ON s.id = t.status_id AND s.user_id = t.created_by';
        }
        $sql .= ' WHERE t.created_by = :user_id';
        $params = ['user_id' => $userId];

        if ($statusId !== null) {
            $sql .= ' AND t.status_id = :status_id';
            $params['status_id'] = $statusId;
        }
        if ($typeId !== null) {
            $sql .= ' AND t.type_id = :type_id';
            $params['type_id'] = $typeId;
        }
        if ($customerId !== null) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($priority !== null) {
            $this->assertPriority($priority);
            $sql .= ' AND t.priority = :priority';
            $params['priority'] = $priority;
        }

        $query = trim($query);
        if ($query !== '') {
            $sql .= " AND (t.title LIKE :query ESCAPE '!' OR t.description LIKE :query ESCAPE '!')";
            $params['query'] = '%' . $this->escapeLike($query) . '%';
        }

        if ($overdue) {
            $sql .= ' AND t.deadline IS NOT NULL AND t.deadline < CURRENT_TIMESTAMP
                      AND (s.is_completion = 0 OR s.id IS NULL)';
        }

        $sql .= ' ORDER BY CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END ASC,
                          t.deadline ASC, t.priority DESC, t.updated_at DESC, t.id DESC';

        return $this->fetchTasks($sql, $params);
    }

    /** @return list<TaskRecord> */
    public function listCalendarForUser(
        int $userId,
        string $rangeStart,
        string $rangeEnd,
        string $mode = 'deadlines_only',
        ?int $statusId = null,
        ?int $typeId = null,
        ?int $customerId = null,
        ?int $priority = null,
    ): array {
        if (!in_array($mode, ['deadlines_only', 'no_deadlines', 'all'], true)) {
            throw new DomainException('Unsupported calendar mode.');
        }
        if ($priority !== null) {
            $this->assertPriority($priority);
        }

        $sql = 'SELECT t.id, t.created_by, t.title, t.description, t.deadline, t.status_id, t.type_id,
                       t.priority, t.customer_id, t.created_at, t.updated_at
                FROM tasks t
                WHERE t.created_by = :user_id AND ';
        $params = [
            'user_id' => $userId,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
        ];

        if ($mode === 'deadlines_only') {
            $sql .= '(t.deadline >= :range_start AND t.deadline < :range_end)';
        } elseif ($mode === 'no_deadlines') {
            $sql .= '(t.deadline IS NULL AND t.created_at >= :range_start AND t.created_at < :range_end)';
        } else {
            $sql .= '((t.deadline IS NOT NULL AND t.deadline >= :range_start AND t.deadline < :range_end)
                     OR (t.deadline IS NULL AND t.created_at >= :range_start AND t.created_at < :range_end))';
        }

        if ($statusId !== null) {
            $sql .= ' AND t.status_id = :status_id';
            $params['status_id'] = $statusId;
        }
        if ($typeId !== null) {
            $sql .= ' AND t.type_id = :type_id';
            $params['type_id'] = $typeId;
        }
        if ($customerId !== null) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($priority !== null) {
            $sql .= ' AND t.priority = :priority';
            $params['priority'] = $priority;
        }

        $sql .= ' ORDER BY COALESCE(t.deadline, t.created_at) ASC, t.priority DESC, t.id ASC';
        return $this->fetchTasks($sql, $params);
    }

    public function createForUser(
        int $userId,
        string $title,
        string $description,
        ?string $deadline,
        int $statusId,
        ?int $typeId,
        int $priority,
        ?int $customerId,
    ): int {
        $title = $this->validateTitle($title);
        $this->assertPriority($priority);
        $this->assertOwnedMetadata($userId, $statusId, $typeId, $customerId);

        $stmt = $this->db->prepare(
            'INSERT INTO tasks (
                created_by, title, description, deadline, status_id, type_id, priority, customer_id,
                created_at, updated_at
             ) VALUES (
                :user_id, :title, :description, :deadline, :status_id, :type_id, :priority, :customer_id,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'description' => trim($description),
            'deadline' => $deadline,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'priority' => $priority,
            'customer_id' => $customerId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateForUser(
        int $userId,
        int $taskId,
        string $title,
        string $description,
        ?string $deadline,
        int $statusId,
        ?int $typeId,
        int $priority,
        ?int $customerId,
    ): bool {
        if ($this->findForUser($userId, $taskId) === null) {
            return false;
        }

        $title = $this->validateTitle($title);
        $this->assertPriority($priority);
        $this->assertOwnedMetadata($userId, $statusId, $typeId, $customerId);

        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET title = :title,
                 description = :description,
                 deadline = :deadline,
                 status_id = :status_id,
                 type_id = :type_id,
                 priority = :priority,
                 customer_id = :customer_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :task_id AND created_by = :user_id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => trim($description),
            'deadline' => $deadline,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'priority' => $priority,
            'customer_id' => $customerId,
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
        return true;
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
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :task_id AND created_by = :user_id');
        $stmt->execute(['task_id' => $taskId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    private function assertOwnedMetadata(int $userId, int $statusId, ?int $typeId, ?int $customerId): void
    {
        if (!$this->ownedReferenceExists('statuses', $userId, $statusId)) {
            throw new DomainException('Selected status does not belong to the current user.');
        }
        if ($typeId !== null && !$this->ownedReferenceExists('task_types', $userId, $typeId)) {
            throw new DomainException('Selected task type does not belong to the current user.');
        }
        if ($customerId !== null && !$this->ownedReferenceExists('customers', $userId, $customerId)) {
            throw new DomainException('Selected customer does not belong to the current user.');
        }
    }

    private function ownedReferenceExists(string $table, int $userId, int $id): bool
    {
        if (!in_array($table, ['statuses', 'task_types', 'customers'], true)) {
            throw new DomainException('Unsupported task metadata reference.');
        }

        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Task title cannot be empty.');
        }
        if (mb_strlen($title) > 255) {
            throw new DomainException('Task title cannot exceed 255 characters.');
        }
        return $title;
    }

    private function assertPriority(int $priority): void
    {
        if ($priority < 0 || $priority > 3) {
            throw new DomainException('Task priority must be between 0 and 3.');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<TaskRecord>
     */
    private function fetchTasks(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $tasks = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $tasks[] = $this->hydrate($row);
            }
        }
        return $tasks;
    }

    /** @param array<string, mixed> $row */
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
