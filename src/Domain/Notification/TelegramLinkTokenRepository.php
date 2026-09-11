<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class TelegramLinkTokenRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function issue(int $userId, DateTimeImmutable $now, DateInterval $ttl): string
    {
        $selector = bin2hex(random_bytes(12));
        $verifier = bin2hex(random_bytes(32));
        $hash = hash('sha256', $verifier);
        $expiresAt = $now->add($ttl)->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO telegram_link_tokens (selector, user_id, verifier_hash, expires_at, consumed_at)
             VALUES (:selector, :user_id, :verifier_hash, :expires_at, NULL)
             ON DUPLICATE KEY UPDATE selector = VALUES(selector), verifier_hash = VALUES(verifier_hash),
                 expires_at = VALUES(expires_at), consumed_at = NULL, created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'selector' => $selector,
            'user_id' => $userId,
            'verifier_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);
        return $selector . '.' . $verifier;
    }

    public function consume(int $userId, string $token, DateTimeImmutable $now): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$selector, $verifier] = $parts;
        if (preg_match('/^[a-f0-9]{24}$/D', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $verifier) !== 1) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT verifier_hash, expires_at, consumed_at
             FROM telegram_link_tokens WHERE selector = :selector AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['selector' => $selector, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['consumed_at'] !== null) {
            return false;
        }
        if ((string) $row['expires_at'] < $now->format('Y-m-d H:i:s')) {
            return false;
        }
        if (!hash_equals((string) $row['verifier_hash'], hash('sha256', $verifier))) {
            return false;
        }
        $update = $this->db->prepare(
            'UPDATE telegram_link_tokens SET consumed_at = :consumed_at
             WHERE selector = :selector AND user_id = :user_id AND consumed_at IS NULL'
        );
        $update->execute([
            'consumed_at' => $now->format('Y-m-d H:i:s'),
            'selector' => $selector,
            'user_id' => $userId,
        ]);
        return $update->rowCount() === 1;
    }
}
