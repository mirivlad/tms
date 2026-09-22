<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Checklist;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Checklist\ChecklistRepository;

final class ChecklistRepositoryTest extends TestCase
{
    private PDO $db;
    private ChecklistRepository $checklists;

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
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            created_by INTEGER NOT NULL,
            project_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE task_checklist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            item_text TEXT NOT NULL,
            is_completed INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec("INSERT INTO projects (id,owner_user_id,owner_team_id) VALUES
            (10,1,NULL),(20,NULL,7),(30,2,NULL)");
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES
            (7,1,'lead'),(7,2,'member')");
        $this->db->exec("INSERT INTO tasks (id,created_by,project_id) VALUES
            (100,1,NULL),(110,1,10),(120,2,20),(130,2,30),(140,2,NULL)");

        $this->checklists = new ChecklistRepository($this->db);
    }

    public function testCrudOrderAndProgressFollowParentTaskAccess(): void
    {
        $first = $this->checklists->createForTask(1, 100, 'First');
        $second = $this->checklists->createForTask(1, 100, 'Second');

        self::assertSame(['First', 'Second'], array_map(
            static fn ($item): string => $item->text,
            $this->checklists->listForTask(1, 100),
        ));

        self::assertTrue($this->checklists->setCompleted(1, 100, $first, true));
        self::assertTrue($this->checklists->updateText(1, 100, $second, 'Renamed'));
        self::assertTrue($this->checklists->move(1, 100, $second, 'up'));

        $items = $this->checklists->listForTask(1, 100);
        self::assertSame(['Renamed', 'First'], array_map(static fn ($item): string => $item->text, $items));

        $progress = $this->checklists->progressForTasks(1, [100]);
        self::assertSame(['total' => 2, 'completed' => 1], $progress[100]);

        self::assertTrue($this->checklists->delete(1, 100, $first));
        self::assertCount(1, $this->checklists->listForTask(1, 100));
    }

    public function testTeamTaskIsSharedButForeignPersonalTasksRemainPrivate(): void
    {
        $shared = $this->checklists->createForTask(1, 120, 'Shared');
        self::assertSame('Shared', $this->checklists->findForTask(2, 120, $shared)?->text);
        self::assertTrue($this->checklists->updateText(2, 120, $shared, 'Edited by teammate'));
        self::assertSame('Edited by teammate', $this->checklists->findForTask(1, 120, $shared)?->text);

        self::assertSame([], $this->checklists->listForTask(1, 130));
        self::assertSame([], $this->checklists->listForTask(1, 140));

        $this->expectException(DomainException::class);
        $this->checklists->createForTask(1, 130, 'Must fail');
    }

    public function testTextValidationAndForeignItemIdsCannotEscapeTaskBoundary(): void
    {
        $owned = $this->checklists->createForTask(1, 100, 'Owned');
        $foreign = $this->checklists->createForTask(2, 140, 'Foreign');

        self::assertNull($this->checklists->findForTask(1, 100, $foreign));
        self::assertFalse($this->checklists->delete(1, 100, $foreign));
        self::assertNotNull($this->checklists->findForTask(1, 100, $owned));

        $this->expectException(DomainException::class);
        $this->checklists->updateText(1, 100, $owned, str_repeat('x', 501));
    }
}
