<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Task;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Task\TaskRepository;

final class TaskRepositoryTest extends TestCase
{
    private PDO $db;
    private TaskRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec(
            'CREATE TABLE statuses (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL
            )'
        );

        $this->db->exec(
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY,
                created_by INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                deadline TEXT NULL,
                status_id INTEGER NULL,
                type_id INTEGER NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                customer_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->db->exec("INSERT INTO statuses (id, user_id, name) VALUES (10, 1, 'Todo'), (20, 2, 'Private')");
        $this->db->exec(
            "INSERT INTO tasks (id, created_by, title, description, status_id, priority, created_at, updated_at) VALUES
                (100, 1, 'User one task', '', 10, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
                (200, 2, 'User two task', '', 20, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->repository = new TaskRepository($this->db);
    }

    public function testFindCannotReadAnotherUsersTask(): void
    {
        self::assertNull($this->repository->findForUser(1, 200));
        self::assertSame('User one task', $this->repository->findForUser(1, 100)?->title);
    }

    public function testListContainsOnlyCurrentUsersTasks(): void
    {
        $tasks = $this->repository->listForUser(1);

        self::assertCount(1, $tasks);
        self::assertSame(100, $tasks[0]->id);
        self::assertSame(1, $tasks[0]->ownerId);
    }

    public function testStatusCannotBeChangedOnAnotherUsersTask(): void
    {
        self::assertFalse($this->repository->updateStatusForUser(1, 200, 10));
        self::assertSame(20, $this->statusOf(200));
    }

    public function testTaskCannotBeMovedToAnotherUsersStatus(): void
    {
        self::assertFalse($this->repository->updateStatusForUser(1, 100, 20));
        self::assertSame(10, $this->statusOf(100));
    }

    public function testOwnerCanChangeTaskStatus(): void
    {
        $this->db->exec("INSERT INTO statuses (id, user_id, name) VALUES (11, 1, 'Doing')");

        self::assertTrue($this->repository->updateStatusForUser(1, 100, 11));
        self::assertSame(11, $this->statusOf(100));
    }

    public function testDeleteCannotRemoveAnotherUsersTask(): void
    {
        self::assertFalse($this->repository->deleteForUser(1, 200));
        self::assertNotNull($this->repository->findForUser(2, 200));

        self::assertTrue($this->repository->deleteForUser(1, 100));
        self::assertNull($this->repository->findForUser(1, 100));
    }

    private function statusOf(int $taskId): int
    {
        $stmt = $this->db->prepare('SELECT status_id FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $taskId]);

        return (int) $stmt->fetchColumn();
    }
}
