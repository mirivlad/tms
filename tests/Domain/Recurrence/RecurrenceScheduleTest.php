<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Recurrence\RecurrenceSchedule;

final class RecurrenceScheduleTest extends TestCase
{
    private RecurrenceSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new RecurrenceSchedule();
    }

    public function testCalendarModesPreserveWallClockTime(): void
    {
        $zone = new DateTimeZone('Europe/Istanbul');
        $seed = new DateTimeImmutable('2026-09-22 09:30:00', $zone);

        self::assertSame(
            '2026-09-23 09:30:00',
            $this->schedule->nextCalendarDeadline('daily', 1, $seed, 22)->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-09-29 09:30:00',
            $this->schedule->nextCalendarDeadline('weekly', 1, $seed, 22)->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-09-27 09:30:00',
            $this->schedule->nextCalendarDeadline('interval', 5, $seed, 22)->format('Y-m-d H:i:s'),
        );
    }

    public function testMonthlyAnchorReturnsToOriginalDayAfterShortMonth(): void
    {
        $zone = new DateTimeZone('UTC');
        $january = new DateTimeImmutable('2027-01-31 18:15:00', $zone);

        $february = $this->schedule->nextCalendarDeadline('monthly', 1, $january, 31);
        $march = $this->schedule->nextCalendarDeadline('monthly', 1, $february, 31);

        self::assertSame('2027-02-28 18:15:00', $february->format('Y-m-d H:i:s'));
        self::assertSame('2027-03-31 18:15:00', $march->format('Y-m-d H:i:s'));
    }

    public function testAfterCompletionUsesConfiguredInterval(): void
    {
        $completed = new DateTimeImmutable('2026-09-22 14:00:00', new DateTimeZone('Asia/Irkutsk'));
        self::assertSame(
            '2026-09-25 14:00:00',
            $this->schedule->afterCompletionDeadline($completed, 3)->format('Y-m-d H:i:s'),
        );
    }

    public function testInvalidIntervalIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->schedule->normalizeInterval('interval', 0);
    }
}
