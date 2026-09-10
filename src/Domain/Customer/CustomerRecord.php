<?php

declare(strict_types=1);

namespace Tms\Domain\Customer;

final readonly class CustomerRecord
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
    ) {
    }
}
