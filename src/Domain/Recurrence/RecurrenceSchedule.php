<?php

declare(strict_types=1);

namespace Tms\Domain\Recurrence;

use DateTimeImmutable;
use DomainException;

final class RecurrenceSchedule
{
    public const MODES = ['daily', 'weekly', 'monthly', 'interval', 'after_completion'];

    public function normalizeInterval(string $mode, int $interval): int
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new DomainException('Unsupported recurrence mode.');
        }
        if (in_array($mode, ['daily', 'weekly', 'monthly'], true)) {
            return 1;
        }
        if ($interval < 1 || $interval > 3650) {
            throw new DomainException('Recurrence interval must be between 1 and 3650 days.');
        }
        return $interval;
    }

    public function nextCalendarDeadline(
        string $mode,
        int $interval,
        DateTimeImmutable $from,
        int $anchorDay,
    ): DateTimeImmutable {
        $interval = $this->normalizeInterval($mode, $interval);
        return match ($mode) {
            'daily' => $from->modify('+1 day'),
            'weekly' => $from->modify('+1 week'),
            'interval' => $from->modify('+' . $interval . ' days'),
            'monthly' => $this->nextMonthly($from, $anchorDay),
            default => throw new DomainException('Completion-based recurrence has no calendar advance.'),
        };
    }

    public function afterCompletionDeadline(DateTimeImmutable $completedAt, int $interval): DateTimeImmutable
    {
        $interval = $this->normalizeInterval('after_completion', $interval);
        return $completedAt->modify('+' . $interval . ' days');
    }

    private function nextMonthly(DateTimeImmutable $from, int $anchorDay): DateTimeImmutable
    {
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new DomainException('Monthly recurrence anchor must be between 1 and 31.');
        }

        $year = (int) $from->format('Y');
        $month = (int) $from->format('n') + 1;
        if ($month === 13) {
            $month = 1;
            $year++;
        }

        $first = $from->setDate($year, $month, 1);
        $day = min($anchorDay, (int) $first->format('t'));
        return $first->setDate($year, $month, $day);
    }
}
