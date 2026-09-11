<?php

declare(strict_types=1);

namespace Tms\Domain\CustomField;

final readonly class CustomFieldRecord
{
    /** @param list<string> $options */
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public string $type,
        public array $options,
        public bool $isRequired,
        public int $sortOrder,
    ) {
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, ['select', 'checkbox_list'], true);
    }
}
