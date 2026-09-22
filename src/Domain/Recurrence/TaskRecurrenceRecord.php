<?php

declare(strict_types=1);

namespace Tms\Domain\Recurrence;

final readonly class TaskRecurrenceRecord
{
    public function __construct(
        public int $id,
        public int $ownerUserId,
        public int $currentTaskId,
        public string $mode,
        public int $intervalValue,
        public ?int $spawnStatusId,
        public string $timezone,
        public ?int $anchorDay,
        public ?string $nextDeadline,
        public ?string $nextRunAt,
        public int $sequence,
        public bool $isActive,
        public ?string $lastGeneratedAt,
        public ?string $lastError,
    ) {
    }
}
