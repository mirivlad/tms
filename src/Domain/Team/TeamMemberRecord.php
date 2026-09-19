<?php

declare(strict_types=1);

namespace Tms\Domain\Team;

final readonly class TeamMemberRecord
{
    public function __construct(
        public int $teamId,
        public int $userId,
        public string $username,
        public string $email,
        public string $role,
        public string $joinedAt,
    ) {
    }

    public function isLead(): bool
    {
        return $this->role === 'lead';
    }
}
