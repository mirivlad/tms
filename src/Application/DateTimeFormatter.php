<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;

final readonly class DateTimeFormatter
{
    private DateTimeZone $databaseTimezone;
    private DateTimeZone $utc;

    public function __construct(string $databaseTimezone)
    {
        $this->databaseTimezone = new DateTimeZone($databaseTimezone);
        $this->utc = new DateTimeZone('UTC');
    }

    public function database(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->format($value, $this->databaseTimezone, $format);
    }

    public function utc(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->format($value, $this->utc, $format);
    }

    private function format(?string $value, DateTimeZone $sourceTimezone, string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $dateTime = new DateTimeImmutable($value, $sourceTimezone);
        $displayTimezone = new DateTimeZone(date_default_timezone_get());
        return $dateTime->setTimezone($displayTimezone)->format($format);
    }
}
