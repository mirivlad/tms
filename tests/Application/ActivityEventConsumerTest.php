<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\ActivityEventConsumer;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Event\DomainEvent;

final class ActivityEventConsumerTest extends TestCase
{
    public function testSameDomainEventIsConsumedOnlyOnce(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE activity_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_event_id TEXT NULL UNIQUE,
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

        $consumer = new ActivityEventConsumer(new ActivityRepository($db));
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
                'changes' => ['status' => ['old' => 'Todo', 'new' => 'Done']],
            ],
            occurredAt: '2026-09-23 12:34:56.123456',
        );

        $consumer->consume($event);
        $consumer->consume($event);

        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM activity_events')->fetchColumn());
        self::assertSame(
            $event->id,
            (string) $db->query('SELECT source_event_id FROM activity_events LIMIT 1')->fetchColumn(),
        );
    }

    public function testDiscussionEventsDoNotBecomeTaskProjectActivityRows(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE activity_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_event_id TEXT NULL UNIQUE,
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

        $consumer = new ActivityEventConsumer(new ActivityRepository($db));
        $consumer->consume(new DomainEvent(
            id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            type: 'discussion.comment.created',
            schemaVersion: 1,
            actorUserId: 7,
            actorUsername: 'alice',
            taskId: 42,
            projectId: 9,
            commentId: 3,
            visibilityUserId: null,
            visibilityTeamId: 5,
            payload: ['subject_title' => 'Deploy', 'changes' => []],
            occurredAt: '2026-09-23 12:34:56.123456',
        ));

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM activity_events')->fetchColumn());
    }
}
