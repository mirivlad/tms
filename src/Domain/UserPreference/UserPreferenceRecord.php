<?php

declare(strict_types=1);

namespace Tms\Domain\UserPreference;

final readonly class UserPreferenceRecord
{
    public function __construct(
        public int $userId,
        public string $timezone,
        public string $theme,
    ) {
    }
}
