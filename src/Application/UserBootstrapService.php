<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Status\StatusRecord;
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

    /**
     * Re-add the current application's missing status templates without changing
     * roles that the user already assigned to existing statuses.
     */
    public function restoreMissingStatuses(int $userId): int
    {
        $existing = $this->statuses->listForUser($userId);
        $names = array_fill_keys(
            array_map(static fn (StatusRecord $status): string => $status->name, $existing),
            true,
        );
        $hasDefault = $this->hasDefault($existing);
        $hasCompletion = $this->hasCompletion($existing);

        $templates = [
            [
                'name' => $this->translator->trans('defaults.status.inbox'),
                'color' => '#64748b',
                'default' => true,
                'completion' => false,
            ],
            [
                'name' => $this->translator->trans('defaults.status.in_progress'),
                'color' => '#3b82f6',
                'default' => false,
                'completion' => false,
            ],
            [
                'name' => $this->translator->trans('defaults.status.done'),
                'color' => '#22c55e',
                'default' => false,
                'completion' => true,
            ],
        ];

        $created = 0;
        foreach ($templates as $template) {
            if (isset($names[$template['name']])) {
                continue;
            }

            $makeDefault = $template['default'] && !$hasDefault;
            $makeCompletion = $template['completion'] && !$hasCompletion;
            $this->statuses->createForUser(
                userId: $userId,
                name: $template['name'],
                color: $template['color'],
                isDefault: $makeDefault,
                isCompletion: $makeCompletion,
            );

            $names[$template['name']] = true;
            $hasDefault = $hasDefault || $makeDefault;
            $hasCompletion = $hasCompletion || $makeCompletion;
            $created++;
        }

        return $created;
    }

    /** @param list<StatusRecord> $statuses */
    private function hasDefault(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if ($status->isDefault) {
                return true;
            }
        }
        return false;
    }

    /** @param list<StatusRecord> $statuses */
    private function hasCompletion(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if ($status->isCompletion) {
                return true;
            }
        }
        return false;
    }
}
