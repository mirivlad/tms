<?php

declare(strict_types=1);

namespace Tms\Domain\SavedView;

final readonly class SavedViewRecord
{
    /** @param array<string, mixed> $query */
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public array $query,
        public bool $isDefault,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
