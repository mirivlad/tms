<?php

declare(strict_types=1);

namespace Tms\Domain\Team;

final readonly class TeamInvitationRecord
{
    public function __construct(
        public int $id,
        public int $teamId,
        public string $teamName,
        public int $invitedUserId,
        public string $invitedUsername,
        public string $invitedEmail,
        public ?int $invitedBy,
        public ?string $invitedByUsername,
        public string $status,
        public string $expiresAt,
        public string $createdAt,
        public ?string $respondedAt,
    ) {
    }
}
