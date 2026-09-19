<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use PDO;

final class InternalNotificationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        int $userId,
        ?int $actorUserId,
        string $actorUsername,
        string $notificationType,
        string $contextLabel,
        string $bodyPreview,
        string $targetUrl,
        string $dedupeKey,
        ?int $projectId = null,
        ?int $taskId = null,
        ?int $commentId = null,
    ): ?int {
        $existing = $this->findIdByDedupe($userId, $dedupeKey);
        if ($existing !== null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO internal_notifications (
                user_id, actor_user_id, actor_username, notification_type,
                context_label, body_preview, target_url,
                project_id, task_id, comment_id, dedupe_key, read_at, created_at
             ) VALUES (
                :user_id, :actor_user_id, :actor_username, :notification_type,
                :context_label, :body_preview, :target_url,
                :project_id, :task_id, :comment_id, :dedupe_key, NULL, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'actor_user_id' => $actorUserId,
            'actor_username' => $actorUsername,
            'notification_type' => $notificationType,
            'context_label' => $contextLabel,
            'body_preview' => $bodyPreview,
            'target_url' => $targetUrl,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'comment_id' => $commentId,
            'dedupe_key' => $dedupeKey,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<InternalNotificationRecord> */
    public function listForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            'SELECT id, user_id, actor_user_id, actor_username, notification_type,
                    context_label, body_preview, target_url,
                    project_id, task_id, comment_id, read_at, created_at
             FROM internal_notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    public function findForUser(int $userId, int $notificationId): ?InternalNotificationRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, actor_user_id, actor_username, notification_type,
                    context_label, body_preview, target_url,
                    project_id, task_id, comment_id, read_at, created_at
             FROM internal_notifications
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function countUnreadForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM internal_notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markReadForUser(int $userId, int $notificationId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE internal_notifications
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function markAllReadForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE internal_notifications
             SET read_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private function findIdByDedupe(int $userId, string $dedupeKey): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM internal_notifications
             WHERE user_id = :user_id AND dedupe_key = :dedupe_key
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'dedupe_key' => $dedupeKey]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): InternalNotificationRecord
    {
        return new InternalNotificationRecord(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            actorUserId: $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            actorUsername: (string) $row['actor_username'],
            notificationType: (string) $row['notification_type'],
            contextLabel: (string) $row['context_label'],
            bodyPreview: (string) $row['body_preview'],
            targetUrl: (string) $row['target_url'],
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            taskId: $row['task_id'] !== null ? (int) $row['task_id'] : null,
            commentId: $row['comment_id'] !== null ? (int) $row['comment_id'] : null,
            readAt: $row['read_at'] !== null ? (string) $row['read_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }
}
