<?php

declare(strict_types=1);

namespace Tms\Domain\CustomField;

use DomainException;
use PDO;
use Throwable;
use Tms\Domain\Project\ProjectAccessRepository;

final class TaskCustomFieldValueRepository
{
    private readonly ProjectAccessRepository $projectAccess;

    public function __construct(private readonly PDO $db, ?ProjectAccessRepository $projectAccess = null)
    {
        $this->projectAccess = $projectAccess ?? new ProjectAccessRepository($db);
    }

    /** @return array<int, string> */
    public function listForTask(int $userId, int $taskId): array
    {
        if ($this->taskScopeForUser($userId, $taskId) === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT field_id, value
             FROM task_custom_field_values
             WHERE task_id = :task_id'
        );
        $stmt->execute(['task_id' => $taskId]);

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
        $values = [];
        foreach ($taskIds as $taskId) {
            $taskValues = $this->listForTask($userId, $taskId);
            if ($taskValues !== []) {
                $values[$taskId] = $taskValues;
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
                'DELETE FROM task_custom_field_values WHERE task_id = :task_id'
            );
            $delete->execute(['task_id' => $taskId]);

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
                    'user_id' => $taskScope['created_by'],
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

    /** @return array{project_id:?int,created_by:int}|null */
    private function taskScopeForUser(int $userId, int $taskId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.created_by, t.project_id
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             LEFT JOIN team_members tm
                ON tm.team_id = p.owner_team_id
               AND tm.user_id = :member_user_id
             WHERE t.id = :task_id
               AND (
                    (t.project_id IS NULL AND t.created_by = :personal_user_id)
                    OR
                    (t.project_id IS NOT NULL AND p.owner_user_id = :project_user_id AND p.owner_team_id IS NULL)
                    OR
                    (t.project_id IS NOT NULL AND p.owner_user_id IS NULL
                     AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'member_user_id' => $userId,
            'task_id' => $taskId,
            'personal_user_id' => $userId,
            'project_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'created_by' => (int) $row['created_by'],
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
            if (!$this->projectAccess->canAccess($userId, $projectId)) {
                return false;
            }
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM custom_fields
                 WHERE user_id IS NULL
                   AND project_id = ?
                   AND id IN ({$placeholders})"
            );
            $stmt->execute([$projectId, ...$fieldIds]);
        }

        return (int) $stmt->fetchColumn() === count($fieldIds);
    }
}
