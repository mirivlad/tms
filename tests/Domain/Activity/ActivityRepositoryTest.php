<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Activity;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Task\TaskRecord;

final class ActivityRepositoryTest extends TestCase
{
    private PDO $db;
    private ActivityRepository $activity;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL,
            name TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE task_types (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE activity_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NULL,
            actor_username TEXT NOT NULL,
            event_type TEXT NOT NULL,
            task_id INTEGER NULL,
            project_id INTEGER NULL,
            visibility_user_id INTEGER NULL,
            visibility_team_id INTEGER NULL,
            payload_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec("INSERT INTO users (id,username) VALUES
            (1,'alice'),(2,'bob'),(3,'carol')");
        $this->db->exec("INSERT INTO teams (id,name) VALUES (7,'Ops'),(8,'Other')");
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES
            (7,1,'lead'),(7,2,'member'),(8,3,'lead')");
        $this->db->exec("INSERT INTO projects (id,owner_user_id,owner_team_id,name) VALUES
            (90,NULL,7,'Shared')");
        $this->db->exec("INSERT INTO statuses (id,name) VALUES (10,'Todo'),(11,'Done')");
        $this->db->exec("INSERT INTO task_types (id,name) VALUES (20,'General')");
        $this->db->exec("INSERT INTO customers (id,name) VALUES (30,'Acme')");

        $this->activity = new ActivityRepository($this->db);
    }

    public function testTaskHistoryUsesSnapshottedTeamVisibilityAndHumanLabels(): void
    {
        $before = new TaskRecord(
            id: 200,
            ownerId: 1,
            title: 'Deploy',
            description: '<p>Old</p>',
            deadline: null,
            statusId: 10,
            typeId: 20,
            priority: 0,
            customerId: 30,
            projectId: 90,
            assigneeUserId: 2,
            createdAt: '2026-09-22 10:00:00',
            updatedAt: '2026-09-22 10:00:00',
        );
        $after = new TaskRecord(
            id: 200,
            ownerId: 1,
            title: 'Deploy',
            description: '<p>New</p>',
            deadline: '2026-09-23 12:00:00',
            statusId: 11,
            typeId: 20,
            priority: 2,
            customerId: 30,
            projectId: 90,
            assigneeUserId: 2,
            createdAt: '2026-09-22 10:00:00',
            updatedAt: '2026-09-22 11:00:00',
        );

        $this->activity->recordTaskCreated(1, $before);
        $this->activity->recordTaskChanged(2, $before, $after);

        $events = $this->activity->listForTask(1, 200);
        self::assertCount(2, $events);
        self::assertCount(2, $this->activity->listForTask(2, 200));
        self::assertSame([], $this->activity->listForTask(3, 200));

        $update = $events[0];
        self::assertSame('task.updated', $update->eventType);
        self::assertSame('bob', $update->actorUsername);
        self::assertSame(['old' => 'Todo', 'new' => 'Done'], $update->changes['status']);
        self::assertSame(['old' => 'low', 'new' => 'high'], $update->changes['priority']);
        self::assertSame(['old' => null, 'new' => null], $update->changes['description']);
    }

    public function testProjectOwnershipTransferDoesNotExposeOldTeamHistoryToNewTeam(): void
    {
        $before = new ProjectRecord(
            id: 90,
            ownerUserId: null,
            ownerTeamId: 7,
            createdBy: 1,
            name: 'Shared',
            description: 'Before',
            lifecycleStatus: 'active',
            createdAt: '2026-09-22 10:00:00',
            updatedAt: '2026-09-22 10:00:00',
        );
        $this->activity->recordProjectCreated(1, $before);

        $this->db->exec('UPDATE projects SET owner_team_id = 8 WHERE id = 90');
        $after = new ProjectRecord(
            id: 90,
            ownerUserId: null,
            ownerTeamId: 8,
            createdBy: 1,
            name: 'Shared',
            description: 'After',
            lifecycleStatus: 'paused',
            createdAt: '2026-09-22 10:00:00',
            updatedAt: '2026-09-22 12:00:00',
        );
        $this->activity->recordProjectChanged(3, $before, $after);

        $oldTeam = $this->activity->listForProject(1, 90);
        self::assertCount(1, $oldTeam);
        self::assertSame('project.created', $oldTeam[0]->eventType);

        $newTeam = $this->activity->listForProject(3, 90);
        self::assertCount(1, $newTeam);
        self::assertSame('project.updated', $newTeam[0]->eventType);
        self::assertSame(['old' => null, 'new' => 'Other'], $newTeam[0]->changes['owner']);
        self::assertSame(['old' => null, 'new' => 'paused'], $newTeam[0]->changes['lifecycle_status']);
    }
}
