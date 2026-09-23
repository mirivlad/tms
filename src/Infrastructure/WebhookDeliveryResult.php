<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

final readonly class WebhookDeliveryResult
{
    public function __construct(
        public bool $success,
        public ?int $httpStatus,
        public ?string $error,
    ) {
    }
}
