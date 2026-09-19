<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Project;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Project\ProjectRepository;

final class ProjectRepositoryTest extends TestCase
{
    private PDO $db;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL,
            created_by INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            lifecycle_status TEXT NOT NULL DEFAULT "active",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec('CREATE TABLE statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            project_id INTEGER NULL,
            source_status_id INTEGER NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            color TEXT NOT NULL DEFAULT "#6b7280",
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_default INTEGER NOT NULL DEFAULT 0,
            is_completion INTEGER NOT NULL DEFAULT 0,
            show_on_board INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT "",
            status_id INTEGER NULL,
            project_id INTEGER NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec("INSERT INTO statuses (
            id, user_id, project_id, source_status_id, name, sort_order, is_default, is_completion
        ) VALUES
            (10, 1, NULL, NULL, 'Inbox', 1, 1, 0),
            (11, 1, NULL, NULL, 'Done', 2, 0, 1),
            (20, 2, NULL, NULL, 'Foreign Inbox', 1, 1, 0)");

        $this->db->exec("INSERT INTO projects (
            id, owner_user_id, owner_team_id, created_by, name, description, lifecycle_status
        ) VALUES
            (50, 2, NULL, 2, 'Foreign', 'Other user', 'active'),
            (60, NULL, 10, 2, 'Team reserved', 'Not a personal project', 'active')");

        $this->projects = new ProjectRepository($this->db);
    }

    public function testCrudIsScopedToPersonalOwner(): void
    {
        $id = $this->projects->createForUser(1, 'TOS', 'Text operating system', 'active');

        $project = $this->projects->findForUser(1, $id);
        self::assertNotNull($project);
        self::assertSame(1, $project->ownerUserId);
        self::assertNull($project->ownerTeamId);
        self::assertTrue($project->isPersonal());
        self::assertSame('TOS', $project->name);

        $cloned = $this->db->query(
            'SELECT source_status_id, is_default, is_completion
             FROM statuses
             WHERE project_id = ' . $id . '
             ORDER BY sort_order, id'
        )?->fetchAll();
        self::assertSame([
            ['source_status_id' => 10, 'is_default' => 1, 'is_completion' => 0],
            ['source_status_id' => 11, 'is_default' => 0, 'is_completion' => 1],
        ], $cloned);

        self::assertTrue($this->projects->updateForUser(1, $id, 'TOS Core', 'Updated', 'paused'));
        self::assertSame('TOS Core', $this->projects->findForUser(1, $id)?->name);
        self::assertSame('paused', $this->projects->findForUser(1, $id)?->lifecycleStatus);

        self::assertNull($this->projects->findForUser(1, 50));
        self::assertNull($this->projects->findForUser(1, 60));
        self::assertFalse($this->projects->updateForUser(1, 50, 'Stolen', '', 'active'));
        self::assertFalse($this->projects->deleteForUser(1, 50));

        $projectStatusId = (int) $this->db->query(
            'SELECT id FROM statuses WHERE project_id = ' . $id . ' AND source_status_id = 11'
        )?->fetchColumn();
        $stmt = $this->db->prepare(
            'INSERT INTO tasks (created_by, title, status_id, project_id) VALUES (1, :title, :status_id, :project_id)'
        );
        $stmt->execute(['title' => 'Project task', 'status_id' => $projectStatusId, 'project_id' => $id]);
        $taskId = (int) $this->db->lastInsertId();

        self::assertTrue($this->projects->deleteForUser(1, $id));
        self::assertNull($this->projects->findForUser(1, $id));
        $task = $this->db->query('SELECT status_id, project_id FROM tasks WHERE id = ' . $taskId)?->fetch();
        self::assertSame(['status_id' => 11, 'project_id' => null], $task);
    }

    public function testListIncludesOnlyPersonalProjectsOwnedByUser(): void
    {
        $this->projects->createForUser(1, 'Active', '', 'active');
        $this->projects->createForUser(1, 'Done', '', 'done');

        $projects = $this->projects->listForUser(1);
        self::assertCount(2, $projects);
        self::assertSame('Active', $projects[0]->name);
        self::assertSame('Done', $projects[1]->name);
    }

    public function testRejectsInvalidLifecycleAndNames(): void
    {
        try {
            $this->projects->createForUser(1, '', '', 'active');
            self::fail('Empty project name must be rejected.');
        } catch (DomainException) {
            self::assertSame([], $this->projects->listForUser(1));
        }

        $this->expectException(DomainException::class);
        $this->projects->createForUser(1, 'Project', '', 'unknown');
    }
}
