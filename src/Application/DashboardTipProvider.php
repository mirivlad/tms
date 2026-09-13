<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;

final class DashboardTipProvider
{
    public function __construct(private readonly string $directory)
    {
    }

    public function forDay(string $locale, int $userId, DateTimeImmutable $day): ?string
    {
        $locale = preg_match('/^[a-z]{2}$/D', $locale) === 1 ? $locale : 'en';
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $locale . '.txt';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }
        $tips = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        if ($tips === []) {
            return null;
        }
        $hash = hash('sha256', $day->format('Y-m-d') . ':' . $userId);
        $index = hexdec(substr($hash, 0, 8)) % count($tips);
        return $tips[$index];
    }
}
