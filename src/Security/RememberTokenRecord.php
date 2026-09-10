<?php

declare(strict_types=1);

namespace Tms\Security;

use DateTimeImmutable;

final readonly class RememberTokenRecord
{
    public function __construct(
        public string $selector,
        public int $userId,
        public string $verifierHash,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
