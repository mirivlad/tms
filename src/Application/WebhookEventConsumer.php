<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;
use Tms\Domain\Webhook\WebhookDeliveryRepository;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;

final class WebhookEventConsumer implements DomainEventConsumer
{
    public function __construct(
        private readonly WebhookSubscriptionRepository $subscriptions,
        private readonly WebhookDeliveryRepository $deliveries,
    ) {
    }

    public function consume(DomainEvent $event): void
    {
        try {
            foreach ($this->subscriptions->listActive() as $subscription) {
                if ($subscription->accepts($event->type)) {
                    $this->deliveries->enqueue($subscription->id, $event->id);
                }
            }
        } catch (\Throwable $error) {
            error_log('TMS webhook enqueue failed: ' . $error->getMessage());
        }
    }
}
