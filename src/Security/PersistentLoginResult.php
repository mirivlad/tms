<?php

declare(strict_types=1);

namespace Tms\Security;

final readonly class PersistentLoginResult
{
    public function __construct(
        public int $userId,
        public RememberToken $rotatedToken,
    ) {
    }
}
