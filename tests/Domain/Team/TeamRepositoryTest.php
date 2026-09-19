<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Team;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Team\TeamRepository;

final class TeamRepositoryTest extends TestCase
{
    private PDO $db;
    private TeamRepository $teams;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            approved_at TEXT NULL
        )');
        $this->db->exec('CREATE TABLE teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, user_id),
            FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');

        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER NULL,
            assignee_user_id INTEGER NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec("INSERT INTO users (id, username, email, is_active, approved_at) VALUES
            (1, 'lead', 'lead@example.test', 1, '2026-01-01'),
            (2, 'member', 'member@example.test', 1, '2026-01-01'),
            (3, 'other', 'other@example.test', 1, '2026-01-01')");

        $this->teams = new TeamRepository($this->db);
    }

    public function testCreatorBecomesLeadAndMembershipScopesTeam(): void
    {
        $teamId = $this->teams->createForUser(1, 'Core Team', 'Test team');

        $team = $this->teams->findForMember(1, $teamId);
        self::assertNotNull($team);
        self::assertTrue($team->currentUserIsLead());
        self::assertSame('lead', $this->teams->roleForUser(1, $teamId));
        self::assertNull($this->teams->findForMember(2, $teamId));

        self::assertTrue($this->teams->addMember($teamId, 2));
        self::assertSame('member', $this->teams->roleForUser(2, $teamId));
        self::assertCount(2, $this->teams->listMembers(1, $teamId));
        self::assertSame([], $this->teams->listMembers(3, $teamId));
    }

    public function testOnlyLeadCanChangeRolesAndLastLeadIsProtected(): void
    {
        $teamId = $this->teams->createForUser(1, 'Core Team', '');
        $this->teams->addMember($teamId, 2);

        self::assertFalse($this->teams->changeRoleForLead(2, $teamId, 1, 'member'));
        self::assertTrue($this->teams->changeRoleForLead(1, $teamId, 2, 'lead'));
        self::assertSame('lead', $this->teams->roleForUser(2, $teamId));

        self::assertTrue($this->teams->changeRoleForLead(1, $teamId, 1, 'member'));
        self::assertSame('member', $this->teams->roleForUser(1, $teamId));

        $this->expectException(DomainException::class);
        $this->teams->changeRoleForLead(2, $teamId, 2, 'member');
    }

    public function testInactiveOrUnapprovedLeadDoesNotSatisfyLastLeadGuard(): void
    {
        $teamId = $this->teams->createForUser(1, 'Core Team', '');
        $this->teams->addMember($teamId, 2, 'lead');

        $this->db->exec('UPDATE users SET is_active = 0 WHERE id = 2');
        try {
            $this->teams->changeRoleForLead(1, $teamId, 1, 'member');
            self::fail('Inactive alternate lead must not satisfy the last-lead guard.');
        } catch (DomainException) {
            self::assertSame('lead', $this->teams->roleForUser(1, $teamId));
        }

        $this->db->exec("UPDATE users SET is_active = 1, approved_at = NULL WHERE id = 2");
        $this->expectException(DomainException::class);
        $this->teams->leave(1, $teamId);
    }

    public function testLastLeadCannotLeaveOrBeRemoved(): void
    {
        $teamId = $this->teams->createForUser(1, 'Core Team', '');
        $this->teams->addMember($teamId, 2);

        try {
            $this->teams->leave(1, $teamId);
            self::fail('Last lead must not be able to leave.');
        } catch (DomainException) {
            self::assertSame('lead', $this->teams->roleForUser(1, $teamId));
        }

        try {
            $this->teams->removeMemberForLead(1, $teamId, 1);
            self::fail('Last lead must not be removable.');
        } catch (DomainException) {
            self::assertSame('lead', $this->teams->roleForUser(1, $teamId));
        }

        self::assertTrue($this->teams->removeMemberForLead(1, $teamId, 2));
        self::assertNull($this->teams->roleForUser(2, $teamId));
    }

    public function testRemovingMemberClearsAssignmentsInTeamProjects(): void
    {
        $teamId = $this->teams->createForUser(1, 'Core Team', '');
        $this->teams->addMember($teamId, 2);
        $this->db->exec(
            'INSERT INTO projects (id, owner_user_id, owner_team_id) VALUES (50, NULL, ' . $teamId . ')'
        );
        $this->db->exec(
            'INSERT INTO tasks (id, project_id, assignee_user_id) VALUES (70, 50, 2)'
        );

        self::assertTrue($this->teams->removeMemberForLead(1, $teamId, 2));
        self::assertNull($this->db->query('SELECT assignee_user_id FROM tasks WHERE id = 70')?->fetchColumn() ?: null);
    }

    public function testLeadCanUpdateAndDeleteTeam(): void
    {
        $teamId = $this->teams->createForUser(1, 'Old', 'Old description');

        self::assertFalse($this->teams->updateForLead(2, $teamId, 'Stolen', ''));
        self::assertTrue($this->teams->updateForLead(1, $teamId, 'Renamed', 'Updated'));
        self::assertSame('Renamed', $this->teams->findForMember(1, $teamId)?->name);

        self::assertFalse($this->teams->deleteForLead(2, $teamId));
        self::assertTrue($this->teams->deleteForLead(1, $teamId));
        self::assertNull($this->teams->findForMember(1, $teamId));
    }
}
