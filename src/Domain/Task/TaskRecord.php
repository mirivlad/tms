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
        public ?int $statusId,
        public ?int $typeId,
        public int $priority,
        public ?int $customerId,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
