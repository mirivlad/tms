<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Team;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Team\TeamInvitationRepository;

final class TeamInvitationRepositoryTest extends TestCase
{
    private PDO $db;
    private TeamInvitationRepository $invitations;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE teams (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE team_invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL,
            invited_user_id INTEGER NOT NULL,
            invited_by INTEGER NULL,
            status TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            responded_at TEXT NULL
        )');

        $this->db->exec("INSERT INTO users (id, username, email) VALUES
            (1, 'lead', 'lead@example.test'),
            (2, 'invitee', 'invitee@example.test'),
            (3, 'other', 'other@example.test')");
        $this->db->exec("INSERT INTO teams (id, name) VALUES (10, 'Core Team')");
        $this->db->exec("INSERT INTO team_members (team_id, user_id, role) VALUES (10, 1, 'lead')");

        $this->invitations = new TeamInvitationRepository($this->db);
    }

    public function testInviteAcceptCreatesMemberAndFinalizesState(): void
    {
        $now = $this->time('2026-09-19 10:00:00');
        $id = $this->invitations->invite(10, 2, 1, $now);

        $pending = $this->invitations->listPendingForUser(2, $now);
        self::assertCount(1, $pending);
        self::assertSame($id, $pending[0]->id);
        self::assertSame('Core Team', $pending[0]->teamName);
        self::assertSame('lead', $pending[0]->invitedByUsername);

        self::assertTrue($this->invitations->accept(2, $id, $this->time('2026-09-20 10:00:00')));
        self::assertSame('member', $this->membershipRole(10, 2));
        self::assertSame('accepted', $this->status($id));
        self::assertSame([], $this->invitations->listPendingForUser(2, $this->time('2026-09-20 10:00:01')));
    }

    public function testDuplicatePendingAndExistingMembershipAreRejected(): void
    {
        $now = $this->time('2026-09-19 10:00:00');
        $this->invitations->invite(10, 2, 1, $now);

        try {
            $this->invitations->invite(10, 2, 1, $now);
            self::fail('Duplicate pending invitation should fail.');
        } catch (DomainException $error) {
            self::assertSame('A pending invitation already exists.', $error->getMessage());
        }

        $this->db->exec("INSERT INTO team_members (team_id, user_id, role) VALUES (10, 3, 'member')");
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('User is already a team member.');
        $this->invitations->invite(10, 3, 1, $now);
    }

    public function testDeclineRevokeAndExpiryAreExplicitStates(): void
    {
        $first = $this->invitations->invite(10, 2, 1, $this->time('2026-09-19 10:00:00'));
        self::assertTrue($this->invitations->decline(2, $first, $this->time('2026-09-19 11:00:00')));
        self::assertSame('declined', $this->status($first));

        $second = $this->invitations->invite(10, 2, 1, $this->time('2026-09-19 12:00:00'));
        self::assertFalse($this->invitations->revokeForLead(3, 10, $second, $this->time('2026-09-19 12:30:00')));
        self::assertTrue($this->invitations->revokeForLead(1, 10, $second, $this->time('2026-09-19 12:30:00')));
        self::assertSame('revoked', $this->status($second));

        $third = $this->invitations->invite(10, 2, 1, $this->time('2026-09-20 10:00:00'));
        self::assertSame([], $this->invitations->listPendingForUser(2, $this->time('2026-09-28 10:00:01')));
        self::assertSame('expired', $this->status($third));
        self::assertFalse($this->invitations->accept(2, $third, $this->time('2026-09-28 10:00:02')));
    }

    private function status(int $id): string
    {
        $stmt = $this->db->prepare('SELECT status FROM team_invitations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn();
    }

    private function membershipRole(int $teamId, int $userId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
