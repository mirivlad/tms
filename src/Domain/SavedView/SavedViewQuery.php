<?php

declare(strict_types=1);

namespace Tms\Domain\SavedView;

use DateTimeImmutable;

final class SavedViewQuery
{
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const SORTS = ['title', 'status_name', 'type_name', 'priority', 'customer', 'created_at', 'scheduled_at', 'deadline'];
    private const PER_PAGE = [10, 25, 50, 100];

    /**
     * Keep only task-list query parameters that TMS itself understands.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function normalize(array $query): array
    {
        $result = [];

        foreach (['status_id', 'type_id'] as $key) {
            $value = $this->positiveInt($query[$key] ?? null);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        if (isset($result['status_id']) && ($query['status_invert'] ?? null) === '1') {
            $result['status_invert'] = '1';
        }

        $project = $this->scalar($query['project'] ?? null, 32);
        if ($project === 'all' || ($project !== '' && ctype_digit($project) && (int) $project > 0)) {
            $result['project'] = $project;
        }

        $priority = $this->scalar($query['priority'] ?? null, 16);
        if (in_array($priority, self::PRIORITIES, true)) {
            $result['priority'] = $priority;
        }

        foreach (['q', 'customer'] as $key) {
            $value = $this->scalar($query[$key] ?? null, 255);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        foreach (['deadline_from', 'deadline_to', 'scheduled_from', 'scheduled_to', 'created_from', 'created_to'] as $key) {
            $value = $this->date($query[$key] ?? null);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        if (($query['overdue'] ?? null) === '1') {
            $result['overdue'] = '1';
        }

        $sort = $this->scalar($query['sort'] ?? null, 64);
        if (in_array($sort, self::SORTS, true) || preg_match('/^custom_[1-9][0-9]*$/D', $sort) === 1) {
            $result['sort'] = $sort;
        }

        $order = strtolower($this->scalar($query['order'] ?? null, 8));
        if (in_array($order, ['asc', 'desc'], true)) {
            $result['order'] = $order;
        }

        $perPage = $this->positiveInt($query['per_page'] ?? null);
        if ($perPage !== null && in_array($perPage, self::PER_PAGE, true)) {
            $result['per_page'] = $perPage;
        }

        $custom = $this->custom($query['custom'] ?? null);
        if ($custom !== []) {
            $result['custom'] = $custom;
        }

        return $result;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function scalar(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        return mb_substr($value, 0, $maxLength);
    }

    private function date(mixed $value): string
    {
        $value = $this->scalar($value, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** @return array<int, mixed> */
    private function custom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $fieldId => $filter) {
            $id = is_int($fieldId) ? $fieldId : (ctype_digit((string) $fieldId) ? (int) $fieldId : 0);
            if ($id < 1) {
                continue;
            }

            if (is_scalar($filter)) {
                $normalized = $this->scalar($filter, 512);
                if ($normalized !== '') {
                    $result[$id] = $normalized;
                }
                continue;
            }

            if (!is_array($filter)) {
                continue;
            }

            $nested = [];
            foreach (['min', 'max'] as $key) {
                $normalized = $this->scalar($filter[$key] ?? null, 64);
                if ($normalized !== '') {
                    $nested[$key] = $normalized;
                }
            }

            if (is_array($filter['values'] ?? null)) {
                $values = [];
                foreach (array_slice($filter['values'], 0, 50) as $option) {
                    $normalized = $this->scalar($option, 255);
                    if ($normalized !== '') {
                        $values[] = $normalized;
                    }
                }
                if ($values !== []) {
                    $nested['values'] = array_values(array_unique($values));
                    $nested['match'] = ($filter['match'] ?? null) === 'all' ? 'all' : 'any';
                }
            }

            if ($nested !== []) {
                $result[$id] = $nested;
            }
        }
        return $result;
    }
}
