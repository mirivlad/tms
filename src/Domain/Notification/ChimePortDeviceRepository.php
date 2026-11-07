<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use PDO;
use Tms\Domain\User\UserRepository;

/**
 * TMS owns all delegated device grants. CP never owns TMS identities.
 * The fingerprint is intentionally tied to the *current password hash*:
 * password changes, account removal and account disable take effect at the
 * very next introspection even if ChimePort is offline during the change.
 */
final class ChimePortDeviceRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users,
        private readonly string $secret,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Trusted application secret required.');
        }
    }

    public function registerAndroid(int $userId, string $label): string
    {
        return $this->create($userId, 'android', $label, null, null);
    }

    public function registerBrowser(int $userId, string $phpSessionId, string $label): string
    {
        if ($phpSessionId === '') {
            throw new \InvalidArgumentException('Active TMS web session required.');
        }
        $key = hash_hmac('sha256', 'browser:' . $phpSessionId, $this->secret);
        $stmt = $this->db->prepare(
            'SELECT device_id FROM chimeport_devices
             WHERE user_id=:user_id AND kind=:kind AND browser_session_hash=:session
               AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'kind' => 'browser', 'session' => $key]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            $this->db->prepare(
                'UPDATE chimeport_devices SET expires_at=UTC_TIMESTAMP()+INTERVAL 30 DAY,
                last_seen_at=UTC_TIMESTAMP() WHERE device_id=:id'
            )->execute(['id' => $existing]);
            return $existing;
        }
        return $this->create($userId, 'browser', $label, $key, 30);
    }

    private function create(int $userId, string $kind, string $label, ?string $browserKey, ?int $expiryDays): string
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isActive || !$user->isApproved) {
            throw new \DomainException('Inactive TMS account cannot register devices.');
        }
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'INSERT INTO chimeport_devices
             (device_id,user_id,kind,label,browser_session_hash,password_fingerprint,expires_at,created_at,last_seen_at)
             VALUES (:id,:user_id,:kind,:label,:browser_key,:fingerprint,:expires_at,:now_created,:now_seen)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'kind' => $kind,
            'label' => mb_substr(trim($label) !== '' ? trim($label) : ucfirst($kind), 0, 120),
            'browser_key' => $browserKey,
            'fingerprint' => $this->fingerprint($user->passwordHash),
            'expires_at' => $expiryDays === null ? null : gmdate('Y-m-d H:i:s', time() + $expiryDays * 86400),
            'now_created' => gmdate('Y-m-d H:i:s'),
            'now_seen' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    public function active(int $userId, string $deviceId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT d.password_fingerprint,u.password_hash
             FROM chimeport_devices d
             INNER JOIN users u ON u.id=d.user_id
             WHERE d.device_id=:id AND d.user_id=:user_id
               AND d.revoked_at IS NULL
               AND (d.expires_at IS NULL OR d.expires_at > :now)
               AND u.is_active=1 AND u.approved_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute(['id' => $deviceId, 'user_id' => $userId,
            'now' => gmdate('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        if (!hash_equals((string) $row['password_fingerprint'], $this->fingerprint((string) $row['password_hash']))) {
            // Do not need an outbound revoke RPC to make access fail-closed.
            return false;
        }
        $this->db->prepare(
            'UPDATE chimeport_devices SET last_seen_at=:now
             WHERE device_id=:id AND last_seen_at < :cutoff'
        )->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'id' => $deviceId,
            'cutoff' => gmdate('Y-m-d H:i:s', time() - 900),
        ]);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.device_id,d.kind,d.label,d.created_at,d.last_seen_at,d.expires_at,d.revoked_at,
                    d.password_fingerprint,u.password_hash
             FROM chimeport_devices d JOIN users u ON u.id=d.user_id
             WHERE d.user_id=:user_id ORDER BY d.created_at DESC LIMIT 100'
        );
        $stmt->execute(['user_id' => $userId]);
        $result = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row['active'] = $row['revoked_at'] === null
                && ($row['expires_at'] === null || strtotime((string) $row['expires_at'] . ' UTC') > time())
                && hash_equals((string) $row['password_fingerprint'], $this->fingerprint((string) $row['password_hash']));
            unset($row['password_fingerprint'], $row['password_hash']);
            $result[] = $row;
        }
        return $result;
    }

    public function revoke(int $userId, string $deviceId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE chimeport_devices SET revoked_at=:revoked_at
             WHERE device_id=:id AND user_id=:user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $deviceId, 'user_id' => $userId,
            'revoked_at' => gmdate('Y-m-d H:i:s')]);
        return $stmt->rowCount() === 1;
    }

    public function revokeAll(int $userId): void
    {
        $this->db->prepare(
            'UPDATE chimeport_devices SET revoked_at=:revoked_at
             WHERE user_id=:user_id AND revoked_at IS NULL'
        )->execute(['user_id' => $userId, 'revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function revokeBrowserSession(int $userId, string $phpSessionId): void
    {
        if ($phpSessionId === '') {
            return;
        }
        $hash = hash_hmac('sha256', 'browser:' . $phpSessionId, $this->secret);
        $stmt = $this->db->prepare(
            'UPDATE chimeport_devices SET revoked_at=:revoked_at
             WHERE user_id=:user_id AND browser_session_hash=:session AND revoked_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'session' => $hash,
            'revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function browserActive(int $userId,string $phpSessionId): bool
    {
        if ($phpSessionId==='') {return false;}
        $hash=hash_hmac('sha256','browser:'.$phpSessionId,$this->secret);
        $stmt=$this->db->prepare(
            'SELECT device_id FROM chimeport_devices WHERE user_id=:user_id AND kind=:kind
            AND browser_session_hash=:session AND revoked_at IS NULL
            AND expires_at>:now ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([
            'user_id'=>$userId,'kind'=>'browser','session'=>$hash,
            'now'=>gmdate('Y-m-d H:i:s'),
        ]);
        $id=$stmt->fetchColumn();
        return is_string($id) && $this->active($userId,$id);
    }

    /** Sliding browser lease is renewed only by an authenticated TMS request. */
    public function touchBrowserSession(int $userId, string $phpSessionId): void
    {
        if ($phpSessionId === '') { return; }
        $hash = hash_hmac('sha256', 'browser:' . $phpSessionId, $this->secret);
        $this->db->prepare(
            'UPDATE chimeport_devices
             SET expires_at=:expires_at,last_seen_at=:now
             WHERE user_id=:user_id AND browser_session_hash=:session
               AND revoked_at IS NULL AND expires_at>:now_check AND last_seen_at<:cutoff'
        )->execute([
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
            'now' => gmdate('Y-m-d H:i:s'),
            'user_id' => $userId,
            'session' => $hash,
            'now_check' => gmdate('Y-m-d H:i:s'),
            'cutoff' => gmdate('Y-m-d H:i:s', time() - 900),
        ]);
    }

    /** Logout or account switch with a formerly expired PHP session. */
    public function revokeBrowserSessionAny(string $phpSessionId): void
    {
        if ($phpSessionId === '') {return;}
        $hash = hash_hmac('sha256','browser:'.$phpSessionId,$this->secret);
        $this->db->prepare(
            'UPDATE chimeport_devices SET revoked_at=:revoked_at
             WHERE browser_session_hash=:hash AND revoked_at IS NULL'
        )->execute(['revoked_at'=>gmdate('Y-m-d H:i:s'),'hash'=>$hash]);
    }

    private function fingerprint(string $passwordHash): string
    {
        return hash_hmac('sha256', 'password:' . $passwordHash, $this->secret);
    }
}
