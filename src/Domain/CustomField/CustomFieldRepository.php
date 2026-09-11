<?php

declare(strict_types=1);

namespace Tms\Domain\CustomField;

use DomainException;
use JsonException;
use PDO;
use Throwable;

final class CustomFieldRepository
{
    /** @var list<string> */
    public const TYPES = ['text', 'textarea', 'select', 'money', 'checkbox', 'checkbox_list'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<CustomFieldRecord> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, field_type, options_json, is_required, sort_order
             FROM custom_fields
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

    public function findForUser(int $userId, int $fieldId): ?CustomFieldRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, field_type, options_json, is_required, sort_order
             FROM custom_fields
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $fieldId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param list<string> $options */
    public function createForUser(
        int $userId,
        string $name,
        string $type,
        array $options,
        bool $isRequired,
    ): int {
        $name = $this->normalizeName($name);
        $type = $this->normalizeType($type);
        $options = $this->normalizeOptions($type, $options);

        $stmt = $this->db->prepare(
            'INSERT INTO custom_fields (
                user_id, name, field_type, options_json, is_required, sort_order, created_at, updated_at
             ) VALUES (
                :user_id, :name, :field_type, :options_json, :is_required, :sort_order,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'field_type' => $type,
            'options_json' => $this->encodeOptions($options),
            'is_required' => $isRequired ? 1 : 0,
            'sort_order' => $this->nextSortOrder($userId),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param list<string> $options */
    public function updateForUser(
        int $userId,
        int $fieldId,
        string $name,
        string $type,
        array $options,
        bool $isRequired,
    ): bool {
        $existing = $this->findForUser($userId, $fieldId);
        if ($existing === null) {
            return false;
        }

        $name = $this->normalizeName($name);
        $type = $this->normalizeType($type);
        $options = $this->normalizeOptions($type, $options);
        $valueContractChanged = $existing->type !== $type || $existing->options !== $options;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE custom_fields
                 SET name = :name,
                     field_type = :field_type,
                     options_json = :options_json,
                     is_required = :is_required,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'name' => $name,
                'field_type' => $type,
                'options_json' => $this->encodeOptions($options),
                'is_required' => $isRequired ? 1 : 0,
                'id' => $fieldId,
                'user_id' => $userId,
            ]);

            if ($valueContractChanged) {
                $clear = $this->db->prepare(
                    'DELETE FROM task_custom_field_values WHERE field_id = :field_id AND user_id = :user_id'
                );
                $clear->execute(['field_id' => $fieldId, 'user_id' => $userId]);
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

    public function deleteForUser(int $userId, int $fieldId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM custom_fields WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $fieldId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $fieldIds */
    public function reorderForUser(int $userId, array $fieldIds): bool
    {
        $owned = array_map(
            static fn (CustomFieldRecord $record): int => $record->id,
            $this->listForUser($userId),
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
                 WHERE id = :id AND user_id = :user_id'
            );
            foreach ($fieldIds as $index => $fieldId) {
                $stmt->execute([
                    'sort_order' => $index + 1,
                    'id' => $fieldId,
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
        if (!in_array($type, self::TYPES, true)) {
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
        if ($options === []) {
            return null;
        }
        return json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nextSortOrder(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM custom_fields WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn() + 1;
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
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            type: (string) $row['field_type'],
            options: $options,
            isRequired: (bool) $row['is_required'],
            sortOrder: (int) $row['sort_order'],
        );
    }
}
