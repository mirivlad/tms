<?php

declare(strict_types=1);

namespace Tms\Domain\Customer;

use DomainException;
use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<CustomerRecord> */
    public function listForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name FROM customers
             WHERE user_id = :user_id ORDER BY name ASC, id ASC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->collect($stmt);
    }

    /** @return list<CustomerRecord> */
    public function listAllForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name FROM customers
             WHERE user_id = :user_id ORDER BY name ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $this->collect($stmt);
    }

    public function findForUser(int $userId, int $customerId): ?CustomerRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name FROM customers
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $customerId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByNameForUser(int $userId, string $name): ?CustomerRecord
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, name FROM customers
             WHERE user_id = :user_id AND name = :name LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<CustomerRecord> */
    public function searchForUser(int $userId, string $query, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        $needle = '%' . $this->escapeLike(trim($query)) . '%';
        $stmt = $this->db->prepare(
            "SELECT id, user_id, name FROM customers
             WHERE user_id = :user_id AND name LIKE :needle ESCAPE '!'
             ORDER BY name ASC, id ASC LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':needle', $needle, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->collect($stmt);
    }

    public function createForUser(int $userId, string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Customer name cannot be empty.');
        }
        if (mb_strlen($name) > 255) {
            throw new DomainException('Customer name cannot exceed 255 characters.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO customers (user_id, name, created_at, updated_at)
             VALUES (:user_id, :name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);
        return (int) $this->db->lastInsertId();
    }

    public function updateForUser(int $userId, int $customerId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Customer name cannot be empty.');
        }
        if (mb_strlen($name) > 255) {
            throw new DomainException('Customer name cannot exceed 255 characters.');
        }
        if ($this->findForUser($userId, $customerId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE customers SET name = :name, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['name' => $name, 'id' => $customerId, 'user_id' => $userId]);
        return true;
    }

    public function deleteForUser(int $userId, int $customerId): bool
    {
        if ($this->findForUser($userId, $customerId) === null) {
            return false;
        }

        $used = $this->db->prepare(
            'SELECT 1 FROM tasks WHERE customer_id = :customer_id AND created_by = :user_id LIMIT 1'
        );
        $used->execute(['customer_id' => $customerId, 'user_id' => $userId]);
        if ($used->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM customers WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $customerId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @return list<CustomerRecord> */
    private function collect(\PDOStatement $stmt): array
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
    private function hydrate(array $row): CustomerRecord
    {
        return new CustomerRecord(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
        );
    }
}
