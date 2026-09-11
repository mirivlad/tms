<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\I18n\Translator;

final class UserBootstrapService
{
    public function __construct(
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
        private readonly Translator $translator,
    ) {
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->statuses->listForUser($userId) === []) {
            $this->statuses->createForUser(
                userId: $userId,
                name: $this->translator->trans('defaults.status.inbox'),
                color: '#64748b',
                isDefault: true,
            );
            $this->statuses->createForUser(
                userId: $userId,
                name: $this->translator->trans('defaults.status.in_progress'),
                color: '#3b82f6',
            );
            $this->statuses->createForUser(
                userId: $userId,
                name: $this->translator->trans('defaults.status.done'),
                color: '#22c55e',
                isCompletion: true,
            );
        }

        if ($this->taskTypes->listForUser($userId) === []) {
            $this->taskTypes->createForUser(
                userId: $userId,
                name: $this->translator->trans('defaults.type.general'),
                description: $this->translator->trans('defaults.type.general_description'),
            );
        }
    }
}
