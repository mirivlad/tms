<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;

final readonly class ActivityEventConsumer implements DomainEventConsumer
{
    public function __construct(private ActivityRepository $activity)
    {
    }

    public function consume(DomainEvent $event): void
    {
        if ((!str_starts_with($event->type, 'task.') && !str_starts_with($event->type, 'project.'))
            || in_array($event->type, ['task.deleted', 'project.deleted'], true)) {
            return;
        }
        $this->activity->recordDomainEvent($event);
    }
}
