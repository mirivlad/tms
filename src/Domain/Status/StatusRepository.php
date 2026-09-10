<?php

declare(strict_types=1);

namespace Tms\Domain\Status;

use DomainException;
use PDO;
use Throwable;

final class StatusRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<StatusRecord>
     */
    public function listForUser(int $userId, bool $boardOnly = false): array
    {
        $sql = 'SELECT id, user_id, name, description, color, sort_order, is_default, is_completion, show_on_board
                FROM statuses
                WHERE user_id = :user_id';
        if ($boardOnly) {
            $sql .= ' AND show_on_board = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }

        return $records;
    }

    public function findForUser(int $userId, int $statusId): ?StatusRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, description, color, sort_order, is_default, is_completion, show_on_board
             FROM statuses
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $statusId, 'user_id' => $userId]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createForUser(
        int $userId,
        string $name,
        string $description = '',
        string $color = '#6b7280',
        bool $isDefault = false,
        bool $isCompletion = false,
        bool $showOnBoard = true,
    ): int {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Status name cannot be empty.');
        }

        $this->db->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault($userId);
            }
            if ($isCompletion) {
                $this->clearCompletion($userId);
            }

            $stmt = $this->db->prepare(
                'INSERT INTO statuses (
                    user_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, created_at, updated_at
                 ) VALUES (
                    :user_id, :name, :description, :color, :sort_order,
                    :is_default, :is_completion, :show_on_board, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'user_id' => $userId,
                'name' => $name,
                'description' => trim($description),
                'color' => $this->normalizeColor($color),
                'sort_order' => $this->nextSortOrder($userId),
                'is_default' => $isDefault ? 1 : 0,
                'is_completion' => $isCompletion ? 1 : 0,
                'show_on_board' => $showOnBoard ? 1 : 0,
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function updateForUser(
        int $userId,
        int $statusId,
        string $name,
        string $description,
        string $color,
        bool $showOnBoard,
    ): bool {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Status name cannot be empty.');
        }

        if ($this->findForUser($userId, $statusId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE statuses
             SET name = :name,
                 description = :description,
                 color = :color,
                 show_on_board = :show_on_board,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => trim($description),
            'color' => $this->normalizeColor($color),
            'show_on_board' => $showOnBoard ? 1 : 0,
            'id' => $statusId,
            'user_id' => $userId,
        ]);
        return true;
    }

    public function setDefaultForUser(int $userId, int $statusId): bool
    {
        return $this->setExclusiveFlag($userId, $statusId, 'is_default');
    }

    public function setCompletionForUser(int $userId, int $statusId): bool
    {
        return $this->setExclusiveFlag($userId, $statusId, 'is_completion');
    }

    public function deleteForUser(int $userId, int $statusId): bool
    {
        $status = $this->findForUser($userId, $statusId);
        if ($status === null || $status->isDefault || $status->isCompletion) {
            return false;
        }

        $used = $this->db->prepare(
            'SELECT 1 FROM tasks WHERE status_id = :status_id AND created_by = :user_id LIMIT 1'
        );
        $used->execute(['status_id' => $statusId, 'user_id' => $userId]);
        if ($used->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM statuses WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $statusId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $statusIds
     */
    public function reorderForUser(int $userId, array $statusIds): bool
    {
        $owned = array_map(
            static fn (StatusRecord $record): int => $record->id,
            $this->listForUser($userId),
        );
        sort($owned);
        $requested = $statusIds;
        sort($requested);

        if ($owned !== $requested || count($statusIds) !== count(array_unique($statusIds))) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE statuses SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            foreach ($statusIds as $index => $statusId) {
                $stmt->execute([
                    'sort_order' => $index + 1,
                    'id' => $statusId,
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

    private function setExclusiveFlag(int $userId, int $statusId, string $column): bool
    {
        if (!in_array($column, ['is_default', 'is_completion'], true)) {
            throw new DomainException('Unsupported status role.');
        }
        if ($this->findForUser($userId, $statusId) === null) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $clear = $this->db->prepare("UPDATE statuses SET {$column} = 0 WHERE user_id = :user_id");
            $clear->execute(['user_id' => $userId]);

            $set = $this->db->prepare(
                "UPDATE statuses SET {$column} = 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id"
            );
            $set->execute(['id' => $statusId, 'user_id' => $userId]);

            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function clearDefault(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE statuses SET is_default = 0 WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    private function clearCompletion(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE statuses SET is_completion = 0 WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    private function nextSortOrder(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM statuses WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    private function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            throw new DomainException('Status color must be a six-digit hexadecimal color.');
        }
        return $color;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StatusRecord
    {
        return new StatusRecord(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            color: (string) $row['color'],
            sortOrder: (int) $row['sort_order'],
            isDefault: (bool) $row['is_default'],
            isCompletion: (bool) $row['is_completion'],
            showOnBoard: (bool) $row['show_on_board'],
        );
    }
}
