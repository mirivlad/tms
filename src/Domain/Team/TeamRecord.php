<?php

declare(strict_types=1);

namespace Tms\Domain\Team;

final readonly class TeamRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public ?int $createdBy,
        public string $createdAt,
        public string $updatedAt,
        public string $currentUserRole,
    ) {
    }

    public function currentUserIsLead(): bool
    {
        return $this->currentUserRole === 'lead';
    }
}
