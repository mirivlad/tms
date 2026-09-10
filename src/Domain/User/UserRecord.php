<?php

declare(strict_types=1);

namespace Tms\Domain\User;

final readonly class UserRecord
{
    public function __construct(
        public int $id,
        public string $username,
        public string $email,
        public string $passwordHash,
        public string $role,
        public bool $isActive,
        public bool $isApproved,
    ) {
    }
}
