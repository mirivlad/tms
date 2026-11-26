<?php

declare(strict_types=1);

namespace Tms\Domain\Mcp;

use PDO;

final class McpTokenRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{id:int,user_id:int,can_write:bool}|null */
    public function authenticate(string $secret, bool $lock = false): ?array
    {
        if (!preg_match('/^tms_mcp_[a-f0-9]{64}$/D', $secret)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT m.id,m.user_id,m.can_write FROM mcp_tokens m JOIN users u ON u.id=m.user_id
             WHERE m.token_hash=:hash AND m.revoked_at IS NULL AND m.expires_at > UTC_TIMESTAMP()
             AND u.is_active=1 AND u.approved_at IS NOT NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['hash' => hash('sha256', $secret)]);
        $row = $stmt->fetch();
        return is_array($row) ? ['id'=>(int)$row['id'], 'user_id'=>(int)$row['user_id'], 'can_write'=>(bool)$row['can_write']] : null;
    }

    public function issue(int $userId, string $label, bool $write, int $days): string
    {
        if ($days < 1 || $days > 365 || trim($label) === '' || mb_strlen($label) > 100) {
            throw new \InvalidArgumentException('Label and expiry (1–365 days) required.');
        }
        $token = 'tms_mcp_' . bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT INTO mcp_tokens(user_id,label,token_hash,can_write,expires_at,created_at)
            VALUES(:uid,:label,:hash,:write,DATE_ADD(UTC_TIMESTAMP(), INTERVAL :days DAY),UTC_TIMESTAMP())');
        $stmt->execute(['uid'=>$userId,'label'=>$label,'hash'=>hash('sha256',$token),'write'=>(int)$write,'days'=>$days]);
        return $token;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id,label,can_write,created_at,expires_at,revoked_at FROM mcp_tokens WHERE user_id=:uid ORDER BY id DESC');
        $stmt->execute(['uid'=>$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revoke(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE mcp_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=:id AND user_id=:uid AND revoked_at IS NULL');
        $stmt->execute(['id'=>$id,'uid'=>$userId]);
        return $stmt->rowCount() === 1;
    }
}
