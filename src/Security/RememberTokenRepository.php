<?php

declare(strict_types=1);

namespace Tms\Security;

use DateTimeImmutable;
use PDO;

final class RememberTokenRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function store(int $userId, RememberToken $token, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO remember_tokens (selector, user_id, verifier_hash, expires_at, created_at)
             VALUES (:selector, :user_id, :verifier_hash, :expires_at, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'selector' => $token->selector,
            'user_id' => $userId,
            'verifier_hash' => $token->verifierHash(),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        $stmt = $this->db->prepare(
            'SELECT selector, user_id, verifier_hash, expires_at
             FROM remember_tokens
             WHERE selector = :selector
             LIMIT 1'
        );
        $stmt->execute(['selector' => $selector]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new RememberTokenRecord(
            selector: (string) $row['selector'],
            userId: (int) $row['user_id'],
            verifierHash: (string) $row['verifier_hash'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
        );
    }

    public function deleteBySelector(string $selector): void
    {
        $stmt = $this->db->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
