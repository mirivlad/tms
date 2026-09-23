<?php

declare(strict_types=1);

namespace Tms\Domain\Webhook;

final readonly class WebhookSubscriptionRecord
{
    /** @param list<string> $eventTypes */
    public function __construct(
        public int $id,
        public string $name,
        public string $endpointUrl,
        public string $secretCiphertext,
        public array $eventTypes,
        public bool $isActive,
        public string $activeSince,
        public int $createdBy,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public function accepts(string $eventType): bool
    {
        return in_array($eventType, $this->eventTypes, true);
    }
}
