<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use PDO;

final class SentNotificationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function wasSent(int $userId, string $channel, string $dedupeKey): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM sent_notifications
             WHERE user_id = :user_id AND channel = :channel AND dedupe_key = :dedupe_key LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'channel' => $channel,
            'dedupe_key' => hash('sha256', $dedupeKey),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function markSent(
        int $userId,
        string $channel,
        string $type,
        string $dedupeKey,
        ?int $taskId = null,
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO sent_notifications
                (user_id, task_id, channel, notification_type, dedupe_key, sent_at)
             VALUES (:user_id, :task_id, :channel, :notification_type, :dedupe_key, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'task_id' => $taskId,
            'channel' => $channel,
            'notification_type' => $type,
            'dedupe_key' => hash('sha256', $dedupeKey),
        ]);
        return $stmt->rowCount() === 1;
    }
}
