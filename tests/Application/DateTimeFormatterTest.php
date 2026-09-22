<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PHPUnit\Framework\TestCase;
use Tms\Application\DateTimeFormatter;

final class DateTimeFormatterTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    public function testDatabaseTimestampUsesDeploymentTimezoneAsItsSource(): void
    {
        date_default_timezone_set('UTC');
        $formatter = new DateTimeFormatter('Asia/Irkutsk');

        self::assertSame('2026-09-22 12:51', $formatter->database('2026-09-22 20:51:00'));
    }

    public function testUtcTimestampUsesUtcAsItsSource(): void
    {
        date_default_timezone_set('Asia/Irkutsk');
        $formatter = new DateTimeFormatter('Asia/Irkutsk');

        self::assertSame('2026-09-23 04:51', $formatter->utc('2026-09-22 20:51:00'));
    }

    public function testEmptyTimestampStaysEmpty(): void
    {
        $formatter = new DateTimeFormatter('UTC');

        self::assertSame('', $formatter->database(null));
        self::assertSame('', $formatter->utc(''));
    }
}
