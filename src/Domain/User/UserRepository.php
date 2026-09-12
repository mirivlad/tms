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
            'SELECT id, username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at
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
            'SELECT id, username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at
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
            'SELECT id, username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at
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
            'SELECT id, username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at
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

    /** @return list<UserRecord> */
    public function listAll(): array
    {
        $stmt = $this->db->query(
            'SELECT id, username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at
             FROM users ORDER BY created_at ASC, id ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $users = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $users[] = $this->hydrate($row);
            }
        }
        return $users;
    }

    /** @return list<UserRecord> */
    public function listPending(): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn (UserRecord $user): bool => !$user->isApproved || !$user->isEmailVerified,
        ));
    }

    public function createUser(
        string $username,
        string $email,
        string $passwordHash,
        bool $isActive = true,
        bool $emailVerified = false,
        bool $approved = false,
    ): int {
        $this->assertAvailableIdentity($username, $email);
        $stmt = $this->db->prepare(
            'INSERT INTO users (
                username, email, password_hash, role, is_active, email_verified_at, approved_at, created_at, updated_at
             ) VALUES (
                :username, :email, :password_hash, \'user\', :is_active, :email_verified_at, :approved_at,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'username' => trim($username),
            'email' => trim($email),
            'password_hash' => $passwordHash,
            'is_active' => $isActive ? 1 : 0,
            'email_verified_at' => $emailVerified ? gmdate('Y-m-d H:i:s') : null,
            'approved_at' => $approved ? gmdate('Y-m-d H:i:s') : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateUsername(int $userId, string $username): bool
    {
        $username = trim($username);
        if ($this->identityExists('username', $username, $userId)) {
            throw new DomainException('Username is already in use.');
        }
        $stmt = $this->db->prepare(
            'UPDATE users SET username = :username, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['username' => $username, 'id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function updateByAdmin(
        int $userId,
        string $username,
        string $email,
        string $role,
        bool $isActive,
    ): bool {
        $username = trim($username);
        $email = trim($email);
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new DomainException('Invalid user role.');
        }
        if ($this->identityExists('username', $username, $userId)) {
            throw new DomainException('Username is already in use.');
        }
        if ($this->identityExists('email', $email, $userId)) {
            throw new DomainException('Email is already in use.');
        }
        $current = $this->findById($userId);
        if ($current === null) {
            return false;
        }
        if ($current->role === 'admin' && $this->isLastActiveAdmin($userId) && ($role !== 'admin' || !$isActive)) {
            throw new DomainException('The last active administrator cannot be disabled or demoted.');
        }
        $stmt = $this->db->prepare(
            'UPDATE users SET username = :username, email = :email, role = :role, is_active = :is_active,
             email_verified_at = CASE WHEN :preserve_verified = 1 THEN email_verified_at ELSE NULL END,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'preserve_verified' => $current->email === $email ? 1 : 0,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'id' => $userId,
        ]);
        return true;
    }

    public function setApproved(int $userId, bool $approved): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET approved_at = :approved_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['approved_at' => $approved ? gmdate('Y-m-d H:i:s') : null, 'id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function setEmailVerified(int $userId, bool $verified): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verified_at = :verified_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null, 'id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function deleteByAdmin(int $userId): bool
    {
        $user = $this->findById($userId);
        if ($user === null) {
            return false;
        }
        if ($user->role === 'admin' && $this->isLastActiveAdmin($userId)) {
            throw new DomainException('The last active administrator cannot be deleted.');
        }
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    private function isLastActiveAdmin(int $userId): bool
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        return $stmt !== false && (int) $stmt->fetchColumn() <= 1
            && ($this->findById($userId)?->role === 'admin');
    }

    private function assertAvailableIdentity(string $username, string $email): void
    {
        if ($this->identityExists('username', trim($username), null) || $this->identityExists('email', trim($email), null)) {
            throw new DomainException('A user with that username or email already exists.');
        }
    }

    private function identityExists(string $column, string $value, ?int $excludeUserId): bool
    {
        if (!in_array($column, ['username', 'email'], true)) {
            throw new DomainException('Unsupported identity field.');
        }
        $sql = 'SELECT 1 FROM users WHERE ' . $column . ' = :value';
        $params = ['value' => $value];
        if ($excludeUserId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
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
            isEmailVerified: $row['email_verified_at'] !== null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }
}
