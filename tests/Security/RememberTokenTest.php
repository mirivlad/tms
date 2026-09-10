<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use PHPUnit\Framework\TestCase;
use Tms\Security\RememberToken;

final class RememberTokenTest extends TestCase
{
    public function testIssuedTokenCanBeParsedFromCookie(): void
    {
        $issued = RememberToken::issue();
        $parsed = RememberToken::fromCookie($issued->cookieValue());

        self::assertNotNull($parsed);
        self::assertSame($issued->selector, $parsed->selector);
        self::assertTrue($parsed->matchesHash($issued->verifierHash()));
    }

    public function testDatabaseHashDoesNotExposeCookieSecret(): void
    {
        $token = RememberToken::issue();

        self::assertStringNotContainsString($token->verifierHash(), $token->cookieValue());
        self::assertSame(64, strlen($token->verifierHash()));
    }

    public function testInvalidCookieIsRejected(): void
    {
        self::assertNull(RememberToken::fromCookie('not-a-token'));
        self::assertNull(RememberToken::fromCookie(str_repeat('a', 24) . '.' . str_repeat('g', 64)));
    }

    public function testDifferentTokenDoesNotMatchStoredHash(): void
    {
        $first = RememberToken::issue();
        $second = RememberToken::issue();

        self::assertFalse($second->matchesHash($first->verifierHash()));
    }
}
