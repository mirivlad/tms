<?php

declare(strict_types=1);

namespace Tms\Domain\Discussion;

final readonly class DiscussionCommentRecord
{
    public function __construct(
        public int $id,
        public ?int $projectId,
        public ?int $taskId,
        public ?int $parentCommentId,
        public ?int $authorUserId,
        public ?string $authorUsername,
        public string $bodyHtml,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isReply(): bool
    {
        return $this->parentCommentId !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
