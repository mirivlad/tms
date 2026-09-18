<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

final readonly class ProjectRecord
{
    public function __construct(
        public int $id,
        public ?int $ownerUserId,
        public ?int $ownerTeamId,
        public int $createdBy,
        public string $name,
        public string $description,
        public string $lifecycleStatus,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public function isPersonal(): bool
    {
        return $this->ownerUserId !== null && $this->ownerTeamId === null;
    }
}
