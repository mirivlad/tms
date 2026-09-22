<?php

declare(strict_types=1);

namespace Tms\Domain\Activity;

final readonly class ActivityRecord
{
    /**
     * @param array<string, array{old:?string,new:?string}> $changes
     */
    public function __construct(
        public int $id,
        public ?int $actorUserId,
        public string $actorUsername,
        public string $eventType,
        public ?int $taskId,
        public ?int $projectId,
        public ?string $subjectTitle,
        public array $changes,
        public string $createdAt,
    ) {
    }
}
