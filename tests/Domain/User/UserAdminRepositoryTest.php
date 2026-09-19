<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\User;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\User\UserRepository;

final class UserAdminRepositoryTest extends TestCase
{
    private PDO $db;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT, is_active INTEGER, email_verified_at TEXT NULL, approved_at TEXT NULL, last_activity_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec("INSERT INTO users (id,username,email,password_hash,role,is_active,email_verified_at,approved_at) VALUES
            (1,'admin','admin@example.test','hash','admin',1,'2026-01-01','2026-01-01'),
            (2,'user','user@example.test','hash','user',1,'2026-01-01','2026-01-01'),
            (3,'secondlead','secondlead@example.test','hash','user',1,'2026-01-01','2026-01-01')");
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->users = new UserRepository($this->db);
    }

    public function testLastActiveAdministratorCannotBeDemotedOrDeleted(): void
    {
        try {
            $this->users->updateByAdmin(1, 'admin', 'admin@example.test', 'user', true);
            self::fail('Last administrator demotion should fail.');
        } catch (DomainException) {
            self::assertSame('admin', $this->users->findById(1)?->role);
        }

        $this->expectException(DomainException::class);
        $this->users->deleteByAdmin(1);
    }

    public function testSoleTeamLeadCannotBeDisabledOrDeletedByAdmin(): void
    {
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES (7,2,'lead')");

        try {
            $this->users->updateByAdmin(2, 'user', 'user@example.test', 'user', false);
            self::fail('Sole team lead disable should fail.');
        } catch (DomainException $error) {
            self::assertSame('A user who is the sole lead of a team cannot be disabled.', $error->getMessage());
            self::assertTrue($this->users->findById(2)?->isActive ?? false);
        }

        try {
            $this->users->deleteByAdmin(2);
            self::fail('Sole team lead deletion should fail.');
        } catch (DomainException $error) {
            self::assertSame('A user who is the sole lead of a team cannot be deleted.', $error->getMessage());
            self::assertNotNull($this->users->findById(2));
        }

        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES (7,3,'lead')");
        self::assertTrue($this->users->updateByAdmin(2, 'user', 'user@example.test', 'user', false));
        self::assertFalse($this->users->findById(2)?->isActive ?? true);
        self::assertTrue($this->users->deleteByAdmin(2));
        self::assertNull($this->users->findById(2));
    }

    public function testChangingEmailRequiresVerificationAgain(): void
    {
        self::assertTrue($this->users->updateByAdmin(2, 'user', 'new@example.test', 'user', true));
        $updated = $this->users->findById(2);
        self::assertNotNull($updated);
        self::assertSame('new@example.test', $updated->email);
        self::assertFalse($updated->isEmailVerified);
    }

    public function testActivityTimestampCanBeRecordedAndRead(): void
    {
        $at = new DateTimeImmutable('2026-09-13 06:30:45', new DateTimeZone('UTC'));
        self::assertTrue($this->users->touchActivity(2, $at));
        self::assertSame('2026-09-13 06:30:45', $this->users->findById(2)?->lastActivityAt);
    }

    public function testPendingListIncludesUnverifiedOrUnapprovedUsers(): void
    {
        $this->db->exec("INSERT INTO users (username,email,password_hash,role,is_active,email_verified_at,approved_at) VALUES ('pending','pending@example.test','hash','user',1,NULL,NULL)");
        self::assertSame(['pending'], array_map(static fn ($user): string => $user->username, $this->users->listPending()));
    }
}
