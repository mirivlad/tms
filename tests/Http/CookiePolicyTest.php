<?php

declare(strict_types=1);

namespace Tms\Tests\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tms\Http\CookiePolicy;

final class CookiePolicyTest extends TestCase
{
    public function testPersistentCookieIsHttpOnlyAndSecure(): void
    {
        $policy = new CookiePolicy(true, 'Lax');
        $expires = new DateTimeImmutable('2030-01-01 00:00:00 UTC');

        self::assertSame([
            'expires' => $expires->getTimestamp(),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $policy->persistent($expires));
    }

    public function testUnsupportedSameSiteValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CookiePolicy(true, 'None');
    }
}
