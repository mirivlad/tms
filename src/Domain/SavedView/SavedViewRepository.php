<?php

declare(strict_types=1);

namespace Tms\Domain\SavedView;

use DomainException;
use JsonException;
use PDO;
use Throwable;

final class SavedViewRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<SavedViewRecord> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, query_json, is_default, created_at, updated_at
             FROM task_saved_views
             WHERE user_id = :user_id
             ORDER BY is_default DESC, name ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        $views = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $views[] = $this->hydrate($row);
            }
        }
        return $views;
    }

    public function findForUser(int $userId, int $viewId): ?SavedViewRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, query_json, is_default, created_at, updated_at
             FROM task_saved_views
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $viewId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function defaultForUser(int $userId): ?SavedViewRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, query_json, is_default, created_at, updated_at
             FROM task_saved_views
             WHERE user_id = :user_id AND is_default = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $query */
    public function create(int $userId, string $name, array $query, bool $isDefault): int
    {
        $name = $this->normalizeName($name);

        $this->db->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault($userId);
            }
            $stmt = $this->db->prepare(
                'INSERT INTO task_saved_views (
                    user_id, name, query_json, is_default, created_at, updated_at
                 ) VALUES (
                    :user_id, :name, :query_json, :is_default, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'user_id' => $userId,
                'name' => $name,
                'query_json' => json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_default' => $isDefault ? 1 : 0,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof JsonException) {
                throw new DomainException('Unable to encode saved view.', 0, $error);
            }
            throw $error;
        }
    }

    public function setDefault(int $userId, int $viewId): bool
    {
        if ($this->findForUser($userId, $viewId) === null) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $this->clearDefault($userId);
            $stmt = $this->db->prepare(
                'UPDATE task_saved_views
                 SET is_default = 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute(['id' => $viewId, 'user_id' => $userId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return true;
    }

    public function delete(int $userId, int $viewId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM task_saved_views WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $viewId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    private function clearDefault(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE task_saved_views SET is_default = 0 WHERE user_id = :user_id AND is_default = 1'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 96) {
            throw new DomainException('Saved view name must contain 1-96 characters.');
        }
        return $name;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SavedViewRecord
    {
        $query = [];
        try {
            $decoded = json_decode((string) $row['query_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $query = $decoded;
            }
        } catch (JsonException) {
            $query = [];
        }

        return new SavedViewRecord(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            query: $query,
            isDefault: (bool) $row['is_default'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
