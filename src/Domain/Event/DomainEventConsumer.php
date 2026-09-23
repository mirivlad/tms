<?php

declare(strict_types=1);

namespace Tms\Domain\Event;

interface DomainEventConsumer
{
    public function consume(DomainEvent $event): void;
}
