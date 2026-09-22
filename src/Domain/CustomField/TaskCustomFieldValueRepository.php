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
            'SELECT v.field_id, v.value
             FROM task_custom_field_values v
             INNER JOIN tasks t ON t.id = v.task_id
             WHERE v.task_id = :task_id
               AND ' . $this->taskAccessCondition('t', 'one_')
        );
        $stmt->execute(['task_id' => $taskId] + $this->taskAccessParams($userId, 'one_'));

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

        $params = $this->taskAccessParams($userId, 'many_');
        $placeholders = [];
        foreach ($taskIds as $index => $taskId) {
            $key = 'task_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $taskId;
        }
        $stmt = $this->db->prepare(
            'SELECT v.task_id, v.field_id, v.value
             FROM task_custom_field_values v
             INNER JOIN tasks t ON t.id = v.task_id
             WHERE v.task_id IN (' . implode(', ', $placeholders) . ')
               AND ' . $this->taskAccessCondition('t', 'many_')
        );
        $stmt->execute($params);

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
                 WHERE task_id = :task_id AND user_id = :owner_user_id'
            );
            $delete->execute([
                'task_id' => $taskId,
                'owner_user_id' => $taskScope['owner_id'],
            ]);

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
                    'user_id' => $taskScope['owner_id'],
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

    public function cloneForTask(int $userId, int $sourceTaskId, int $targetTaskId): void
    {
        $sourceScope = $this->taskScopeForUser($userId, $sourceTaskId);
        $targetScope = $this->taskScopeForUser($userId, $targetTaskId);
        if ($sourceScope === null || $targetScope === null
            || $sourceScope['project_id'] !== $targetScope['project_id']) {
            throw new DomainException('Task custom-field scope changed during recurrence.');
        }

        $values = $this->listForTask($userId, $sourceTaskId);
        if ($values === []) {
            return;
        }
        if (!$this->fieldsBelongToScope($userId, $targetScope['project_id'], array_keys($values))) {
            throw new DomainException('Custom field is unavailable.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO task_custom_field_values (
                task_id, field_id, user_id, value, created_at, updated_at
             ) VALUES (
                :task_id, :field_id, :user_id, :value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        foreach ($values as $fieldId => $value) {
            $insert->execute([
                'task_id' => $targetTaskId,
                'field_id' => $fieldId,
                'user_id' => $targetScope['owner_id'],
                'value' => $value,
            ]);
        }
    }

    /** @return array{project_id:?int,owner_id:int}|null */
    private function taskScopeForUser(int $userId, int $taskId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.project_id, t.created_by
             FROM tasks t
             WHERE t.id = :id
               AND ' . $this->taskAccessCondition('t', 'scope_') . '
             LIMIT 1'
        );
        $stmt->execute(['id' => $taskId] + $this->taskAccessParams($userId, 'scope_'));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'owner_id' => (int) $row['created_by'],
        ];
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
                 LEFT JOIN team_members tm
                   ON tm.team_id = p.owner_team_id
                  AND tm.user_id = ?
                 WHERE f.user_id IS NULL
                   AND f.project_id = ?
                   AND (
                        (p.owner_user_id = ? AND p.owner_team_id IS NULL)
                        OR
                        (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
                   )
                   AND f.id IN ({$placeholders})"
            );
            $stmt->execute([$userId, $projectId, $userId, ...$fieldIds]);
        }

        return (int) $stmt->fetchColumn() === count($fieldIds);
    }
}
