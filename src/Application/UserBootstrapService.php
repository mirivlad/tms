<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;

final class UserBootstrapService
{
    public function __construct(
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
    ) {
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->statuses->listForUser($userId) === []) {
            $this->statuses->createForUser(
                userId: $userId,
                name: 'Inbox',
                color: '#64748b',
                isDefault: true,
            );
            $this->statuses->createForUser(
                userId: $userId,
                name: 'In progress',
                color: '#3b82f6',
            );
            $this->statuses->createForUser(
                userId: $userId,
                name: 'Done',
                color: '#22c55e',
                isCompletion: true,
            );
        }

        if ($this->taskTypes->listForUser($userId) === []) {
            $this->taskTypes->createForUser(
                userId: $userId,
                name: 'General',
                description: 'General-purpose tasks',
            );
        }
    }
}
