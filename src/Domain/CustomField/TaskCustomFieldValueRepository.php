<?php

declare(strict_types=1);

namespace Tms\Domain\CustomField;

use DomainException;
use PDO;
use Throwable;

final class TaskCustomFieldValueRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int, string> */
    public function listForTask(int $userId, int $taskId): array
    {
        $stmt = $this->db->prepare(
            'SELECT field_id, value
             FROM task_custom_field_values
             WHERE task_id = :task_id AND user_id = :user_id'
        );
        $stmt->execute(['task_id' => $taskId, 'user_id' => $userId]);

        $values = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $values[(int) $row['field_id']] = (string) $row['value'];
            }
        }
        return $values;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, array<int, string>>
     */
    public function listForTasks(int $userId, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            $taskIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT task_id, field_id, value
             FROM task_custom_field_values
             WHERE user_id = ? AND task_id IN ({$placeholders})"
        );
        $stmt->execute([$userId, ...$taskIds]);

        $values = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $values[(int) $row['task_id']][(int) $row['field_id']] = (string) $row['value'];
            }
        }
        return $values;
    }

    /** @param array<int, string|null> $values */
    public function replaceForTask(int $userId, int $taskId, array $values): void
    {
        $taskScope = $this->taskScopeForUser($userId, $taskId);
        if ($taskScope === null) {
            throw new DomainException('Task is unavailable.');
        }

        $fieldIds = array_map('intval', array_keys($values));
        if (!$this->fieldsBelongToScope($userId, $taskScope['project_id'], $fieldIds)) {
            throw new DomainException('Custom field is unavailable.');
        }

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare(
                'DELETE FROM task_custom_field_values
                 WHERE task_id = :task_id AND user_id = :user_id'
            );
            $delete->execute(['task_id' => $taskId, 'user_id' => $userId]);

            $insert = $this->db->prepare(
                'INSERT INTO task_custom_field_values (
                    task_id, field_id, user_id, value, created_at, updated_at
                 ) VALUES (
                    :task_id, :field_id, :user_id, :value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            foreach ($values as $fieldId => $value) {
                if ($value === null) {
                    continue;
                }
                $insert->execute([
                    'task_id' => $taskId,
                    'field_id' => $fieldId,
                    'user_id' => $userId,
                    'value' => $value,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{project_id:?int}|null */
    private function taskScopeForUser(int $userId, int $taskId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT project_id
             FROM tasks
             WHERE id = :id AND created_by = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $taskId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
        ];
    }

    /** @param list<int> $fieldIds */
    private function fieldsBelongToScope(int $userId, ?int $projectId, array $fieldIds): bool
    {
        $fieldIds = array_values(array_unique(array_filter(
            $fieldIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($fieldIds === []) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
        if ($projectId === null) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM custom_fields
                 WHERE user_id = ? AND project_id IS NULL
                   AND id IN ({$placeholders})"
            );
            $stmt->execute([$userId, ...$fieldIds]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM custom_fields f
                 INNER JOIN projects p ON p.id = f.project_id
                 WHERE f.user_id IS NULL
                   AND f.project_id = ?
                   AND p.owner_user_id = ?
                   AND p.owner_team_id IS NULL
                   AND f.id IN ({$placeholders})"
            );
            $stmt->execute([$projectId, $userId, ...$fieldIds]);
        }

        return (int) $stmt->fetchColumn() === count($fieldIds);
    }
}
