<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Task;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Task\TaskRepository;

final class TaskCrudRepositoryTest extends TestCase
{
    private PDO $db;
    private TaskRepository $tasks;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE statuses (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL,
            is_completion INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec('CREATE TABLE task_types (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE customers (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            deadline TEXT NULL,
            status_id INTEGER NULL,
            type_id INTEGER NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            customer_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec("INSERT INTO statuses (id,user_id,name,is_completion) VALUES
            (10,1,'Inbox',0),(11,1,'Done',1),(20,2,'Foreign',0)");
        $this->db->exec("INSERT INTO task_types (id,user_id,name) VALUES (30,1,'General'),(40,2,'Foreign')");
        $this->db->exec("INSERT INTO customers (id,user_id,name) VALUES (50,1,'Acme 100%'),(60,2,'Foreign')");
        $this->db->exec("INSERT INTO tasks (id,created_by,title,description,status_id,type_id,priority,customer_id) VALUES
            (200,2,'Other user task','',20,40,1,60)");

        $this->tasks = new TaskRepository($this->db);
    }

    public function testCreateAndUpdateUseOnlyOwnedMetadata(): void
    {
        $id = $this->tasks->createForUser(1, 'First task', 'Body', null, 10, 30, 2, 50);
        $task = $this->tasks->findForUser(1, $id);

        self::assertNotNull($task);
        self::assertSame('First task', $task->title);
        self::assertSame(10, $task->statusId);
        self::assertSame(30, $task->typeId);
        self::assertSame(50, $task->customerId);

        self::assertTrue($this->tasks->updateForUser(1, $id, 'Renamed', 'Updated', null, 11, null, 3, null));
        self::assertSame('Renamed', $this->tasks->findForUser(1, $id)?->title);
        self::assertSame(11, $this->tasks->findForUser(1, $id)?->statusId);
    }

    public function testCreateRejectsAnotherUsersStatus(): void
    {
        $this->expectException(DomainException::class);
        $this->tasks->createForUser(1, 'Blocked', '', null, 20, null, 1, null);
    }

    public function testUpdateRejectsAnotherUsersTypeAndCustomer(): void
    {
        $id = $this->tasks->createForUser(1, 'Owned', '', null, 10, 30, 1, 50);

        try {
            $this->tasks->updateForUser(1, $id, 'Owned', '', null, 10, 40, 1, 50);
            self::fail('Foreign task type should have been rejected.');
        } catch (DomainException) {
            self::assertSame(30, $this->tasks->findForUser(1, $id)?->typeId);
        }

        $this->expectException(DomainException::class);
        $this->tasks->updateForUser(1, $id, 'Owned', '', null, 10, 30, 1, 60);
    }

    public function testCannotUpdateAnotherUsersTask(): void
    {
        self::assertFalse($this->tasks->updateForUser(1, 200, 'Stolen', '', null, 10, null, 1, null));
        self::assertSame('Other user task', $this->tasks->findForUser(2, 200)?->title);
    }

    public function testFiltersStayInsideOwnerAndTreatSearchWildcardsLiterally(): void
    {
        $this->tasks->createForUser(1, 'Acme 100% rollout', 'literal percent', null, 10, 30, 3, 50);
        $this->tasks->createForUser(1, 'Unrelated', 'nothing here', null, 10, 30, 1, null);

        $results = $this->tasks->listFilteredForUser(1, priority: 3, query: '100%');
        self::assertCount(1, $results);
        self::assertSame('Acme 100% rollout', $results[0]->title);
    }

    public function testOverdueExcludesCompletionStatus(): void
    {
        $past = '2020-01-01 10:00:00';
        $this->tasks->createForUser(1, 'Late open', '', $past, 10, null, 1, null);
        $this->tasks->createForUser(1, 'Late but done', '', $past, 11, null, 1, null);

        $results = $this->tasks->listFilteredForUser(1, overdue: true);
        self::assertCount(1, $results);
        self::assertSame('Late open', $results[0]->title);
    }
}
