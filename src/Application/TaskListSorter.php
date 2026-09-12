<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\Task\TaskRecord;

final class TaskListSorter
{
    public function __construct(private readonly CustomFieldValueCodec $codec)
    {
    }

    /**
     * @param list<CustomFieldRecord> $customFields
     */
    public function supports(string $field, array $customFields): bool
    {
        if (in_array($field, ['title', 'status_name', 'type_name', 'priority', 'customer', 'created_at', 'deadline'], true)) {
            return true;
        }

        $customId = $this->customFieldId($field);
        if ($customId === null) {
            return false;
        }
        foreach ($customFields as $customField) {
            if ($customField->id === $customId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param array<int, string> $statusNames
     * @param array<int, string> $typeNames
     * @param array<int, string> $customerNames
     * @param list<CustomFieldRecord> $customFields
     * @param array<int, array<int, string>> $customValues
     * @return list<TaskRecord>
     */
    public function sort(
        array $tasks,
        string $field,
        string $order,
        array $statusNames,
        array $typeNames,
        array $customerNames,
        array $customFields,
        array $customValues,
    ): array {
        if (!$this->supports($field, $customFields)) {
            return $tasks;
        }

        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';
        $customById = [];
        foreach ($customFields as $customField) {
            $customById[$customField->id] = $customField;
        }

        usort($tasks, function (TaskRecord $left, TaskRecord $right) use (
            $field,
            $order,
            $statusNames,
            $typeNames,
            $customerNames,
            $customById,
            $customValues,
        ): int {
            $a = $this->value($left, $field, $statusNames, $typeNames, $customerNames, $customById, $customValues);
            $b = $this->value($right, $field, $statusNames, $typeNames, $customerNames, $customById, $customValues);

            if ($a['missing'] !== $b['missing']) {
                return $a['missing'] ? 1 : -1;
            }
            if ($a['missing']) {
                return 0;
            }

            if (is_int($a['value']) && is_int($b['value'])) {
                $result = $a['value'] <=> $b['value'];
            } else {
                $result = strnatcmp(
                    mb_strtolower((string) $a['value'], 'UTF-8'),
                    mb_strtolower((string) $b['value'], 'UTF-8'),
                );
            }

            return $order === 'desc' ? -$result : $result;
        });

        return $tasks;
    }

    /**
     * @param array<int, string> $statusNames
     * @param array<int, string> $typeNames
     * @param array<int, string> $customerNames
     * @param array<int, CustomFieldRecord> $customById
     * @param array<int, array<int, string>> $customValues
     * @return array{missing: bool, value: int|string}
     */
    private function value(
        TaskRecord $task,
        string $field,
        array $statusNames,
        array $typeNames,
        array $customerNames,
        array $customById,
        array $customValues,
    ): array {
        $standard = match ($field) {
            'title' => $task->title,
            'status_name' => $task->statusId === null ? null : ($statusNames[$task->statusId] ?? null),
            'type_name' => $task->typeId === null ? null : ($typeNames[$task->typeId] ?? null),
            'priority' => $task->priority,
            'customer' => $task->customerId === null ? null : ($customerNames[$task->customerId] ?? null),
            'created_at' => $task->createdAt,
            'deadline' => $task->deadline,
            default => null,
        };
        if ($standard !== null || !str_starts_with($field, 'custom_')) {
            return $this->sortable($standard);
        }

        $customId = $this->customFieldId($field);
        $customField = $customId === null ? null : ($customById[$customId] ?? null);
        if (!$customField instanceof CustomFieldRecord) {
            return ['missing' => true, 'value' => ''];
        }
        $stored = $customValues[$task->id][$customId] ?? null;
        if ($stored === null || $stored === '') {
            return ['missing' => true, 'value' => ''];
        }

        return match ($customField->type) {
            'money' => ['missing' => false, 'value' => $this->moneyCents($stored)],
            'checkbox' => ['missing' => false, 'value' => $stored === '1' ? 1 : 0],
            'checkbox_list' => $this->sortable($this->codec->display($customField, $stored)),
            default => $this->sortable($stored),
        };
    }

    /** @return array{missing: bool, value: int|string} */
    private function sortable(int|string|null $value): array
    {
        if ($value === null || $value === '') {
            return ['missing' => true, 'value' => ''];
        }
        return ['missing' => false, 'value' => $value];
    }

    private function customFieldId(string $field): ?int
    {
        if (preg_match('/^custom_([1-9][0-9]*)$/D', $field, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    private function moneyCents(string $stored): int
    {
        [$whole, $fraction] = array_pad(explode('.', $stored, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
