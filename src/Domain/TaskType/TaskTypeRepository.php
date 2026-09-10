<?php

declare(strict_types=1);

namespace Tms\Domain\TaskType;

use DomainException;
use PDO;
use Throwable;

final class TaskTypeRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<TaskTypeRecord>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, description, sort_order
             FROM task_types
             WHERE user_id = :user_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    public function findForUser(int $userId, int $typeId): ?TaskTypeRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, description, sort_order
             FROM task_types
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $typeId, 'user_id' => $userId]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createForUser(int $userId, string $name, string $description = ''): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Task type name cannot be empty.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO task_types (user_id, name, description, sort_order, created_at, updated_at)
             VALUES (:user_id, :name, :description, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'description' => trim($description),
            'sort_order' => $this->nextSortOrder($userId),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateForUser(int $userId, int $typeId, string $name, string $description): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Task type name cannot be empty.');
        }

        if ($this->findForUser($userId, $typeId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE task_types
             SET name = :name, description = :description, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => trim($description),
            'id' => $typeId,
            'user_id' => $userId,
        ]);
        return true;
    }

    public function deleteForUser(int $userId, int $typeId): bool
    {
        if ($this->findForUser($userId, $typeId) === null) {
            return false;
        }

        $used = $this->db->prepare(
            'SELECT 1 FROM tasks WHERE type_id = :type_id AND created_by = :user_id LIMIT 1'
        );
        $used->execute(['type_id' => $typeId, 'user_id' => $userId]);
        if ($used->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM task_types WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $typeId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $typeIds
     */
    public function reorderForUser(int $userId, array $typeIds): bool
    {
        $owned = array_map(
            static fn (TaskTypeRecord $record): int => $record->id,
            $this->listForUser($userId),
        );
        sort($owned);
        $requested = $typeIds;
        sort($requested);

        if ($owned !== $requested || count($typeIds) !== count(array_unique($typeIds))) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE task_types SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            foreach ($typeIds as $index => $typeId) {
                $stmt->execute([
                    'sort_order' => $index + 1,
                    'id' => $typeId,
                    'user_id' => $userId,
                ]);
            }
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function nextSortOrder(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM task_types WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TaskTypeRecord
    {
        return new TaskTypeRecord(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            sortOrder: (int) $row['sort_order'],
        );
    }
}
