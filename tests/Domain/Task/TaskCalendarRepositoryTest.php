<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Task;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Task\TaskRepository;

final class TaskCalendarRepositoryTest extends TestCase
{
    private TaskRepository $tasks;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE tasks (
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
        )');

        $db->exec("INSERT INTO tasks
            (id, created_by, title, description, deadline, status_id, type_id, priority, customer_id, created_at, updated_at)
            VALUES
            (1, 1, 'Deadline task', '', '2026-09-10 12:00:00', 10, 30, 3, 50, '2026-08-01 09:00:00', '2026-08-01 09:00:00'),
            (2, 1, 'No deadline task', '', NULL, 10, 30, 1, 50, '2026-09-11 09:00:00', '2026-09-11 09:00:00'),
            (3, 2, 'Foreign deadline', '', '2026-09-12 12:00:00', 20, 40, 3, 60, '2026-09-01 09:00:00', '2026-09-01 09:00:00'),
            (4, 1, 'Outside range', '', '2026-10-20 12:00:00', 10, 30, 3, 50, '2026-09-01 09:00:00', '2026-09-01 09:00:00')");

        $this->tasks = new TaskRepository($db);
    }

    public function testDeadlineModeIsOwnerScopedAndRangeScoped(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'deadlines_only',
        );

        self::assertCount(1, $tasks);
        self::assertSame('Deadline task', $tasks[0]->title);
    }

    public function testNoDeadlineModeUsesCreationDate(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'no_deadlines',
        );

        self::assertCount(1, $tasks);
        self::assertSame('No deadline task', $tasks[0]->title);
    }

    public function testAllModeCombinesBothPlacementRules(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'all',
        );

        self::assertSame(['Deadline task', 'No deadline task'], array_map(
            static fn ($task): string => $task->title,
            $tasks,
        ));
    }

    public function testCalendarFiltersStayOwnerScoped(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'all',
            statusId: 10,
            typeId: 30,
            customerId: 50,
            priority: 3,
        );

        self::assertCount(1, $tasks);
        self::assertSame('Deadline task', $tasks[0]->title);
    }
}
