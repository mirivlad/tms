<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tms\Application\DashboardTipProvider;

final class DashboardTipProviderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tms-tip-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory . '/en.txt', "# comment\nFirst tip\nSecond tip\n");
        file_put_contents($this->directory . '/ru.txt', "Первый совет\nВторой совет\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testTipIsStableForUserAndDay(): void
    {
        $provider = new DashboardTipProvider($this->directory);
        $day = new DateTimeImmutable('2026-09-13 12:00:00');

        $first = $provider->forDay('en', 42, $day);
        $second = $provider->forDay('en', 42, $day);

        self::assertSame($first, $second);
        self::assertContains($first, ['First tip', 'Second tip']);
    }

    public function testInvalidLocaleFallsBackToEnglishAndMissingFileReturnsNull(): void
    {
        $provider = new DashboardTipProvider($this->directory);
        $day = new DateTimeImmutable('2026-09-13');

        self::assertContains($provider->forDay('../../etc/passwd', 7, $day), ['First tip', 'Second tip']);
        unlink($this->directory . '/en.txt');
        self::assertNull($provider->forDay('en', 7, $day));
    }
}
