<?php

declare(strict_types=1);

namespace Tms\Domain\User;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findByUsername(string $username): ?UserRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, is_active, approved_at
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findById(int $userId): ?UserRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, is_active, approved_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function replacePasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UserRecord
    {
        return new UserRecord(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: (string) $row['role'],
            isActive: (bool) $row['is_active'],
            isApproved: $row['approved_at'] !== null,
        );
    }
}
