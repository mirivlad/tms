<?php

declare(strict_types=1);

namespace Tms\Domain\TaskType;

final readonly class TaskTypeRecord
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public string $description,
        public int $sortOrder,
    ) {
    }
}
