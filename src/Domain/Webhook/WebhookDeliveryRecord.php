<?php

declare(strict_types=1);

namespace Tms\Domain\Webhook;

final readonly class WebhookDeliveryRecord
{
    public function __construct(
        public int $id,
        public int $subscriptionId,
        public string $eventId,
        public string $status,
        public int $attemptCount,
        public ?string $nextAttemptAt,
        public ?string $lastAttemptAt,
        public ?int $responseStatus,
        public ?string $lastError,
        public ?string $deliveredAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
