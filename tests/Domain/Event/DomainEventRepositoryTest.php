<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Event;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventRepository;

final class DomainEventRepositoryTest extends TestCase
{
    private PDO $db;
    private DomainEventRepository $events;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE domain_events (
            event_id TEXT PRIMARY KEY,
            event_type TEXT NOT NULL,
            schema_version INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            actor_username TEXT NOT NULL,
            task_id INTEGER NULL,
            project_id INTEGER NULL,
            comment_id INTEGER NULL,
            visibility_user_id INTEGER NULL,
            visibility_team_id INTEGER NULL,
            payload_json TEXT NOT NULL,
            occurred_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->events = new DomainEventRepository($this->db);
    }

    public function testAppendAndFindPreserveStableEnvelopeAndPayload(): void
    {
        $event = new DomainEvent(
            id: '11111111-2222-4333-8444-555555555555',
            type: 'task.updated',
            schemaVersion: 1,
            actorUserId: 7,
            actorUsername: 'alice',
            taskId: 42,
            projectId: null,
            commentId: null,
            visibilityUserId: 7,
            visibilityTeamId: null,
            payload: [
                'subject_title' => 'Deploy',
                'changes' => ['scheduled_at' => ['old' => null, 'new' => '2026-09-24 09:00:00']],
            ],
            occurredAt: '2026-09-23 12:34:56.123456',
        );

        $this->events->append($event);
        $stored = $this->events->find($event->id);

        self::assertNotNull($stored);
        self::assertSame($event->id, $stored->id);
        self::assertSame('task.updated', $stored->type);
        self::assertSame(1, $stored->schemaVersion);
        self::assertSame(42, $stored->taskId);
        self::assertSame(7, $stored->visibilityUserId);
        self::assertNull($stored->visibilityTeamId);
        self::assertSame($event->payload, $stored->payload);
        self::assertSame($event->occurredAt, $stored->occurredAt);
    }

    public function testAppendRejectsAmbiguousVisibility(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->events->append(new DomainEvent(
            id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            type: 'task.created',
            schemaVersion: 1,
            actorUserId: 1,
            actorUsername: 'alice',
            taskId: 1,
            projectId: null,
            commentId: null,
            visibilityUserId: 1,
            visibilityTeamId: 2,
            payload: ['subject_title' => 'Bad scope', 'changes' => []],
            occurredAt: '2026-09-23 12:00:00.000000',
        ));
    }
}
