<?php

declare(strict_types=1);

namespace Tms\Security;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PasswordResetTokenRepository
{
    private readonly DateTimeZone $utc;

    public function __construct(private readonly PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function issue(int $userId, DateTimeImmutable $now, DateInterval $lifetime): PasswordResetToken
    {
        $this->deleteAllForUser($userId);
        $token = PasswordResetToken::issue();
        $stmt = $this->db->prepare(
            'INSERT INTO password_reset_tokens
                (selector, user_id, verifier_hash, expires_at, consumed_at, created_at)
             VALUES (:selector, :user_id, :verifier_hash, :expires_at, NULL, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'selector' => $token->selector,
            'user_id' => $userId,
            'verifier_hash' => $token->verifierHash(),
            'expires_at' => $now->add($lifetime)->setTimezone($this->utc)->format('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    public function findValidUserId(string $value, DateTimeImmutable $now): ?int
    {
        $token = PasswordResetToken::fromValue($value);
        if ($token === null) {
            return null;
        }
        $stored = $this->findBySelector($token->selector);
        if ($stored === null || $stored->consumed || $stored->expiresAt <= $now) {
            return null;
        }
        return $token->matchesHash($stored->verifierHash) ? $stored->userId : null;
    }

    public function consume(string $value, DateTimeImmutable $now): ?int
    {
        $token = PasswordResetToken::fromValue($value);
        if ($token === null) {
            return null;
        }
        $stored = $this->findBySelector($token->selector);
        if ($stored === null || $stored->consumed || $stored->expiresAt <= $now
            || !$token->matchesHash($stored->verifierHash)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'UPDATE password_reset_tokens
             SET consumed_at = CURRENT_TIMESTAMP
             WHERE selector = :selector AND consumed_at IS NULL'
        );
        $stmt->execute(['selector' => $stored->selector]);
        return $stmt->rowCount() === 1 ? $stored->userId : null;
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    private function findBySelector(string $selector): ?PasswordResetTokenRecord
    {
        $stmt = $this->db->prepare(
            'SELECT selector, user_id, verifier_hash, expires_at, consumed_at
             FROM password_reset_tokens WHERE selector = :selector LIMIT 1'
        );
        $stmt->execute(['selector' => $selector]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new PasswordResetTokenRecord(
            selector: (string) $row['selector'],
            userId: (int) $row['user_id'],
            verifierHash: (string) $row['verifier_hash'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $this->utc),
            consumed: $row['consumed_at'] !== null,
        );
    }
}
