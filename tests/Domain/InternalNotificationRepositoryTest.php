<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Notification\InternalNotificationRepository;

final class InternalNotificationRepositoryTest extends TestCase
{
    private PDO $db;
    private InternalNotificationRepository $notifications;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE internal_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            actor_username TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            context_label TEXT NOT NULL,
            body_preview TEXT NOT NULL DEFAULT "",
            target_url TEXT NOT NULL,
            project_id INTEGER NULL,
            task_id INTEGER NULL,
            comment_id INTEGER NULL,
            dedupe_key TEXT NOT NULL,
            read_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, dedupe_key)
        )');
        $this->notifications = new InternalNotificationRepository($this->db);
    }

    public function testCreateDedupeUnreadAndOpenState(): void
    {
        $id = $this->notifications->create(
            userId: 2,
            actorUserId: 1,
            actorUsername: 'lead',
            notificationType: 'discussion_mention',
            contextLabel: 'Shared project',
            bodyPreview: '@member hello',
            targetUrl: '/projects/7#comment-9',
            dedupeKey: hash('sha256', 'mention:9:2'),
            projectId: 7,
            commentId: 9,
        );
        self::assertNotNull($id);
        self::assertNull($this->notifications->create(
            userId: 2,
            actorUserId: 1,
            actorUsername: 'lead',
            notificationType: 'discussion_mention',
            contextLabel: 'Shared project',
            bodyPreview: '@member hello again',
            targetUrl: '/projects/7#comment-9',
            dedupeKey: hash('sha256', 'mention:9:2'),
            projectId: 7,
            commentId: 9,
        ));

        self::assertSame(1, $this->notifications->countUnreadForUser(2));
        self::assertSame(0, $this->notifications->countUnreadForUser(1));
        $records = $this->notifications->listForUser(2);
        self::assertCount(1, $records);
        self::assertTrue($records[0]->isUnread());
        self::assertSame('/projects/7#comment-9', $records[0]->targetUrl);

        self::assertTrue($this->notifications->markReadForUser(2, (int) $id));
        self::assertSame(0, $this->notifications->countUnreadForUser(2));
        self::assertFalse($this->notifications->findForUser(2, (int) $id)?->isUnread() ?? true);
        self::assertNull($this->notifications->findForUser(1, (int) $id));
    }

    public function testMarkAllReadOnlyTouchesRecipient(): void
    {
        foreach ([11, 12] as $commentId) {
            $this->notifications->create(
                userId: 2,
                actorUserId: 1,
                actorUsername: 'lead',
                notificationType: 'discussion_reply',
                contextLabel: 'Task',
                bodyPreview: 'reply',
                targetUrl: '/tasks/5/edit#comment-' . $commentId,
                dedupeKey: hash('sha256', 'reply:' . $commentId . ':2'),
                taskId: 5,
                commentId: $commentId,
            );
        }
        $this->notifications->create(
            userId: 3,
            actorUserId: 1,
            actorUsername: 'lead',
            notificationType: 'discussion_reply',
            contextLabel: 'Task',
            bodyPreview: 'reply',
            targetUrl: '/tasks/5/edit#comment-13',
            dedupeKey: hash('sha256', 'reply:13:3'),
            taskId: 5,
            commentId: 13,
        );

        $this->notifications->markAllReadForUser(2);
        self::assertSame(0, $this->notifications->countUnreadForUser(2));
        self::assertSame(1, $this->notifications->countUnreadForUser(3));
    }
}
