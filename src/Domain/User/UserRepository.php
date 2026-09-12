<?php

declare(strict_types=1);

namespace Tms\Domain\User;

use DomainException;
use PDO;
use RuntimeException;

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

    public function findByEmail(string $email): ?UserRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, is_active, approved_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => trim($email)]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByIdentifier(string $identifier): ?UserRecord
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, is_active, approved_at
             FROM users
             WHERE username = :username OR email = :email
             LIMIT 1'
        );
        $stmt->execute([
            'username' => $identifier,
            'email' => $identifier,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
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

    public function createAdmin(string $username, string $email, string $passwordHash): int
    {
        $exists = $this->db->prepare(
            'SELECT 1 FROM users WHERE username = :username OR email = :email LIMIT 1'
        );
        $exists->execute([
            'username' => $username,
            'email' => $email,
        ]);

        if ($exists->fetchColumn() !== false) {
            throw new DomainException('A user with that username or email already exists.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO users (
                username, email, password_hash, role, is_active,
                email_verified_at, approved_at, created_at, updated_at
             ) VALUES (
                :username, :email, :password_hash, \'admin\', 1,
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )'
        );
        $insert->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        $id = $this->db->lastInsertId();
        if ($id === false || (int) $id < 1) {
            throw new RuntimeException('Unable to determine the created administrator ID.');
        }

        return (int) $id;
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
