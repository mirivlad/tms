<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;
use Tms\Domain\Event\DomainEventRepository;

final class DomainEventBus
{
    /** @param list<DomainEventConsumer> $consumers */
    public function __construct(
        private readonly DomainEventRepository $events,
        private readonly array $consumers,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        $this->events->append($event);
        foreach ($this->consumers as $consumer) {
            $consumer->consume($event);
        }
    }
}
