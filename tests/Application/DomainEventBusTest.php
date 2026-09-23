<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\DomainEventBus;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;
use Tms\Domain\Event\DomainEventRepository;

final class DomainEventBusTest extends TestCase
{
    public function testPublishJournalsBeforeDispatchingToConsumers(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE domain_events (
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

        $repository = new DomainEventRepository($db);
        $consumer = new class($repository) implements DomainEventConsumer {
            public bool $sawJournaledEvent = false;

            public function __construct(private readonly DomainEventRepository $repository)
            {
            }

            public function consume(DomainEvent $event): void
            {
                $this->sawJournaledEvent = $this->repository->find($event->id) !== null;
            }
        };

        $event = new DomainEvent(
            id: '11111111-2222-4333-8444-555555555555',
            type: 'task.created',
            schemaVersion: 1,
            actorUserId: 7,
            actorUsername: 'alice',
            taskId: 42,
            projectId: null,
            commentId: null,
            visibilityUserId: 7,
            visibilityTeamId: null,
            payload: ['subject_title' => 'Deploy', 'changes' => []],
            occurredAt: '2026-09-23 12:34:56.123456',
        );

        (new DomainEventBus($repository, [$consumer]))->publish($event);

        self::assertTrue($consumer->sawJournaledEvent);
        self::assertNotNull($repository->find($event->id));
    }
}
