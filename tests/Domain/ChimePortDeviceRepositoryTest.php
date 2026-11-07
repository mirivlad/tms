<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Notification\ChimePortDeviceRepository;
use Tms\Domain\User\UserRepository;

/**
 * Authorization property tests against an isolated in-memory schema. Runtime
 * uses MariaDB but the SQL conditions/identity relationships are the same.
 */
final class ChimePortDeviceRepositoryTest extends TestCase
{
    public function testPasswordChangeOrRevokeDisablesGrantWithoutCallingChimePort(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,email TEXT,password_hash TEXT,
            role TEXT,is_active INTEGER,approved_at TEXT,email_verified_at TEXT,
            created_at TEXT,updated_at TEXT,last_activity_at TEXT)');
        $db->exec("INSERT INTO users(id,username,email,password_hash,role,is_active,approved_at)
            VALUES(42,'alice','alice@example.org','old-hash','user',1,'2026-01-01')");
        $db->exec('CREATE TABLE chimeport_devices (device_id TEXT PRIMARY KEY, user_id INTEGER, kind TEXT,
            label TEXT,browser_session_hash TEXT,password_fingerprint TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,expires_at TEXT,revoked_at TEXT)');
        $repo = new ChimePortDeviceRepository($db, new UserRepository($db), 'cp_a_secret');
        $id = $repo->registerAndroid(42, 'Android');
        self::assertTrue($repo->active(42, $id));
        self::assertFalse($repo->active(43, $id));
        $db->exec("UPDATE users SET password_hash='new-hash' WHERE id=42");
        self::assertFalse($repo->active(42, $id));
        $id2 = $repo->registerAndroid(42, 'new phone');
        self::assertTrue($repo->active(42, $id2));
        self::assertTrue($repo->revoke(42, $id2));
        self::assertFalse($repo->active(42, $id2));
        $id3 = $repo->registerAndroid(42, 'phone 3');
        $db->exec("UPDATE users SET is_active=0 WHERE id=42");
        self::assertFalse($repo->active(42, $id3));
    }
}
