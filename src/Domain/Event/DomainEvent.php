<?php

declare(strict_types=1);

namespace Tms\Domain\Event;

final readonly class DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $schemaVersion,
        public ?int $actorUserId,
        public string $actorUsername,
        public ?int $taskId,
        public ?int $projectId,
        public ?int $commentId,
        public ?int $visibilityUserId,
        public ?int $visibilityTeamId,
        public array $payload,
        public string $occurredAt,
    ) {
    }
}
