<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Project;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Project\ProjectStatusRepository;

final class ProjectStatusRepositoryTest extends TestCase
{
    private PDO $db;
    private ProjectStatusRepository $statuses;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
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
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (project_id, name)
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by INTEGER NOT NULL,
            status_id INTEGER NULL,
            project_id INTEGER NULL
        )');

        $this->db->exec('INSERT INTO projects (id, owner_user_id, owner_team_id) VALUES
            (10, 1, NULL),
            (20, 2, NULL),
            (30, NULL, 7)');

        $this->db->exec("INSERT INTO statuses (
            id, user_id, project_id, source_status_id, name, sort_order,
            is_default, is_completion, show_on_board
        ) VALUES
            (100, NULL, 10, 1, 'Inbox', 1, 1, 0, 1),
            (101, NULL, 10, 2, 'Done', 2, 0, 1, 1),
            (200, NULL, 20, 3, 'Foreign', 1, 1, 1, 1),
            (300, NULL, 30, NULL, 'Team reserved', 1, 1, 0, 1)");

        $this->statuses = new ProjectStatusRepository($this->db);
    }

    public function testListAndFindStayInsideOwnedProject(): void
    {
        $records = $this->statuses->listForProject(1, 10);
        self::assertSame([100, 101], array_map(static fn ($status): int => $status->id, $records));
        self::assertSame(10, $records[0]->projectId);
        self::assertNull($records[0]->userId);
        self::assertTrue($records[0]->isProjectScoped());

        self::assertNotNull($this->statuses->findForProject(1, 10, 100));
        self::assertNull($this->statuses->findForProject(1, 10, 200));
        self::assertSame([], $this->statuses->listForProject(1, 20));
        self::assertSame([], $this->statuses->listForProject(1, 30));
    }

    public function testRolesAreExclusiveInsideProjectOnly(): void
    {
        $new = $this->statuses->createForProject(
            1,
            10,
            'Review',
            'Ready for review',
            '#123456',
            isDefault: true,
            isCompletion: true,
        );

        self::assertTrue($this->statuses->findForProject(1, 10, $new)?->isDefault);
        self::assertTrue($this->statuses->findForProject(1, 10, $new)?->isCompletion);
        self::assertFalse($this->statuses->findForProject(1, 10, 100)?->isDefault);
        self::assertFalse($this->statuses->findForProject(1, 10, 101)?->isCompletion);

        self::assertTrue($this->statuses->findForProject(2, 20, 200)?->isDefault);
        self::assertTrue($this->statuses->findForProject(2, 20, 200)?->isCompletion);
    }

    public function testUsedDefaultAndCompletionStatusesCannotBeDeleted(): void
    {
        $working = $this->statuses->createForProject(1, 10, 'Working');
        $this->db->exec('INSERT INTO tasks (created_by, status_id, project_id) VALUES (1, ' . $working . ', 10)');

        self::assertFalse($this->statuses->deleteForProject(1, 10, 100));
        self::assertFalse($this->statuses->deleteForProject(1, 10, 101));
        self::assertFalse($this->statuses->deleteForProject(1, 10, $working));

        $this->db->exec('DELETE FROM tasks WHERE status_id = ' . $working);
        self::assertTrue($this->statuses->deleteForProject(1, 10, $working));
    }

    public function testReorderRequiresTheExactOwnedProjectSet(): void
    {
        self::assertFalse($this->statuses->reorderForProject(1, 10, [101]));
        self::assertFalse($this->statuses->reorderForProject(1, 10, [101, 200]));
        self::assertTrue($this->statuses->reorderForProject(1, 10, [101, 100]));
        self::assertSame(
            [101, 100],
            array_map(static fn ($status): int => $status->id, $this->statuses->listForProject(1, 10)),
        );
    }

    public function testCannotCreateStatusForForeignOrReservedTeamProject(): void
    {
        try {
            $this->statuses->createForProject(1, 20, 'Stolen');
            self::fail('Foreign project must be rejected.');
        } catch (DomainException) {
            self::assertNull($this->statuses->findForProject(2, 20, 201));
        }

        $this->expectException(DomainException::class);
        $this->statuses->createForProject(1, 30, 'Premature team status');
    }
}
