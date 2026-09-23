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
        $db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            created_by INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            deadline TEXT NULL,
            scheduled_at TEXT NULL,
            status_id INTEGER NULL,
            type_id INTEGER NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            customer_id INTEGER NULL,
            project_id INTEGER NULL,
            assignee_user_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $db->exec('CREATE TABLE customers (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL
        )');
        $db->exec("INSERT INTO customers (id,user_id,name) VALUES
            (50,1,'Acme 100%'),(51,1,'Other customer'),(60,2,'Foreign')");

        $db->exec("INSERT INTO tasks
            (id, created_by, title, description, deadline, scheduled_at, status_id, type_id, priority, customer_id, created_at, updated_at)
            VALUES
            (1, 1, 'Deadline task', '', '2026-09-10 12:00:00', NULL, 10, 30, 3, 50, '2026-08-01 09:00:00', '2026-08-01 09:00:00'),
            (2, 1, 'No dates task', '', NULL, NULL, 10, 30, 1, 50, '2026-09-11 09:00:00', '2026-09-11 09:00:00'),
            (3, 2, 'Foreign deadline', '', '2026-09-12 12:00:00', NULL, 20, 40, 3, 60, '2026-09-01 09:00:00', '2026-09-01 09:00:00'),
            (4, 1, 'Outside range', '', '2026-10-20 12:00:00', NULL, 10, 30, 3, 50, '2026-09-01 09:00:00', '2026-09-01 09:00:00'),
            (5, 1, 'Planned task', '', '2026-10-10 17:00:00', '2026-09-15 09:00:00', 10, 30, 2, 50, '2026-08-10 09:00:00', '2026-08-10 09:00:00')");

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

    public function testNoDatesModeUsesCreationDate(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'no_dates',
        );

        self::assertCount(1, $tasks);
        self::assertSame('No dates task', $tasks[0]->title);
    }

    public function testPlannedModeUsesScheduledTimeIndependentlyFromDeadline(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'planned_only',
        );

        self::assertCount(1, $tasks);
        self::assertSame('Planned task', $tasks[0]->title);
    }

    public function testAllModeCombinesAllPlacementRules(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'all',
        );

        self::assertSame(['Deadline task', 'No dates task', 'Planned task'], array_map(
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
            statusIds: [10],
            typeIds: [30],
            customerId: 50,
            priorities: [3],
        );

        self::assertCount(1, $tasks);
        self::assertSame('Deadline task', $tasks[0]->title);
    }

    public function testCalendarSupportsMultipleValuesAndInversion(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'all',
            statusIds: [999],
            typeIds: [999],
            priorities: [3],
            statusInvert: true,
            typeInvert: true,
            priorityInvert: true,
        );

        self::assertCount(1, $tasks);
        self::assertSame('No dates task', $tasks[0]->title);
    }

    public function testCalendarCustomerSubstringEscapesLikeWildcards(): void
    {
        $tasks = $this->tasks->listCalendarForUser(
            1,
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            'all',
            customerQuery: '100%',
        );

        self::assertSame(['Deadline task', 'No dates task', 'Planned task'], array_map(
            static fn ($task): string => $task->title,
            $tasks,
        ));
    }
}
