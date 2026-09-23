<?php

declare(strict_types=1);

namespace Tms\Domain\Task;

final readonly class TaskRecord
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $title,
        public string $description,
        public ?string $deadline,
        public ?string $scheduledAt,
        public ?int $statusId,
        public ?int $typeId,
        public int $priority,
        public ?int $customerId,
        public ?int $projectId,
        public ?int $assigneeUserId,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
