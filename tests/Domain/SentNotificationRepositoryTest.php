<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Notification\SentNotificationRepository;

final class SentNotificationRepositoryTest extends TestCase
{
    public function testSuccessfulDeliveryMarkerIsIdempotentPerChannel(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE sent_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER NULL,
                channel TEXT NOT NULL,
                notification_type TEXT NOT NULL,
                dedupe_key TEXT NOT NULL,
                sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, channel, dedupe_key)
            )'
        );
        $repository = new SentNotificationRepository($db);

        self::assertFalse($repository->wasSent(7, 'email', 'tomorrow:2026-09-12'));
        self::assertTrue($repository->markSent(7, 'email', 'tomorrow', 'tomorrow:2026-09-12'));
        self::assertTrue($repository->wasSent(7, 'email', 'tomorrow:2026-09-12'));
        self::assertFalse($repository->markSent(7, 'email', 'tomorrow', 'tomorrow:2026-09-12'));

        self::assertTrue($repository->markSent(7, 'telegram', 'tomorrow', 'tomorrow:2026-09-12'));
        self::assertSame('2', (string) $db->query('SELECT COUNT(*) FROM sent_notifications')->fetchColumn());
    }
}
