<?php

declare(strict_types=1);

namespace Tms\Domain\Checklist;

use DomainException;
use PDO;
use Throwable;

final class ChecklistRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<ChecklistItemRecord> */
    public function listForTask(int $userId, int $taskId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.task_id, i.item_text, i.is_completed, i.sort_order, i.created_at, i.updated_at
             FROM task_checklist_items i
             INNER JOIN tasks t ON t.id = i.task_id
             WHERE i.task_id = :task_id
               AND ' . $this->taskAccessCondition('t', 'list_') . '
             ORDER BY i.sort_order ASC, i.id ASC'
        );
        $stmt->execute(['task_id' => $taskId] + $this->taskAccessParams($userId, 'list_'));

        $items = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $items[] = $this->hydrate($row);
            }
        }
        return $items;
    }

    public function findForTask(int $userId, int $taskId, int $itemId): ?ChecklistItemRecord
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.task_id, i.item_text, i.is_completed, i.sort_order, i.created_at, i.updated_at
             FROM task_checklist_items i
             INNER JOIN tasks t ON t.id = i.task_id
             WHERE i.id = :item_id
               AND i.task_id = :task_id
               AND ' . $this->taskAccessCondition('t', 'find_') . '
             LIMIT 1'
        );
        $stmt->execute([
            'item_id' => $itemId,
            'task_id' => $taskId,
        ] + $this->taskAccessParams($userId, 'find_'));

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createForTask(int $userId, int $taskId, string $text): int
    {
        $text = $this->normalizeText($text);
        $this->assertTaskAccessible($userId, $taskId);

        $next = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 10
             FROM task_checklist_items WHERE task_id = :task_id'
        );
        $next->execute(['task_id' => $taskId]);
        $sortOrder = max(10, (int) $next->fetchColumn());

        $stmt = $this->db->prepare(
            'INSERT INTO task_checklist_items (
                task_id, item_text, is_completed, sort_order, created_at, updated_at
             ) VALUES (
                :task_id, :item_text, 0, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'item_text' => $text,
            'sort_order' => $sortOrder,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateText(int $userId, int $taskId, int $itemId, string $text): bool
    {
        if ($this->findForTask($userId, $taskId, $itemId) === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE task_checklist_items
             SET item_text = :item_text, updated_at = CURRENT_TIMESTAMP
             WHERE id = :item_id AND task_id = :task_id'
        );
        $stmt->execute([
            'item_text' => $this->normalizeText($text),
            'item_id' => $itemId,
            'task_id' => $taskId,
        ]);
        return true;
    }

    public function setCompleted(int $userId, int $taskId, int $itemId, bool $completed): bool
    {
        if ($this->findForTask($userId, $taskId, $itemId) === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE task_checklist_items
             SET is_completed = :completed, updated_at = CURRENT_TIMESTAMP
             WHERE id = :item_id AND task_id = :task_id'
        );
        $stmt->execute([
            'completed' => $completed ? 1 : 0,
            'item_id' => $itemId,
            'task_id' => $taskId,
        ]);
        return true;
    }

    public function move(int $userId, int $taskId, int $itemId, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new DomainException('Unsupported checklist move direction.');
        }
        $items = $this->listForTask($userId, $taskId);
        $index = null;
        foreach ($items as $position => $item) {
            if ($item->id === $itemId) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($items[$target])) {
            return true;
        }

        $current = $items[$index];
        $other = $items[$target];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE task_checklist_items SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND task_id = :task_id'
            );
            $stmt->execute([
                'sort_order' => $other->sortOrder,
                'id' => $current->id,
                'task_id' => $taskId,
            ]);
            $stmt->execute([
                'sort_order' => $current->sortOrder,
                'id' => $other->id,
                'task_id' => $taskId,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return true;
    }

    public function delete(int $userId, int $taskId, int $itemId): bool
    {
        if ($this->findForTask($userId, $taskId, $itemId) === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM task_checklist_items WHERE id = :item_id AND task_id = :task_id'
        );
        $stmt->execute(['item_id' => $itemId, 'task_id' => $taskId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, array{total:int,completed:int}>
     */
    public function progressForTasks(int $userId, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            $taskIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($taskIds === []) {
            return [];
        }

        $params = $this->taskAccessParams($userId, 'progress_');
        $placeholders = [];
        foreach ($taskIds as $index => $taskId) {
            $key = 'task_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $taskId;
        }

        $stmt = $this->db->prepare(
            'SELECT i.task_id, COUNT(*) AS total, SUM(CASE WHEN i.is_completed = 1 THEN 1 ELSE 0 END) AS completed
             FROM task_checklist_items i
             INNER JOIN tasks t ON t.id = i.task_id
             WHERE i.task_id IN (' . implode(', ', $placeholders) . ')
               AND ' . $this->taskAccessCondition('t', 'progress_') . '
             GROUP BY i.task_id'
        );
        $stmt->execute($params);

        $progress = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $progress[(int) $row['task_id']] = [
                    'total' => (int) $row['total'],
                    'completed' => (int) $row['completed'],
                ];
            }
        }
        return $progress;
    }

    private function assertTaskAccessible(int $userId, int $taskId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM tasks t
             WHERE t.id = :task_id
               AND ' . $this->taskAccessCondition('t', 'access_') . '
             LIMIT 1'
        );
        $stmt->execute(['task_id' => $taskId] + $this->taskAccessParams($userId, 'access_'));
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('Task is unavailable.');
        }
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 500) {
            throw new DomainException('Checklist item must contain 1-500 characters.');
        }
        return $text;
    }

    private function taskAccessCondition(string $alias, string $prefix): string
    {
        return sprintf(
            '((%1$s.project_id IS NULL AND %1$s.created_by = :%2$spersonal_task_user)
              OR EXISTS (
                  SELECT 1
                  FROM projects access_project
                  LEFT JOIN team_members access_member
                    ON access_member.team_id = access_project.owner_team_id
                   AND access_member.user_id = :%2$steam_user
                  WHERE access_project.id = %1$s.project_id
                    AND (
                        (access_project.owner_user_id = :%2$spersonal_project_user
                         AND access_project.owner_team_id IS NULL)
                        OR
                        (access_project.owner_user_id IS NULL
                         AND access_project.owner_team_id IS NOT NULL
                         AND access_member.user_id IS NOT NULL)
                    )
              ))',
            $alias,
            $prefix,
        );
    }

    /** @return array<string, int> */
    private function taskAccessParams(int $userId, string $prefix): array
    {
        return [
            $prefix . 'personal_task_user' => $userId,
            $prefix . 'team_user' => $userId,
            $prefix . 'personal_project_user' => $userId,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ChecklistItemRecord
    {
        return new ChecklistItemRecord(
            id: (int) $row['id'],
            taskId: (int) $row['task_id'],
            text: (string) $row['item_text'],
            isCompleted: (bool) $row['is_completed'],
            sortOrder: (int) $row['sort_order'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
