<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

final readonly class InternalNotificationRecord
{
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $actorUserId,
        public string $actorUsername,
        public string $notificationType,
        public string $contextLabel,
        public string $bodyPreview,
        public string $targetUrl,
        public ?int $projectId,
        public ?int $taskId,
        public ?int $commentId,
        public ?string $readAt,
        public string $createdAt,
    ) {
    }

    public function isUnread(): bool
    {
        return $this->readAt === null;
    }
}
