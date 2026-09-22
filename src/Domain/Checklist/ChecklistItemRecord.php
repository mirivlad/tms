<?php

declare(strict_types=1);

namespace Tms\Domain\Checklist;

final readonly class ChecklistItemRecord
{
    public function __construct(
        public int $id,
        public int $taskId,
        public string $text,
        public bool $isCompleted,
        public int $sortOrder,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
