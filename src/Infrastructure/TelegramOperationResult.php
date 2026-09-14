<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

final readonly class TelegramOperationResult
{
    public function __construct(
        public bool $success,
        public string $code,
        public ?int $httpStatus = null,
        public ?string $description = null,
    ) {
    }
}
