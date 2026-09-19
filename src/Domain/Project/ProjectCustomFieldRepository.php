<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

use DomainException;
use JsonException;
use PDO;
use Throwable;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;

final class ProjectCustomFieldRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<CustomFieldRecord> */
    public function listForProject(int $userId, int $projectId): array
    {
        if (!$this->projectAccessible($userId, $projectId)) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, project_id, source_field_id, name, field_type,
                    options_json, is_required, sort_order
             FROM custom_fields
             WHERE user_id IS NULL AND project_id = :project_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $this->fetchAll($stmt);
    }

    public function findForProject(int $userId, int $projectId, int $fieldId): ?CustomFieldRecord
    {
        if (!$this->projectAccessible($userId, $projectId)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, project_id, source_field_id, name, field_type,
                    options_json, is_required, sort_order
             FROM custom_fields
             WHERE id = :id AND user_id IS NULL AND project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $fieldId, 'project_id' => $projectId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param list<string> $options */
    public function createForProject(
        int $userId,
        int $projectId,
        string $name,
        string $type,
        array $options,
        bool $isRequired,
    ): int {
        $this->assertProjectManageable($userId, $projectId);
        $name = $this->normalizeName($name);
        $type = $this->normalizeType($type);
        $options = $this->normalizeOptions($type, $options);

        $stmt = $this->db->prepare(
            'INSERT INTO custom_fields (
                user_id, project_id, source_field_id, name, field_type, options_json,
                is_required, sort_order, created_at, updated_at
             ) VALUES (
                NULL, :project_id, NULL, :name, :field_type, :options_json,
                :is_required, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'name' => $name,
            'field_type' => $type,
            'options_json' => $this->encodeOptions($options),
            'is_required' => $isRequired ? 1 : 0,
            'sort_order' => $this->nextSortOrder($projectId),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param list<string> $options */
    public function updateForProject(
        int $userId,
        int $projectId,
        int $fieldId,
        string $name,
        string $type,
        array $options,
        bool $isRequired,
    ): bool {
        if (!$this->projectManageable($userId, $projectId)) {
            return false;
        }
        $existing = $this->findForProject($userId, $projectId, $fieldId);
        if ($existing === null) {
            return false;
        }

        $name = $this->normalizeName($name);
        $type = $this->normalizeType($type);
        $options = $this->normalizeOptions($type, $options);
        $typeChanged = $existing->type !== $type;
        $optionsChanged = $existing->options !== $options;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE custom_fields
                 SET name = :name,
                     field_type = :field_type,
                     options_json = :options_json,
                     is_required = :is_required,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
            );
            $stmt->execute([
                'name' => $name,
                'field_type' => $type,
                'options_json' => $this->encodeOptions($options),
                'is_required' => $isRequired ? 1 : 0,
                'id' => $fieldId,
                'project_id' => $projectId,
            ]);

            if ($typeChanged) {
                $this->clearStoredValues($fieldId);
            } elseif ($optionsChanged && $type === 'select') {
                $this->pruneSelectValues($fieldId, $options);
            } elseif ($optionsChanged && $type === 'checkbox_list') {
                $this->pruneCheckboxListValues($fieldId, $options);
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

    public function deleteForProject(int $userId, int $projectId, int $fieldId): bool
    {
        if (!$this->projectManageable($userId, $projectId)
            || $this->findForProject($userId, $projectId, $fieldId) === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM custom_fields
             WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
        );
        $stmt->execute(['id' => $fieldId, 'project_id' => $projectId]);
        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $fieldIds */
    public function reorderForProject(int $userId, int $projectId, array $fieldIds): bool
    {
        if (!$this->projectManageable($userId, $projectId)) {
            return false;
        }
        $owned = array_map(
            static fn (CustomFieldRecord $field): int => $field->id,
            $this->listForProject($userId, $projectId),
        );
        $ownedSorted = $owned;
        $requestedSorted = $fieldIds;
        sort($ownedSorted);
        sort($requestedSorted);
        if ($ownedSorted !== $requestedSorted || count($fieldIds) !== count(array_unique($fieldIds))) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE custom_fields
                 SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
            );
            foreach ($fieldIds as $index => $fieldId) {
                $stmt->execute([
                    'sort_order' => $index + 1,
                    'id' => $fieldId,
                    'project_id' => $projectId,
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

    private function clearStoredValues(int $fieldId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM task_custom_field_values WHERE field_id = :field_id'
        );
        $stmt->execute(['field_id' => $fieldId]);
    }

    /** @param list<string> $options */
    private function pruneSelectValues(int $fieldId, array $options): void
    {
        $params = ['field_id' => $fieldId];
        $placeholders = [];
        foreach ($options as $index => $option) {
            $key = 'option_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $option;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM task_custom_field_values
             WHERE field_id = :field_id
               AND value NOT IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
    }

    /** @param list<string> $options */
    private function pruneCheckboxListValues(int $fieldId, array $options): void
    {
        $select = $this->db->prepare(
            'SELECT task_id, user_id, value
             FROM task_custom_field_values
             WHERE field_id = :field_id'
        );
        $select->execute(['field_id' => $fieldId]);

        $update = $this->db->prepare(
            'UPDATE task_custom_field_values
             SET value = :value, updated_at = CURRENT_TIMESTAMP
             WHERE task_id = :task_id AND field_id = :field_id AND user_id = :user_id'
        );
        $delete = $this->db->prepare(
            'DELETE FROM task_custom_field_values
             WHERE task_id = :task_id AND field_id = :field_id AND user_id = :user_id'
        );

        while (($row = $select->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $stored = is_string($row['value'] ?? null) ? (string) $row['value'] : '';
            try {
                $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = [];
            }

            $kept = [];
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (is_string($value) && in_array($value, $options, true) && !in_array($value, $kept, true)) {
                        $kept[] = $value;
                    }
                }
            }

            $params = [
                'task_id' => (int) $row['task_id'],
                'field_id' => $fieldId,
                'user_id' => (int) $row['user_id'],
            ];
            if ($kept === []) {
                $delete->execute($params);
            } else {
                $update->execute($params + [
                    'value' => json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    private function assertProjectManageable(int $userId, int $projectId): void
    {
        if (!$this->projectManageable($userId, $projectId)) {
            throw new DomainException('Project is unavailable.');
        }
    }

    private function projectAccessible(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM projects p
             LEFT JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :team_user_id
             WHERE p.id = :id
               AND (
                    (p.owner_user_id = :personal_user_id AND p.owner_team_id IS NULL)
                    OR
                    (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function projectManageable(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM projects p
             LEFT JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :team_user_id
             WHERE p.id = :id
               AND (
                    (p.owner_user_id = :personal_user_id AND p.owner_team_id IS NULL)
                    OR
                    (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.role = \'lead\')
               )
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 96) {
            throw new DomainException('Custom field name must contain 1-96 characters.');
        }
        return $name;
    }

    private function normalizeType(string $type): string
    {
        $type = trim($type);
        if (!in_array($type, CustomFieldRepository::TYPES, true)) {
            throw new DomainException('Unsupported custom field type.');
        }
        return $type;
    }

    /**
     * @param list<string> $options
     * @return list<string>
     */
    private function normalizeOptions(string $type, array $options): array
    {
        if (!in_array($type, ['select', 'checkbox_list'], true)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            $option = trim($option);
            if ($option === '') {
                continue;
            }
            if (mb_strlen($option) > 128) {
                throw new DomainException('Custom field options cannot exceed 128 characters.');
            }
            if (!in_array($option, $normalized, true)) {
                $normalized[] = $option;
            }
        }
        if ($normalized === []) {
            throw new DomainException('Select and checkbox-list fields require at least one option.');
        }
        if (count($normalized) > 50) {
            throw new DomainException('Custom fields cannot contain more than 50 options.');
        }
        return $normalized;
    }

    /** @param list<string> $options */
    private function encodeOptions(array $options): ?string
    {
        return $options === []
            ? null
            : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nextSortOrder(int $projectId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0)
             FROM custom_fields
             WHERE user_id IS NULL AND project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    /** @return list<CustomFieldRecord> */
    private function fetchAll(\PDOStatement $stmt): array
    {
        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CustomFieldRecord
    {
        $options = [];
        $encoded = $row['options_json'] ?? null;
        if (is_string($encoded) && $encoded !== '') {
            try {
                $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new DomainException('Stored custom field options are invalid.', 0, $error);
            }
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (is_string($value)) {
                        $options[] = $value;
                    }
                }
            }
        }

        return new CustomFieldRecord(
            id: (int) $row['id'],
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            name: (string) $row['name'],
            type: (string) $row['field_type'],
            options: $options,
            isRequired: (bool) $row['is_required'],
            sortOrder: (int) $row['sort_order'],
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            sourceFieldId: $row['source_field_id'] !== null ? (int) $row['source_field_id'] : null,
        );
    }
}
